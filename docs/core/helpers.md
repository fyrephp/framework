# Helpers

Helpers provide concise access to common framework services and small runtime operations. They are most useful in templates, route closures, and other short-lived code where injecting every dependency would obscure the task.

## Table of Contents

- [Start here](#start-here)
- [Choose helpers or dependency injection](#choose-helpers-or-dependency-injection)
- [Helper reference](#helper-reference)
  - [Engine, configuration, and language](#engine-configuration-and-language)
  - [HTTP, routing, and sessions](#http-routing-and-sessions)
  - [Authentication and authorization](#authentication-and-authorization)
  - [Views and templates](#views-and-templates)
  - [Data and services](#data-and-services)
  - [Utilities and debugging](#utilities-and-debugging)
- [Behavior notes](#behavior-notes)
- [Related](#related)

## Start here

The helper functions are defined in `src/functions.php` and loaded by Composer. In namespaced code, import the helpers you use explicitly:

```php
use function logged_in;
use function redirect;
use function route;
use function view;

if (!logged_in()) {
    return route('login') |> redirect(...);
}

return view('dashboard');
```

Call `app()` without arguments when you need the shared `Engine`, or pass a service alias to resolve it from the container:

```php
use Fyre\Core\Config;

$app = app();
$config = app(Config::class);
```

## Choose helpers or dependency injection

Use helpers when the dependency is naturally part of the current application context, especially in templates and small callbacks. Prefer constructor injection when a dependency is part of a class’s contract or needs to be replaced by a test double.

Most helpers delegate to the same shared services available through the container. The main exceptions are small runtime wrappers such as `env()`, `dump()`, `dd()`, and `abort()`.

## Helper reference

### Engine, configuration, and language

| Helper | Result |
| --- | --- |
| `app(string\|null $alias = null, array $arguments = []): mixed` | shared `Engine` without an alias, or a service resolved with `Container::use()` |
| `config(string\|null $key = null, mixed $default = null): mixed` | shared `Config` without a key, or a dot-notation config value |
| `env(string $name, mixed $default = null): mixed` | environment value, or the default when it is unset or empty |
| `__(string $key, array $data = []): array\|string\|null` | language value with optional placeholder data |

### HTTP, routing, and sessions

| Helper | Result |
| --- | --- |
| `request(string\|null $key = null, string\|null $as = null): mixed` | current `ServerRequest` without arguments, or request data parsed through `getData()` |
| `response(): ClientResponse` | new response resolved through the response factory binding |
| `json(mixed $data, bool $stream = false): ClientResponse` | JSON response; streaming emits an iterable as a JSON array |
| `route(string $name, array $arguments = [], string\|null $scheme = null, string\|null $host = null, int\|null $port = null, bool\|null $full = null): string` | URL generated from a route alias, placeholders, and optional origin overrides |
| `redirect(string\|Uri $uri, int $code = 302, array $options = []): RedirectResponse` | redirect response for a URI |
| `abort(int $code = 500, string\|null $message = null): never` | throws the HTTP exception mapped to the status code |
| `session(string\|null $key = null, mixed $value = null): mixed` | current `Session`, a stored value, or the result of writing a value |
| `asset(string $path, bool $full = false): string` | normalized asset URL, optionally resolved against `App.baseUri` |

`route()` accepts optional `$scheme`, `$host`, `$port`, and `$full` arguments after the route arguments. It throws `RouterException` when the alias is missing, a required placeholder is absent, or a value does not match its route pattern.

Both request and response objects are immutable. Return the value produced by response `with*()` methods:

```php
return response()
    ->withStatus(202)
    ->withJson(['queued' => true]);
```

Call `session()` with no arguments for the session object, one argument to read, or two arguments to write:

```php
$step = session('wizard.step');
session('wizard.step', 2);
```

### Authentication and authorization

| Helper | Result |
| --- | --- |
| `auth(): Auth` | shared authentication service |
| `user(): Entity\|null` | current authenticated user |
| `logged_in(): bool` | whether a user is logged in |
| `authorize(string $rule, mixed ...$args): void` | authorize a rule and throw when access is denied |
| `can(string $rule, mixed ...$args): bool` | whether a rule is allowed |
| `cannot(string $rule, mixed ...$args): bool` | whether a rule is denied |
| `can_any(array $rules, mixed ...$args): bool` | whether any listed rule is allowed |
| `can_none(array $rules, mixed ...$args): bool` | whether every listed rule is denied |

### Views and templates

| Helper | Result |
| --- | --- |
| `view(string $template, array $data = [], string\|null $layout = null): string` | rendered template using the selected or default layout |
| `element(string $file, array $data = []): string` | rendered element with its local data |
| `escape(string $string): string` | string escaped for HTML |

### Data and services

| Helper | Result |
| --- | --- |
| `cache(string $key = 'default'): Cacher` | shared configured cache handler |
| `db(string $key = 'default'): Connection` | shared configured database connection |
| `model(string $alias): Model` | shared model resolved by alias |
| `email(string $key = 'default'): Email` | new email from the configured mailer |
| `encryption(string $key = 'default'): Encrypter` | shared configured encryption handler |
| `queue(string $className, array $arguments = [], array $options = []): void` | enqueue a job through the shared queue manager |
| `type(string\|null $type = null): Type\|TypeParser` | shared `TypeParser` without a name, or the named type handler |

### Utilities and debugging

| Helper | Result |
| --- | --- |
| `collect(array\|Closure\|JsonSerializable\|Traversable\|null $source): Collection` | new collection containing the source values |
| `now(): DateTime` | new date/time value for the current instant |
| `dump(mixed ...$data): void` | dump values with `var_dump()` |
| `dd(mixed ...$data): never` | dump values and stop execution |
| `log_message(string $type, string $message, array $data = []): void` | write through the shared log manager |

## Behavior notes

- Helpers that resolve services rely on the shared `Engine`; set the application instance during bootstrap when loader mappings or discovery features matter.
- `abort()` directly maps status codes `400`, `401`, `403`, `404`, `405`, `406`, `409`, `410`, `501`, and `503`. Other codes produce an `InternalServerException` carrying the supplied code.
- `cache()` returns a no-op handler when caching is disabled. By default, caching is disabled while `App.debug` is enabled.
- `env()` treats both an unset variable and an empty string as missing.
- `dump()` wraps its output in `<pre>` outside CLI and uses `var_dump()` for each value.
- `view()` uses `App.defaultLayout` when no layout is supplied.
- `asset($path, true)` resolves the path relative to `App.baseUri`; `asset($path, false)` treats it as-is.

## Related

- [Engine](engine.md)
- [Container](container.md)
- [Config](config.md)
- [Language (`Lang`)](lang.md)
- [Authentication](../auth/authentication.md)
- [HTTP](../http/index.md)
- [Routing](../routing/index.md)
- [View](../view/index.md)
