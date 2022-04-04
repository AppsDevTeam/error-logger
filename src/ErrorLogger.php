<?php

namespace ADT;

use DateTime;
use Exception;
use Nette\DI\Container;
use RuntimeException;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\Helpers;
use Tracy\Logger;

class ErrorLogger extends Logger
{
	/**
	 * Maximální počet odeslaných emailů denně
	 * @var int
	 */
	protected int $maxEmailsPerDay;

	/**
	 * Maximální počet odeslaných emailů v rámci jednoho requestu
	 * @var int
	 */
	protected int $maxEmailsPerRequest;

	/**
	 * Počet odeslaných emailů v rámci aktuálního requestu
	 * @var int
	 */
	protected int $sentEmailsPerRequest = 0;

	/**
	 * Pole s citlivými údaji, jejižch hodnoty se nemají zobrazovat ve výpisu.
	 * @var array
	 */
	protected array $sensitiveFields = [];

	/**
	 * Ma se vlozit error message do emailu
	 */
	protected bool $includeErrorMessage = true;

	protected ?Container $container = null;

	public static function install($email, $maxEmailsPerDay = NULL, $maxEmailsPerRequest = NULL, $sensitiveFields = [], $includeErrorMessage = true): ?Logger
	{
		if (!Debugger::$productionMode) {
			return null;
		}

		Debugger::$email = $email;

		$logger = new static(Debugger::$logDirectory, Debugger::$email, Debugger::getBlueScreen());

		$logger->maxEmailsPerDay = $maxEmailsPerDay ?: 10;
		$logger->maxEmailsPerRequest = $maxEmailsPerRequest ?: 10;
		$logger->sensitiveFields = $sensitiveFields;
		$logger->includeErrorMessage = $includeErrorMessage;

		Debugger::setLogger($logger);

		return $logger;
	}

	public function setup(Container $container)
	{
		$this->container = $container;
	}

	protected function sendEmail($message): void
	{
		if (
			$this->email
			&&
			$this->mailer
		) {
			$exceptionFile = $message instanceof \Throwable
				? $this->getExceptionFile($message)
				: null;
			$line = static::formatLogLine($message, $exceptionFile);
			$logFile = $this->directory . '/email-sent';

			// we delete email-sent file from yesterday
			if (date('Y-m-d', @filemtime($logFile)) < (new DateTime('midnight'))->format('Y-m-d')) {
				@unlink($logFile);
			}

			$messageHash = preg_replace('~(Resource id #)\d+~', '$1', $message);
			$messageHash = preg_replace('~(PID: )\d+~', '$1', $messageHash);
			$messageHash = md5($messageHash);

			if (
				// ještě se vejdeme do limitu v rámci aktuálního requestu
				$this->sentEmailsPerRequest < $this->maxEmailsPerRequest
				&&
				// tento hash jsme ještě neposlali
				(
					!($logContent = @file_get_contents($logFile))
					||
					(strstr($logContent, $messageHash) === false)
				)
				&&
				// ještě se vejdeme do limitu v rámci aktuálního dne
				substr_count($logContent, date('Y-m-d')) < $this->maxEmailsPerDay
			) {
				if (!@file_put_contents($logFile, $line . ' ' . $messageHash . PHP_EOL, FILE_APPEND | LOCK_EX)) {
					throw new RuntimeException("Unable to write to log file '" . $logFile . "'. Is directory writable?");
				}

				// sestavíme zprávu
				if (is_array($message)) {
					$stringMessage = implode(' ', $message);
				} else {
					$stringMessage = $message;
				}

				$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
				if (count($backtrace) > 3) { //pokud jsou 3 tak jde pouze o exception a je ulozena nette chybova stranka
					$backtraceString = "";

					for ($i = 0; $i < count($backtrace); $i++) {
						$backtraceData = $backtrace[$i] + [
								'file' => '_unknown_',
								'line' => '_unknown_',
								'function' => '_unknown_',
							];

						$backtraceString = "#$i {$backtraceData['file']}({$backtraceData['line']}): "
							. (isset($backtraceData['class']) ? $backtraceData['class'] . '::' : '')
							. "{$backtraceData['function']}()\n";
					}

					$stringMessage .= "\n\n" . $backtraceString;
				}


				// přidáme doplnující info - referer, browser...
				$stringMessage .= "\n\n" .
					(isset($_SERVER['HTTP_HOST']) ? 'LINK:' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . "\n" : '') .
					'SERVER:' . Dumper::toText($_SERVER) . "\n\n" .
					'GET:' . Dumper::toText($_GET, [Dumper::DEPTH => 10]) . "\n\n" .
					'POST:' . Dumper::toText($this->hideSensitiveFieldValue($_POST), [Dumper::DEPTH => 10]);

				if ($this->container && ($securityUser = $this->container->getByType('\Nette\Security\User', FALSE))) {
					// obalujeme do try protoze SecurityUser je zavisly na databazi a pokud je chyba v db, tak nam error nedojde
					try {
						$stringMessage .= "\n\n" .
							'securityUser:' . Dumper::toText($securityUser->identity, [Dumper::DEPTH => 1]);
					} catch (Exception $e) {}
				}

				if ($this->container && ($git = $this->container->getByType('\ADT\TracyGit\Git', FALSE)) !== NULL && ($gitInfo = $git->getInfo())) {
					$stringMessage .= "\n\n";

					foreach ($gitInfo as $key => $value) {
						$stringMessage .= $key . ": " . $value . "\n";
					}
				}

				// odešleme chybu emailem
				call_user_func($this->mailer, $stringMessage, implode(', ', (array)$this->email), $exceptionFile);

				$this->sentEmailsPerRequest++;
			}
		}
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

	protected function hideSensitiveFieldValue($array): array
	{
		$sensitiveFields = $this->sensitiveFields;
		$replacement = '*****';

		array_walk_recursive($array, function (&$value, $key) use ($sensitiveFields, $replacement) {
			if (in_array($key, $sensitiveFields, TRUE)) {
				$value = $replacement;
			}
		});

		return $array;
	}
}
