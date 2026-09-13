# FyreFramework

[![CI](https://github.com/fyrephp/framework/actions/workflows/ci.yml/badge.svg)](https://github.com/fyrephp/framework/actions/workflows/ci.yml)
[![Codecov](https://codecov.io/github/fyrephp/framework/branch/main/graph/badge.svg)](https://app.codecov.io/github/fyrephp/framework)
[![Packagist Version](https://img.shields.io/packagist/v/fyre/framework.svg)](https://packagist.org/packages/fyre/framework)
[![Packagist Downloads](https://img.shields.io/packagist/dt/fyre/framework.svg)](https://packagist.org/packages/fyre/framework)
[![GitHub License](https://img.shields.io/github/license/fyrephp/framework.svg)](LICENSE)

Use FyreFramework to build HTTP applications and CLI tools with routing, ORM, views, caching, queues, and other focused components.

Install the `fyre/framework` package and use individual subsystems as needed, or extend `Engine` when you want the framework's common application services available by default.

## Table of Contents

- [Start here](#start-here)
- [Requirements](#requirements)
- [Installation](#installation)
- [Application bootstrap](#application-bootstrap)
- [Hello world](#hello-world)
- [Documentation](#documentation)
- [Release and support](#release-and-support)
- [Repository development](#repository-development)
- [License](#license)

## Start here

- **Install the framework package**: follow [Installation](#installation).
- **Run a complete application**: follow the [Hello world guide](docs/getting-started.md#hello-world-application).
- **Build around the default application services**: see [Application bootstrap](#application-bootstrap).
- **Organize route actions**: see [Controllers](docs/routing/controllers.md).
- **Prepare for production**: follow the [Deployment guide](docs/deployment.md).
- **Use a specific subsystem**: browse the [documentation](#documentation).

## Requirements

- PHP >= 8.5
- Required PHP extensions: `ext-intl`, `ext-mbstring`, `ext-sodium`
- For database connections: `ext-pdo` plus the matching PDO driver such as `ext-pdo_mysql`, `ext-pdo_pgsql`, or `ext-pdo_sqlite`

Optional (depending on the parts you use):

- `ext-curl` (HTTP client requests)
- `ext-exif` (EXIF orientation detection for image manipulation)
- `ext-gd` (image manipulation)
- `ext-memcached` (Memcached cache)
- `ext-openssl` (OpenSSL encryption handler)
- `ext-pcntl` (queue workers and async promises)
- `ext-posix` (async promises)
- `ext-redis` (Redis cache and queue handlers)
- `ext-sockets` (async promises)

Fyre has no third-party runtime dependencies beyond PSR interfaces (`psr/*`).

## Installation

Install the package with Composer:

```bash
composer require fyre/framework
```

## Application bootstrap

A common bootstrap extends `Fyre\Core\Engine`, customizes the middleware queue, and shares the application instance with the framework helpers.

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

Your application repository defines its entry points, routes, configuration paths, and project layout around this package.

Continue with [Engine](docs/core/engine.md), [HTTP Middleware](docs/http/middleware.md), and [Routing](docs/routing/index.md).

## Hello world

The [Hello world application](docs/getting-started.md#hello-world-application) combines the bootstrap, route, HTTP request pipeline, response emitter, CLI entry point, and web-server rewrites into one copy-paste example.

## Documentation

Start with [Getting Started](docs/getting-started.md), use [Deployment](docs/deployment.md) when
preparing for production, or browse the [documentation index](docs/index.md). You can also jump
directly to the area you need:

- **Core services**: [Core](docs/core/index.md) → [Engine](docs/core/engine.md) → [Container](docs/core/container.md)
- **HTTP applications**: [HTTP](docs/http/index.md) → [Routing](docs/routing/index.md) → [Controllers](docs/routing/controllers.md)
- **Data and persistence**: [Database](docs/database/index.md) → [ORM](docs/orm/index.md)
- **Auth and security**: [Auth](docs/auth/index.md), [Security](docs/security/index.md)
- **Shared services**: [Events](docs/events/index.md), [Logging](docs/logging/index.md), [Mail](docs/mail/index.md), [Cache](docs/cache/index.md), [Queue](docs/queue/index.md)
- **Rendering and forms**: [View](docs/view/index.md), [Form](docs/form/index.md)
- **Tooling and tests**: [Console](docs/console/index.md), [Testing](docs/testing/index.md), [Utilities](docs/utilities/index.md)

## Release and support

- [Changelog](CHANGELOG.md) - user-visible changes, deprecations, and breaking changes
- [Security Policy](SECURITY.md) - supported releases and private vulnerability reporting
- [API Stability](docs/stability.md) - public API, internal symbols, and compatibility guarantees

## Repository development

Install the repository's dev dependencies and run the baseline checks:

```bash
composer validate --strict
composer install
composer audit --no-interaction
composer cs
composer stan
composer stan:tests
composer test:core
```

Additional local suites cover SQLite and external runtime integrations:

```bash
composer test:sqlite
composer test:external
```

The SQLite suite requires `ext-pdo` and `ext-pdo_sqlite`. The external suite requires `ext-curl` and a `google-chrome` executable for PDF rendering.

Service-backed suites are available for dependencies defined in `docker-compose.yml`:

```bash
docker compose up -d mysql
composer test:mysql
```

Available service-backed suites include `mariadb`, `mysql`, `postgres`, `redis`, `memcached`, and `smtp`.

## License

FyreFramework is released under the [MIT License](LICENSE).
