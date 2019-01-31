ErrorLogger
===========

Sends more info about the error than [Tracy\Logger](https://github.com/nette/tracy). Moreover, it can send multiple errors not only the first.

Installation
------------

````
composer require adt/error-logger
````

Place this to your bootstrap.php after calling `$configurator->enableDebugger()` and before calling `$configurator->createContainer()`:
````
$logger = \ADT\ErrorLogger::install($email = 'errors@example.com', $maxEmailsPerDay = 10, $maxEmailsPerRequest = 10);
````
and this after calling `$configurator->createContainer()`:
```
if ($logger) {
	$logger->setup($container);
}
```

**Sensitive fields:**

You can specify keys of array, which will be hidden in POST dump.

Example:

````
$logger = \ADT\ErrorLogger::install(
	'errors@example.com',
	10,
	10,
	$sensitiveFields = [
		'password',
	]
);
````

POST dump:

```
POST:array (4)
   username => "my_username" (11)
   password => "*****" (5)
   login => "Sign in" (7)
   _do => "signForm-submit" (15)
```

