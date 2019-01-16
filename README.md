ErrorLogger
===========

Sends more info about the error than [Tracy\Logger](https://github.com/nette/tracy). Moreover, it can send multiple errors not only the first.

Installation
------------

Add to your composer.json
````
"repositories": [
  {
    "type": "git",
    "url": "https://github.com/AppsDevTeam/ErrorLogger"
  }
]
````

````
composer require adt/error-logger
````

Place this to your bootstrap.php before calling `$configurator->createContainer()`:
````
$logger = \ADT\ErrorLogger::install($email = 'errors@example.com', $maxEmailsPerDay = 10, $maxEmailsPerRequest = 10);
````
and this after calling `$configurator->createContainer()`:
```
if ($logger) {
	$logger->setup($container);
}
```
