# Getting Started

Use this guide to install FyreFramework, check its requirements, and run a minimal HTTP and CLI application.

FyreFramework is distributed as a package rather than an application skeleton. Your application repository defines its entry points, routes, configuration paths, and project layout around the framework.

## Table of Contents

- [Start here](#start-here)
- [Requirements](#requirements)
- [Installation](#installation)
- [Hello world application](#hello-world-application)
  - [Application bootstrap](#application-bootstrap)
  - [HTTP front controller](#http-front-controller)
  - [CLI entry point](#cli-entry-point)
  - [Web-server configuration](#web-server-configuration)
- [Next steps](#next-steps)
- [Behavior notes](#behavior-notes)
- [Related](#related)

## Start here

The usual setup is:

1. Check that your PHP installation provides the extensions needed by the parts of the framework you plan to use.
2. Install `fyre/framework` with Composer.
3. Follow the [Hello world application](#hello-world-application) to run a complete request through the framework.
4. Add the routes, middleware, and configuration required by your application.

If you only need an individual subsystem, install the same package and resolve or construct that subsystem directly instead of building around `Engine`.

## Requirements

FyreFramework requires:

- PHP 8.5 or later
- `ext-intl`
- `ext-mbstring`
- `ext-sodium`

Database connections also require `ext-pdo` and the matching PDO driver, such as `ext-pdo_mysql`, `ext-pdo_pgsql`, or `ext-pdo_sqlite`.

Some features require additional extensions:

- `ext-curl` for HTTP client requests
- `ext-exif` for EXIF orientation detection during image manipulation
- `ext-gd` for image manipulation
- `ext-memcached` for the Memcached cache handler
- `ext-openssl` for the OpenSSL encryption handler
- `ext-pcntl` for queue workers and asynchronous promises
- `ext-posix` for asynchronous promises
- `ext-redis` for Redis cache and queue handlers
- `ext-sockets` for asynchronous promises

The framework has no third-party runtime dependencies beyond PSR interfaces (`psr/*`).

## Installation

Install the framework package with Composer:

```bash
composer require fyre/framework
```

Composer installs the framework and makes its classes available through the generated autoloader.

## Hello world application

FyreFramework is intentionally a library package rather than an application skeleton. The following files are enough to run a complete application:

```text
my-app/
├── bin/
│   └── console
├── public/
│   └── index.php
├── bootstrap.php
└── vendor/
```

### Application bootstrap

Create `bootstrap.php` in the application root. It loads Composer, configures the HTTP middleware queue, and shares the application instance with the framework helpers:

```php
<?php
declare(strict_types=1);

use Fyre\Core\Engine;
use Fyre\Core\Loader;
use Fyre\Http\MiddlewareQueue;

$composer = require __DIR__.'/vendor/autoload.php';

final class Application extends Engine
{
    #[Override]
    public function middleware(MiddlewareQueue $queue): MiddlewareQueue
    {
        return $queue
            ->add('error')
            ->add('router')
            ->add('bindings');
    }
}

$loader = new Loader()
    ->addClassMap($composer->getClassMap())
    ->addNamespaces($composer->getPrefixesPsr4())
    ->register();

$app = new Application($loader);
Application::setInstance($app);

return $app;
```

### HTTP front controller

Create `public/index.php`. This registers a route, builds the current request, runs it through middleware and routing, then emits the resulting response:

```php
<?php
declare(strict_types=1);

use Fyre\Core\Engine;
use Fyre\Http\RequestHandler;
use Fyre\Http\ResponseEmitter;
use Fyre\Http\ServerRequest;
use Fyre\Router\RouteHandler;
use Fyre\Router\Router;

/** @var Engine $app */
$app = require dirname(__DIR__).'/bootstrap.php';

$app->use(Router::class)->get(
    '/',
    static fn(): string => 'Hello, world!'
);

$request = $app->use(ServerRequest::class);
$handler = $app->use(RequestHandler::class, [
    'fallbackHandler' => $app->use(RouteHandler::class),
]);
$response = $handler->handle($request);

$app->use(ResponseEmitter::class)->emit($response, $request);
```

Run the application with PHP's development server, then open <http://127.0.0.1:8080>:

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

### CLI entry point

Create `bin/console`:

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

use Fyre\Console\CommandRunner;
use Fyre\Core\Engine;

/** @var Engine $app */
$app = require dirname(__DIR__).'/bootstrap.php';

exit($app->use(CommandRunner::class)->handle($argv));
```

Make it executable and run it without arguments to list the available commands:

```bash
chmod +x bin/console
bin/console
```

### Web-server configuration

In production, set the document root to `public`. For Apache with `mod_rewrite`, create `public/.htaccess`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

For Nginx, route requests for missing files to the front controller and adjust `fastcgi_pass` for the installed PHP-FPM service:

```nginx
root /path/to/my-app/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

For production configuration, storage, migrations, security, workers, and health checks, see
[Deployment](deployment.md).

## Next steps

Continue with the area that matches what you are building:

- [Engine](core/engine.md) - configure the application services provided by the default engine
- [Config](core/config.md) - define and load application configuration
- [HTTP Middleware](http/middleware.md) - assemble the HTTP request pipeline
- [Routing](routing/index.md) - define routes and dispatch request handlers
- [Controllers](routing/controllers.md) - organize route actions with dependency injection, responses, and views
- [Deployment](deployment.md) - prepare an application release for production
- [Console](console/index.md) - build and run console commands
- [Database](database/index.md) - configure connections and build queries
- [ORM](orm/index.md) - work with models, entities, and relationships
- [Testing](testing/index.md) - test framework-powered application code

## Behavior notes

- The framework package does not prescribe an application directory layout or generate entry points; the Hello world layout is a minimal starting point.
- Only enable and configure the subsystems your application uses.
- Optional PHP extensions are required only when the corresponding feature is used.

## Related

- [Core](core/index.md)
- [HTTP](http/index.md)
- [Controllers](routing/controllers.md)
- [Deployment](deployment.md)
- [Console](console/index.md)
- [Testing](testing/index.md)
