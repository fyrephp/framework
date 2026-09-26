# HTTP Middleware

Use middleware for request logic that should run before or after a route or handler, such as sessions, authentication, CSRF protection, and response headers.

`MiddlewareQueue` stores entries in execution order. `MiddlewareRegistry` maps aliases and groups to executable middleware.

## Table of Contents

- [Define the application queue](#define-the-application-queue)
- [Built-in middleware](#built-in-middleware)
- [Register aliases](#register-aliases)
- [Group middleware](#group-middleware)
- [Pass inline arguments](#pass-inline-arguments)
- [Queue and registry reference](#queue-and-registry-reference)
- [Related](#related)

## Define the application queue

Override `Engine::middleware()` to define the global middleware order (see [Engine](../core/engine.md)):

```php
use Fyre\Core\Engine;
use Fyre\Http\MiddlewareQueue;
use Override;

class Application extends Engine
{
    #[Override]
    public function middleware(MiddlewareQueue $queue): MiddlewareQueue
    {
        return $queue
            ->add('error')
            ->add('session')
            ->add('auth')
            ->add('router')
            ->add('bindings');
    }
}
```

Place middleware that creates context before middleware that consumes it. For example, `bindings` requires route information and therefore runs after `router`. Keep `error` near the start so errors thrown by later middleware use the same exception-rendering path.

A queue entry may be a PSR-15 middleware instance, a middleware closure, or a string alias or class name resolved by `MiddlewareRegistry`.

## Built-in middleware

`Engine` maps these aliases by default:

| Alias | Middleware | Purpose |
| --- | --- | --- |
| `error` | `ErrorHandlerMiddleware` | render thrown exceptions through `ErrorHandler` |
| `session` | `SessionMiddleware` | open the session, attach it to the request, and close it afterward |
| `auth` | `AuthMiddleware` | authenticate the request and attach `auth` and `user` attributes |
| `authenticated` | `AuthenticatedMiddleware` | require a logged-in user |
| `unauthenticated` | `UnauthenticatedMiddleware` | require a logged-out user |
| `can` | `AuthorizedMiddleware` | enforce an authorization rule |
| `cors` | `CorsMiddleware` | apply Cross-Origin Resource Sharing policy and handle preflight requests |
| `csrf` | `CsrfProtectionMiddleware` | validate CSRF tokens and apply response behavior |
| `csp` | `CspMiddleware` | apply Content Security Policy headers |
| `router` | `RouterMiddleware` | match the request and attach route context |
| `bindings` | `SubstituteBindingsMiddleware` | resolve route parameters to entities, enums, or custom values |

`RateLimiterMiddleware` is also included, but it is not mapped by default. Map it under an alias such as `throttle` when needed.

The default error renderer follows `App.debug`. With debugging disabled or absent, unexpected exceptions produce a generic `500 Internal Server Error` body. With debugging enabled, the escaped exception and stack trace are included. A custom `Error.renderer` replaces that behavior and is responsible for controlling what it exposes.

Registering `ErrorHandler` also converts non-suppressed PHP errors into `ErrorException` instances.

`ErrorHandler` maps `Fyre\ORM\Exceptions\RecordNotFoundException` to an HTTP 404 status. Custom renderers still receive the original exception.

See [Authentication](../auth/authentication.md), [Authorization](../auth/authorization.md), [Auth Middleware](../auth/middleware.md), [CORS](../security/cors.md), [CSRF](../security/csrf.md), [Content Security Policy](../security/csp.md), [Rate Limiting](../security/rate-limiting.md), [Router](../routing/router.md), and [Route Bindings](../routing/route-bindings.md) for feature-specific setup.

## Register aliases

Map a class when the container should construct the middleware:

```php
use Fyre\Security\Middleware\RateLimiterMiddleware;

$registry->map('throttle', RateLimiterMiddleware::class, [
    'options' => [
        'limit' => 120,
        'window' => 60,
    ],
]);
```

The optional third argument supplies container build arguments. A closure passed to `map()` is a container-invoked factory and must return a middleware closure or PSR-15 middleware instance; it is not itself the request middleware.

Aliases are shared after their first resolution. Mapping the same alias again discards its cached instance.

## Group middleware

Use `group()` to expose a sequence under one alias:

```php
$registry->group('web', [
    'session',
    'csrf',
    'auth',
]);

$queue->add('web');
```

A group runs as a nested queue. When it finishes, request handling returns to the next entry in the outer queue.

## Pass inline arguments

String entries may append arguments using `alias:arg1,arg2`:

```php
$queue->add('can:admin');
$queue->add('throttle:120,60,2');
```

The alias prefix must be mapped or be a resolvable middleware class. Arguments are passed after the request and handler as untrimmed strings; they are not type-cast. An entry ending in `:` passes one empty-string argument.

Only use inline arguments with middleware whose additional parameters are optional or always supplied by that alias.

## Queue and registry reference

Resolve the registry from the container when configuring it outside `Engine`:

```php
use Fyre\Http\MiddlewareRegistry;

$registry = app(MiddlewareRegistry::class);
```

| API | Purpose |
| --- | --- |
| `MiddlewareQueue::add($middleware)` | append an entry |
| `MiddlewareQueue::prepend($middleware)` | prepend an entry |
| `MiddlewareQueue::insertAt($index, $middleware)` | insert using `array_splice()` index semantics |
| `MiddlewareRegistry::map($alias, $middleware, $arguments = [])` | map an alias to a class or factory |
| `MiddlewareRegistry::group($alias, $middleware)` | map an alias to a nested queue |
| `MiddlewareRegistry::resolve($middleware)` | resolve an entry and its inline arguments |
| `MiddlewareRegistry::use($alias)` | return the shared middleware for an alias |
| `MiddlewareRegistry::clear()` | remove all aliases and cached instances |

For queue execution, fallback handling, and request scoping, see [Request Handler](request-handler.md).

## Related

- [Engine](../core/engine.md)
- [HTTP Requests](requests.md)
- [HTTP Responses](responses.md)
- [Request Handler](request-handler.md)
- [Sessions](sessions.md)
- [Router](../routing/router.md)
- [Authentication](../auth/authentication.md)
- [Authorization](../auth/authorization.md)
- [CORS](../security/cors.md)
- [CSRF](../security/csrf.md)
- [Content Security Policy](../security/csp.md)
- [Rate Limiting](../security/rate-limiting.md)
