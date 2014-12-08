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

		$debEmail = \Tracy\Debugger::$email;
		$debLogDir = \Tracy\Debugger::$logDirectory;
		\Tracy\Debugger::setLogger(new \ADT\ErrorLogger()); //nastavime vlastni logger
		\Tracy\Debugger::getLogger()->injectSecurityUser($container->getByType('\Nette\Security\User'));
		\Tracy\Debugger::getLogger()->configure($debEmail, $debLogDir);
		\Tracy\Debugger::$maxLen = FALSE;
	}

	public function __construct() {
		\Nette\Diagnostics\Debugger::$logDirectory = & $this->directory;
		\Nette\Diagnostics\Debugger::$email = & $this->email;
		\Nette\Diagnostics\Debugger::$mailer = & $this->mailer;
		$this->mailer = array(__CLASS__, 'defaultMailer');
	}

	public function injectSecurityUser(\Nette\Security\User $securityUser) {
		$this->securityUser = $securityUser;
	}

	public function configure($email, $directory) {
		$this->email = $email;
		$this->directory = $directory;
	}

	/**
	 * Logs message or exception to file and sends email notification.
	 * @param  string|array
	 * @param  int     one of constant INFO, WARNING, ERROR (sends email), CRITICAL (sends email)
	 * @return bool    was successful?
	 */
	public function log($message, $priority = self::INFO) {
		if (!is_dir($this->directory)) {
			throw new \Nette\DirectoryNotFoundException("Directory '$this->directory' is not found or is not directory.");
		}

		if (is_array($message)) {
			$stringMessage = implode(' ', $message);
		} else {
			$stringMessage = $message;
		}

		$res = error_log(trim($stringMessage) . PHP_EOL, 3, $this->directory . '/gcs_' . strtolower($priority) . '.log');
		if (($priority === self::ERROR || $priority === self::CRITICAL || $priority === self::WARNING ) && $this->email && $this->mailer) {
			$messageHash = md5($message[1]);

			$dbt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
			$dbtSting = "";
			if(count($dbt) >3){//pokud jsou 3 tak jde pouze o exception a je ulozena nette chybova stranka
				for($i=0;$i<count($dbt); $i++){
					@$dbtSting .= "#".($i)." ".$dbt[$i]['file'] . '(' . $dbt[$i]['line'] . '): ' . (isset($dbt[$i]['class']) ? $dbt[$i]['class'] . '::' : '') . $dbt[$i]['function'] . '()' . "\n";
				}

				$stringMessage .= "\n\n".$dbtSting;
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
				\Nette\Callback::create($this->mailer)->invoke($stringMessage, $this->email);
			}
		}
		return $res;
	}
}
