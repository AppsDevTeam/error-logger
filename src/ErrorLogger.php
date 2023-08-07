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

		// MAX EMAILS PER DY

		$maxEmailsPerDayLog = $this->directory . '/max-emails-per-day.log';

		// delete file from yesterday
		if (date('Y-m-d', @filemtime($maxEmailsPerDayLog)) < (new DateTime('midnight'))->format('Y-m-d')) {
			@unlink($maxEmailsPerDayLog);
		}

		$logContent = @file_get_contents($errorSnoozeLog);

		if (substr_count($logContent, date('Y-m-d')) >= $this->maxEmailsPerDay) {
			// Limit per day exceeded
			return;
		}

		if (!@file_put_contents($errorSnoozeLog, $line . ' ' . $messageHash . PHP_EOL, FILE_APPEND | LOCK_EX)) {
			throw new RuntimeException("Unable to write to log file '" . $errorSnoozeLog . "'. Is directory writable?");
		}

		// SEND EMAIL

		call_user_func($this->mailer, $message, implode(', ', (array)$this->email), $exceptionFile);

		$this->sentEmailsPerRequest++;
	}

	/**
	 * @internal
	 */
	public function defaultMailer($message, string $email, ?string $exceptionFile = null): void
	{
		$host = preg_replace('#[^\w.-]+#', '', $_SERVER['HTTP_HOST'] ?? php_uname('n'));

		$separator = md5(time());
		$eol = "\n";

		$body =
			"--" . $separator . $eol .

			// Text email
			"Content-Type: text/plain; charset=\"UTF-8\"" . $eol .
			"Content-Transfer-Encoding: 8bit" . $eol . $eol .
			$this->formatMessage($message) . "\n\nsource: " . Helpers::getSource() . $eol .
			"--" . $separator . $eol;

		if ($exceptionFile && $this->includeExceptionFile) {
			$body .=
				// Attachment
				"Content-Type: application/octet-stream; name=\"" . basename($exceptionFile) . "\"" . $eol .
				"Content-Transfer-Encoding: base64" . $eol .
				"Content-Disposition: attachment" . $eol . $eol .
				chunk_split(base64_encode(file_get_contents($exceptionFile))) . $eol .
				"--" . $separator . "--";
		}

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
