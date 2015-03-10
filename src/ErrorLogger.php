<?php

namespace ADT;

class ErrorLogger extends \Tracy\Logger {

	protected $securityUser;

	/**
	 * Statická instalace v bootrstrap.php
	 * @param \SystemContainer|DI\Container $container
	 */
	public static function install($container) {
		if (! \Tracy\Debugger::$productionMode) {
			return;
		}

		\Tracy\Debugger::setLogger(new static(\Tracy\Debugger::$logDirectory, \Tracy\Debugger::$email, \Tracy\Debugger::getBlueScreen()));
		\Tracy\Debugger::getLogger()->injectSecurityUser($container->getByType('\Nette\Security\User'));
		\Tracy\Debugger::$maxLen = FALSE;
	}

	public function __construct($directory, $email = NULL, \Tracy\BlueScreen $blueScreen = NULL)
	{
		parent::__construct($directory, $email, $blueScreen);
	}

	public function injectSecurityUser(\Nette\Security\User $securityUser) {
		$this->securityUser = $securityUser;
	}

	/**
	 * Logs message or exception to file and sends email notification.
	 * @param  string|\Exception
	 * @param  int   one of constant ILogger::INFO, WARNING, ERROR (sends email), EXCEPTION (sends email), CRITICAL (sends email)
	 * @return string logged error filename
	 */
	public function log($message, $priority = self::INFO)
	{
		if (!$this->directory) {
			throw new \LogicException('Directory is not specified.');
		} elseif (!is_dir($this->directory)) {
			throw new \RuntimeException("Directory '$this->directory' is not found or is not directory.");
		}

		$exceptionFile = $message instanceof \Exception ? $this->logException($message) : NULL;
		$line = $this->formatLogLine($message, $exceptionFile);
		$file = $this->directory . '/' . strtolower($priority ?: self::INFO) . '.log';

		if (!@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX)) {
			throw new \RuntimeException("Unable to write to log file '$file'. Is directory writable?");
		}

		if (in_array($priority, array(self::ERROR, self::EXCEPTION, self::CRITICAL), TRUE)) {

			if ($this->email && $this->mailer) {
				if (is_array($message)) {
					$stringMessage = implode(' ', $message);
				} else {
					$stringMessage = $message;
				}

				$messageHash = md5($message[1]);

				$dbt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
				$dbtSting = "";
				if(count($dbt) > 3){ //pokud jsou 3 tak jde pouze o exception a je ulozena nette chybova stranka
					for($i=0; $i < count($dbt); $i++){
						@$dbtSting .= "#".($i)." ".$dbt[$i]['file'] . '(' . $dbt[$i]['line'] . '): ' . (isset($dbt[$i]['class']) ? $dbt[$i]['class'] . '::' : '') . $dbt[$i]['function'] . '()' . "\n";
					}

					$stringMessage .= "\n\n". $dbtSting;
				}

				// pridame doplnujici info, referer,browser,...
				$stringMessage .= "\n\n".
					(isset($_SERVER['HTTP_HOST']) ? 'LINK:' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . "\n" : '') .
					'SERVER:' . var_export($_SERVER, TRUE) . "\n\n".
					'GET:' . var_export($_GET, TRUE) . "\n\n".
					'POST:' . var_export($_POST, TRUE) . "\n\n".
					'securityUser:' . var_export($this->securityUser->identity, TRUE) . "\n\n";

				// zjistime zda dana chyba uz neni odeslana
				$errors = explode(PHP_EOL, @file_get_contents($this->directory . '/email-sent'));
				if (count($errors) == 0 || !in_array($messageHash, $errors)) { // je li prazdny nebo neni v poly
					// pridame a odeslem
					@file_put_contents($this->directory . '/email-sent', $messageHash . PHP_EOL, FILE_APPEND);

					call_user_func($this->mailer, $stringMessage, implode(', ', (array) $this->email));
				}
			}
		}

		return $exceptionFile;
	}
}
