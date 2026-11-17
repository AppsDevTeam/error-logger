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
$logger = \ADT\ErrorLogger::install(
	$email = 'errors@example.com', 
	$maxEmailsPerDay = 100, 
	$maxEmailsPerRequest = 10, 
	$includeExceptionFile = true,
	$errorMessageSanitizeRegex = '~\d|(/[^\s]*)|(\w+://)~', // removes all numbers, absolut paths and protocols
	$emailSnooze = '1 day'
);
if (!\Tracy\Debugger::$productionMode) {
	// Do not send emails
	$logger->mailer = null;
}
````

Log rotation
------------

Tracy never rotates its logs. Before each write ErrorLogger renames every `*.log` of the log directory bigger than
`$maxLogSize` (10 MB) to `<name>.log-<YYYY-MM-DD-HHMMSS>`, keeps `$maxRotatedLogs` (10) of them per log and deletes
exception snapshots (`exception--*`) older than `$maxExceptionFileAge` (90 days). Logs written by PHP itself into the
same directory are rotated too. No cron needed, it runs under the user of the application.

````php
$logger->maxLogSize = 50 * 1024 * 1024;
````


Markdown exception report
-------------------------

Recent Tracy writes next to every `exception--*.html` a Markdown twin `exception--*.md`, which ErrorLogger attaches
to the e-mail. Tracy prints the request headers in it raw and dumps call arguments without passing their names, so the
scrubber of the BlueScreen (keyed by name) does not apply there. ErrorLogger therefore writes the `.md` itself before Tracy does
(and rewrites an older one before attaching it); with a Tracy without it nothing changes:

- request headers, query parameters, call arguments and dumped keys whose name matches `$sensitiveKeys`, the BlueScreen
  `scrubber` or `keysToHide` are replaced by `*****`,
- call arguments without a name (variadic, closures) are always replaced, frames, files and lines stay.

The HTML report is left to the BlueScreen scrubber. The exception message itself is never scrubbed.

````php
$logger->sensitiveKeys = '~pass|secret|token|iban~i';
````

Tests: `composer install && vendor/bin/phpunit` (runs a request through the PHP built-in web server).
