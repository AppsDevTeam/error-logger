<?php

namespace ADT;

use DateTime;
use RuntimeException;
use Tracy\Debugger;
use Tracy\Helpers;
use Tracy\Logger;

class ErrorLogger extends Logger
{
	/**
	 * Maximum number of emails per day
	 * @var int
	 */
	protected int $maxEmailsPerDay;

	/**
	 * Maximum number of emails per request
	 * @var int
	 */
	protected int $maxEmailsPerRequest;

	/**
	 * Number of emails in current request
	 * @var int
	 */
	protected int $sentEmailsPerRequest = 0;

	/**
	 * Include exception file as an attachment?
	 */
	protected bool $includeErrorMessage = true;

	public static function install($email, $maxEmailsPerDay = NULL, $maxEmailsPerRequest = NULL, $includeErrorMessage = true): ?self
	{
		Debugger::$email = $email;

		$logger = new static(Debugger::$logDirectory, Debugger::$email, Debugger::getBlueScreen());

		$logger->maxEmailsPerDay = $maxEmailsPerDay ?: 10;
		$logger->maxEmailsPerRequest = $maxEmailsPerRequest ?: 10;
		$logger->includeErrorMessage = $includeErrorMessage;

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

		$exceptionFile = $message instanceof \Throwable
			? $this->getExceptionFile($message)
			: null;
		$line = static::formatLogLine($message, $exceptionFile);
		$logFile = $this->directory . '/email-sent';

		// Delete email-sent file from yesterday
		if (date('Y-m-d', @filemtime($logFile)) < (new DateTime('midnight'))->format('Y-m-d')) {
			@unlink($logFile);
		}

		$messageHash = preg_replace('~(Resource id #)\d+~', '$1', $message);
		$messageHash = preg_replace('~(PID: )\d+~', '$1', $messageHash);
		$messageHash = md5($messageHash);

		if ($this->sentEmailsPerRequest >= $this->maxEmailsPerRequest) {
			// Limit per request exceeded
			return;
		}

		if (
			($logContent = @file_get_contents($logFile))
			&&
			(strstr($logContent, $messageHash) !== false)
		) {
			// Duplicate error
			return;
		}

		if (substr_count($logContent, date('Y-m-d')) >= $this->maxEmailsPerDay) {
			// Limit per day exceeded
			return;
		}

		if (!@file_put_contents($logFile, $line . ' ' . $messageHash . PHP_EOL, FILE_APPEND | LOCK_EX)) {
			throw new RuntimeException("Unable to write to log file '" . $logFile . "'. Is directory writable?");
		}


		call_user_func($this->mailer, $message, implode(', ', (array)$this->email), $exceptionFile);

		$this->sentEmailsPerRequest++;
	}

	/**
	 * @internal
	 */
	public function defaultMailer($message, string $email, ?string $attachment = null): void
	{
		$host = preg_replace('#[^\w.-]+#', '', $_SERVER['HTTP_HOST'] ?? php_uname('n'));

		$separator = md5(time());
		$eol = "\n";

		$body = '';
		if ($this->includeErrorMessage) {
			$body =
				"--" . $separator . $eol .

				// Text email
				"Content-Type: text/plain; charset=\"UTF-8\"" . $eol .
				"Content-Transfer-Encoding: 8bit" . $eol . $eol .
				$this->formatMessage($message) . "\n\nsource: " . Helpers::getSource() . $eol .
				"--" . $separator . $eol;

			if ($attachment) {
				$body .=
					// Attachment
					"Content-Type: application/octet-stream; name=\"" . basename($attachment) . "\"" . $eol .
					"Content-Transfer-Encoding: base64" . $eol .
					"Content-Disposition: attachment" . $eol . $eol .
					chunk_split(base64_encode(file_get_contents($attachment))) . $eol .
					"--" . $separator . "--";
			}
		}

		$parts = str_replace(
			["\r\n", "\n"],
			["\n", PHP_EOL],
			[
				'headers' => implode("\n", [
						'X-Mailer: Tracy',
						'MIME-Version: 1.0',
						'Content-Type: multipart/mixed; boundary="' . $separator . '"',
						'Content-Transfer-Encoding: 7bit',
					]) . "\n",
				'subject' => "PHP: An error occurred on the server $host",
				'body' => $body
			]
		);

		mail($email, $parts['subject'], $parts['body'], $parts['headers']);
	}

}
