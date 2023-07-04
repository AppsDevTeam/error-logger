ErrorLogger
===========

Sends more info about the error than [Tracy\Logger](https://github.com/nette/tracy). Moreover, it can send multiple errors not only the first.

Installation
------------

````bash
composer require adt/error-logger
````

Place this to your bootstrap.php after calling `$configurator->enableDebugger()`:

````php
$logger = \ADT\ErrorLogger::install($email = 'errors@example.com', $maxEmailsPerDay = 10, $maxEmailsPerRequest = 10);
if (!\Tracy\Debugger::$productionMode) {
	// Do not send emails
	$logger->mailer = null;
}
````
