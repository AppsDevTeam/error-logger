ErrorLogger
===========

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
