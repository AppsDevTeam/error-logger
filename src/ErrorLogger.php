<?php

namespace ADT;

use DateTime;
use RuntimeException;
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

	private int $sentEmailsPerRequest = 0;

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
		return parent::log($message, $level);
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
		$exceptionMdFile = $exceptionFile && substr($exceptionFile, -5) === '.html'
			? substr($exceptionFile, 0, -5) . '.md'
			: null;
		if ($exceptionMdFile !== null && !is_file($exceptionMdFile)) {
			$exceptionMdFile = null;
		}
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
			$this->formatMessage($message) . "\n\nsource: " . Helpers::getSource(),
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
