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

Place this to your bootstrap.php:
````
\ADT\ErrorLogger::install($container);
````

Configuration
-------------

To override maximum number of sent emails per day (default is 10), add it as a second argument to `install` method:
````
\ADT\ErrorLogger::install($container, 25);
````

Available parameters in `config.neon`:
````
parameters:
    ...

    logger:
        maxEmailsPerDay: 10
        maxEmailsPerRequest: 10

    ...
````
