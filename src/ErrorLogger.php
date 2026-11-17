<?php

namespace ADT;

use DateTime;
use RuntimeException;
use Tracy\BlueScreen;
use Tracy\Debugger;
use Tracy\Helpers;
use Tracy\Logger;

final class ErrorLogger extends Logger
{
	/**
	 * Maximum number of emails per day
	 * @var int
	 */
	public int $maxEmailsPerDay;

	/**
	 * Maximum number of emails per request
	 * @var int
	 */
	public int $maxEmailsPerRequest;

	/**
	 * Regular expression which removes matches before checking if email was already sent
	 * @var string
	 */
	public string $errorMessageSanitizeRegex;

	/**
	 * Include exception file as an attachment?
	 */
	public bool $includeExceptionFile;

	/**
	 * Number of emails in current request
	 * @var int
	 */
	/** Log over this size (bytes) is renamed to <name>.log-<date> before the next write */
	public int $maxLogSize = 10 * 1024 * 1024;

	/** Rotated files kept per log */
	public int $maxRotatedLogs = 10;

	/** Exception snapshots (exception--*.html/.md) older than this (seconds) are deleted */
	public int $maxExceptionFileAge = 90 * 86400;

	/**
	 * Names of request headers, query parameters, call arguments and dumped keys whose values are left out
	 * of the markdown exception report (on top of the scrubber and keysToHide of the BlueScreen)
	 */
	public string $sensitiveKeys = '~pass|pwd|secret|token|(^|[_-])(api)?key($|[_-])|apikey|salt|dsn'
		. '|(^|[_-])auth|authorization|cookie|sessid|session|bypass|app-debug~i';

	private int $sentEmailsPerRequest = 0;

	/** @var array<string, true> markdown reports written by this logger in this request */
	private array $scrubbedMarkdown = [];

	/** The one of Tracy\Logger is private */
	private ?BlueScreen $reportBlueScreen;

	public function __construct($directory, $email = null, ?BlueScreen $blueScreen = null)
	{
		parent::__construct($directory, $email, $blueScreen);
		$this->reportBlueScreen = $blueScreen;
	}

	public static function install(
		$email,
		$maxEmailsPerDay = 100,
		$maxEmailsPerRequest = 10,
		$includeExceptionFile = true,
		$errorMessageSanitizeRegex = '~\d|(/[^\s]*)|(\w+://)~', // removes all numbers, absolut paths and protocols
		$emailSnooze = '1 day'
	): ?self
	{
		Debugger::$email = $email;

		$logger = new self(Debugger::$logDirectory, Debugger::$email, Debugger::getBlueScreen());

		$logger->maxEmailsPerDay = $maxEmailsPerDay;
		$logger->maxEmailsPerRequest = $maxEmailsPerRequest;
		$logger->includeExceptionFile = $includeExceptionFile;
		$logger->errorMessageSanitizeRegex = $errorMessageSanitizeRegex;
		$logger->emailSnooze = $emailSnooze;

		Debugger::setLogger($logger);

		return $logger;
	}

