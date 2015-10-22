Codeception extension to start and stop PHP built-in web server for your tests.

## Minimum requirements

* Codeception 2.0
* PHP 5.4

## Installation

1. Install [Codeception](http://codeception.com) via Composer
2. Add `archanium/phpbuiltinserver: "dev-master"` to your `composer.json`
3. Run `composer install`
4. Include extensions into `codeception.yml` configuration:

## Configuration

### general example

``` yaml
paths:
    tests: .
    log: _log
    data: _data
    helpers: _helpers
extensions:
    enabled:
        - Codeception\Extension\PhpBuiltinServer
    config:
        Codeception\Extension\PhpBuiltinServer:
            hostname: localhost
            port: 8000
            documentRoot: tests/_data
            startDelay: 1
            phpIni: /etc/php5/apache2/php.ini
```

### example for projects based on Symfony
``` yaml
paths:
    tests: .
    log: _log
    data: _data
    helpers: _helpers
extensions:
    enabled:
        - Codeception\Extension\PhpBuiltinServer
    config:
        Codeception\Extension\PhpBuiltinServer:
            hostname: localhost
            port: 8000
            documentRoot: ../web
            router: ../web/app.php
            directoryIndex: app.php
            startDelay: 1
            phpIni: /etc/php5/apache2/php.ini
```
