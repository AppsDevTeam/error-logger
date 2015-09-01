ErrorLogger
===========

Sends more info about the error than [Tracy\Logger](https://github.com/nette/tracy). Moreover, it can send multiple errors not only the first.

Installation
------------

Add to your composer.json
```
"repositories": [
  {
    "type": "git",
    "url": "https://github.com/AppsDevTeam/ErrorLogger"
  }
]
````

````
composer require adt/errorLogger
````

Place this to your bootstrap.php:
````
\ADT\ErrorLogger::install($container);
````