	/**
	 * Tracy itself never rotates its logs: a broken release or a flood of requests would fill the disk.
	 * Rotates every *.log of the directory, also the ones PHP writes itself (error_log), right before
	 * a write - no cron, any environment, under the user of the application.
	 */
	public function log($message, $level = self::INFO)
	{
		$this->rotate();
		// Tracy takes the URL straight from $_SERVER - a secret in the query string (?token=) would end up in
		// the log line, the title and the dump of the HTML report and in the e-mail; the scrubber sees keys only
		$server = [];
		foreach (['REQUEST_URI', 'QUERY_STRING'] as $key) {
			if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
				$server[$key] = $_SERVER[$key];
				$_SERVER[$key] = $key === 'QUERY_STRING' ? $this->scrubQuery($_SERVER[$key]) : $this->scrubUrl($_SERVER[$key]);
			}
		}
		try {
			if ($message instanceof \Throwable && $this->directory && is_dir($this->directory)) {
				$this->writeMarkdown($message, $this->getExceptionFile($message, $level), false);
			}
			return parent::log($message, $level);
		} finally {
			$_SERVER = array_replace($_SERVER, $server);
		}
	}

	/**
	 * The markdown report of Tracy (exception--*.md) prints the request headers raw and dumps call arguments
	 * without passing their names to the dumper, so the name-keyed scrubber of the BlueScreen never sees them
	 * (session cookie, Authorization, $password ...), unlike in the HTML report. Tracy writes the report only
	 * when the file does not exist yet, so writing a scrubbed one first keeps the raw one off the disk too.
	 */
	private function writeMarkdown(\Throwable $exception, string $exceptionFile, bool $overwrite): ?string
	{
		if (
			substr($exceptionFile, -5) !== '.html'
			|| !method_exists(BlueScreen::class, 'renderAgent')
		) {
			return null;
		}
		$mdFile = substr($exceptionFile, 0, -5) . '.md';
		if (isset($this->scrubbedMarkdown[$mdFile])) {
			return $mdFile;
		}
		if (!$overwrite && (is_file($exceptionFile) || is_file($mdFile))) {
			return null;
		}

		try {
			$markdown = $this->renderScrubbedMarkdown($exception);
		} catch (\Throwable $e) {
			// a report that cannot be scrubbed must not be left to Tracy
			$markdown = 'The markdown report could not be scrubbed (' . get_class($e) . '), see the HTML one.' . "\n";
		}

		if ($overwrite) {
			$written = @file_put_contents($mdFile, $markdown, LOCK_EX) !== false;
		} elseif ($handle = @fopen($mdFile, 'x')) { // @ another request may have written it meanwhile
			$written = fwrite($handle, $markdown) !== false;
			fclose($handle);
		} else {
			$written = false;
		}
		if (!$written) {
			return null;
		}

		$this->scrubbedMarkdown[$mdFile] = true;
		return $mdFile;
	}

	private function renderScrubbedMarkdown(\Throwable $exception): string
	{
		$blueScreen = $this->reportBlueScreen ?? Debugger::getBlueScreen();
		$scrubber = $blueScreen->scrubber;
		$keysToHide = array_map('strtolower', $blueScreen->keysToHide ?? []);
		$isSensitive = function ($key, $value = null) use ($scrubber, $keysToHide): bool {
			$key = (string) $key;
			return (bool) preg_match($this->sensitiveKeys, $key)
				|| in_array(strtolower($key), $keysToHide, true)
				|| ($scrubber !== null && $scrubber($key, $value, null));
		};

		$traces = [];
		$blueScreen->scrubber = function ($key, $value, $class = null) use ($isSensitive): bool {
			return $isSensitive($key, $value);
		};
		try {
			foreach (Helpers::getExceptionChain($exception) as $ex) {
				$property = new \ReflectionProperty($ex instanceof \Exception ? \Exception::class : \Error::class, 'trace');
				if (PHP_VERSION_ID < 80100) {
					$property->setAccessible(true);
				}
				$trace = $property->getValue($ex);
				$traces[] = [$property, $ex, $trace];
				$property->setValue($ex, array_map(function (array $frame) use ($isSensitive): array {
					return $this->scrubFrame($frame, $isSensitive);
				}, $trace));
			}

			$location = method_exists(Helpers::class, 'findCallerLocation')
				? Helpers::findCallerLocation(array_merge(Debugger::$transparentPaths ?? [], [__DIR__]))
				: null;
			$markdown = (new \ReflectionMethod($blueScreen, 'renderAgent'))->getNumberOfParameters() > 1
				? $blueScreen->renderAgent($exception, $location)
				: $blueScreen->renderAgent($exception);
		} finally {
			$blueScreen->scrubber = $scrubber;
			foreach ($traces as [$property, $ex, $trace]) {
				$property->setValue($ex, $trace);
			}
		}

		// Tracy escapes a leading "- " in every dumped value, so a line starting with it is a request header
		$markdown = preg_replace_callback('~^- (.+?): .*$~m', function (array $m) use ($isSensitive): string {
			return $isSensitive(str_replace('\\', '', $m[1])) ? '- ' . $m[1] . ': \\*\\*\\*\\*\\*' : $m[0];
		}, $markdown);

		$source = Helpers::getSource();
		return str_replace(Helpers::escapeMd($source), Helpers::escapeMd($this->scrubUrl($source)), $markdown);
	}

	private function scrubFrame(array $frame, callable $isSensitive): array
	{
		if (empty($frame['args'])) {
			return $frame;
		}

		try {
			$params = isset($frame['class'])
				? (new \ReflectionMethod($frame['class'], $frame['function']))->getParameters()
				: (new \ReflectionFunction($frame['function']))->getParameters();
		} catch (\Throwable $e) {
			$params = [];
		}

		foreach ($frame['args'] as $k => $v) {
			// without a name (variadic, closure) nothing tells whether the value is a secret
			if (!isset($params[$k]) || $params[$k]->isVariadic() || $isSensitive($params[$k]->getName(), $v)) {
				$frame['args'][$k] = '*****';
			}
		}
		return $frame;
	}

	private function scrubUrl(string $url): string
	{
		$parts = explode('?', $url, 2);
		if (!isset($parts[1])) {
			return $url;
		}

		return $parts[0] . '?' . $this->scrubQuery($parts[1]);
	}

	private function scrubQuery(string $query): string
	{
		$pairs = [];
		foreach (explode('&', $query) as $pair) {
			$kv = explode('=', $pair, 2);
			$pairs[] = isset($kv[1]) && preg_match($this->sensitiveKeys, urldecode($kv[0]))
				? $kv[0] . '=*****'
				: $pair;
		}
		return implode('&', $pairs);
	}

	private function rotate(): void
	{
		foreach (glob($this->directory . '/*.log') ?: [] as $file) {
			// own bookkeeping, small and cleared by itself
			if (in_array(basename($file), ['error-snooze.log', 'max-emails-per-day.log'], true)) {
				continue;
			}
			// rename keeps nothing open: PHP and Tracy open the log on every write; a second worker
			// rotating at the same moment only fails to rename the already moved file
			if (@filesize($file) > $this->maxLogSize && @rename($file, $file . '-' . date('Y-m-d-His'))) {
				$rotated = glob($file . '-*') ?: [];
				sort($rotated);
				foreach (array_slice($rotated, 0, max(0, count($rotated) - $this->maxRotatedLogs)) as $old) {
					@unlink($old);
				}
			}
		}

		// Tracy writes one snapshot per distinct exception; a cheap check on a small share of writes is enough
		if (mt_rand(1, 100) === 1) {
			$limit = time() - $this->maxExceptionFileAge;
			foreach (glob($this->directory . '/exception--*') ?: [] as $file) {
				if (@filemtime($file) < $limit) {
					@unlink($file);
				}
			}
		}
	}

	protected function sendEmail($message): void
	{
		if (
			!$this->email
			||
			!$this->mailer
		) {
			return;
		}

		if ($this->sentEmailsPerRequest >= $this->maxEmailsPerRequest) {
			// Limit per request exceeded
			return;
		}

		$exceptionFile = $message instanceof \Throwable
			? $this->getExceptionFile($message)
			: null;
		$line = self::formatLogLine($message, $exceptionFile);

		$messageHash = md5($this->sanitizeString($message));

		// ERROR SNOOZE

		$errorSnoozeLog = $this->directory . '/error-snooze.log';

		$snooze = is_numeric($this->emailSnooze)
			? $this->emailSnooze
			: strtotime($this->emailSnooze) - time();

		// Delete email-sent file from yesterday
		if (($filemtime = @filemtime($errorSnoozeLog)) && ($filemtime + $snooze < time())) {
			@unlink($errorSnoozeLog);
		}

		if (
			($logContent = @file_get_contents($errorSnoozeLog))
			&&
			(strstr($logContent, $messageHash) !== false)
		) {
			// Duplicate error
			return;
		}

		self::writeToLogFile($errorSnoozeLog, $line . ' ' . $messageHash);

		// MAX EMAILS PER DAY

		$maxEmailsPerDayLog = $this->directory . '/max-emails-per-day.log';

		// delete file from yesterday
		if (($filemtime = @filemtime($maxEmailsPerDayLog)) && date('Y-m-d', $filemtime) < (new DateTime('midnight'))->format('Y-m-d')) {
			@unlink($maxEmailsPerDayLog);
		} elseif (($lines = @file($maxEmailsPerDayLog)) && count($lines) >= $this->maxEmailsPerDay) {
			// Limit per day exceeded
			return;
		}

		self::writeToLogFile($maxEmailsPerDayLog, $line . ' ' . $messageHash);

		// SEND EMAIL

		// a report left by an older version or by Tracy itself is scrubbed before it leaves the server
		$exceptionMdFile = $exceptionFile ? $this->writeMarkdown($message, $exceptionFile, true) : null;

		call_user_func($this->mailer, $message, implode(', ', (array)$this->email), $exceptionFile, $exceptionMdFile);

		$this->sentEmailsPerRequest++;
	}

	/**
	 * @internal
	 */
	public function defaultMailer($message, string $email, ?string $exceptionFile = null, ?string $exceptionMdFile = null): void
	{
		$host = preg_replace('#[^\w.-]+#', '', $_SERVER['HTTP_HOST'] ?? php_uname('n'));

		$separator = md5(time());
		$eol = "\n";

		$bodyParts = [
			// Text email
			"Content-Type: text/plain; charset=\"UTF-8\"" . $eol .
			"Content-Transfer-Encoding: 8bit" . $eol . $eol .
			$this->formatMessage($message) . "\n\nsource: " . $this->scrubUrl(Helpers::getSource()),
		];

		if ($exceptionFile && $this->includeExceptionFile) {
			$bodyParts[] =
				// Attachment
				"Content-Type: application/octet-stream; name=\"" . basename($exceptionFile) . "\"" . $eol .
				"Content-Transfer-Encoding: base64" . $eol .
				"Content-Disposition: attachment" . $eol . $eol .
				chunk_split(base64_encode(file_get_contents($exceptionFile)));
		}

		if ($exceptionMdFile && $this->includeExceptionFile) {
			$bodyParts[] =
				// Attachment
				"Content-Type: text/markdown; charset=\"UTF-8\"; name=\"" . basename($exceptionMdFile) . "\"" . $eol .
				"Content-Transfer-Encoding: base64" . $eol .
				"Content-Disposition: attachment" . $eol . $eol .
				chunk_split(base64_encode(file_get_contents($exceptionMdFile)));
		}

		$body = "--" . $separator . $eol
			. implode($eol . "--" . $separator . $eol, $bodyParts)
			. $eol . "--" . $separator . "--";

		$parts = str_replace(
			["\r\n", "\n"],
			["\n", PHP_EOL],
			[
				'headers' => implode("\n", array_filter([
						($this->fromEmail ? 'From: ' . $this->fromEmail : ''),
						'X-Mailer: Tracy',
						'MIME-Version: 1.0',
						'Content-Type: multipart/mixed; boundary="' . $separator . '"',
						'Content-Transfer-Encoding: 7bit',
					])) . "\n",
				'subject' => "PHP: An error occurred on the server $host",
				'body' => $body
			]
		);

		mail($email, $parts['subject'], $parts['body'], $parts['headers']);
	}

	private function sanitizeString($string): string
	{
		return preg_replace($this->errorMessageSanitizeRegex, '', $string);
	}

	private static function writeToLogFile($filename, $data)
	{
		if (!@file_put_contents($filename, $data . PHP_EOL, FILE_APPEND | LOCK_EX)) {
			throw new RuntimeException("Unable to write to log file '" . $filename . "'. Is directory writable?");
		}
	}
}
