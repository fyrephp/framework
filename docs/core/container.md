# Container

Use `Fyre\Core\Container` when you want explicit control over dependency injection, object creation, and service lifetimes.

Most applications work through [Engine](engine.md), but the underlying container is useful for tests, manual composition, and lower-level framework work.

## Table of Contents

- [Start here](#start-here)
- [Core workflows](#core-workflows)
  - [Resolve a service with `use()`](#resolve-a-service-with-use)
  - [Build a new object with `build()`](#build-a-new-object-with-build)
  - [Call code with `call()`](#call-code-with-call)
- [Bindings and lifetimes](#bindings-and-lifetimes)
- [Managing container state](#managing-container-state)
- [Dependency resolution](#dependency-resolution)
- [Contextual attributes](#contextual-attributes)
- [Related](#related)

## Start here

The container gives you three core capabilities:

- resolve a service with `use()`
- build a fresh object with `build()`
- call a function or method with injected dependencies via `call()`

`Container` also implements PSR-11:

- `get()` delegates to `use()`
- `has()` checks whether an alias maps to an existing instance, factory, or instantiable class

## Core workflows

### Resolve a service with `use()`

`use($alias)` resolves a class name or custom alias and returns the matching service or value.

```php
use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Lang;

$container = new Container();

$container->singleton(Config::class);
$container->singleton(Lang::class);

$config = $container->use(Config::class)
    ->addPath('/path/to/config')
    ->load('app');

$lang = $container->use(Lang::class);
```

When a shared service has already been resolved, `use()` returns that same instance unless you pass manual arguments.

### Build a new object with `build()`

`build($className)` always creates a new instance of the class you ask for, while still resolving its dependencies through the container.

```php
$container = new Container();
$container->singleton(Config::class);

$langA = $container->build(Lang::class);
$langB = $container->build(Lang::class);
```

### Call code with `call()`

`call($callable)` runs a closure, method, or invokable object and resolves its parameters through the same rules used by `build()`.

```php
use Fyre\Core\Attributes\Config as ConfigAttribute;
use Fyre\Core\Config;

$container = new Container();
$container->singleton(Config::class);
$container->use(Config::class)->set('App.name', 'MyApp');

$name = $container->call(
    static fn(#[ConfigAttribute('App.name')] string $appName): string => $appName
);
```

For a non-static `[ClassName::class, 'method']` callable, the container builds the class before invoking the method.

## Bindings and lifetimes

Bindings tell the container how to resolve an alias. Lifetimes tell it whether the result should be reused.

- **Unbound class name**: if the alias is a concrete instantiable class, the container can build it on demand.
- `bind()`: bind an alias to a class name or factory closure.
- `singleton()`: bind a shared service that stays available for the life of the container.
- `scoped()`: bind a shared service that can be cleared later with `clearScoped()`.
- `instance()`: bind an alias directly to a specific value or object.
- `replaceInstance()`: replace the current cached instance without removing its existing binding or scoped lifetime.

String-to-string bindings are common for interfaces:

```php
use Fyre\Log\Handlers\ArrayLogger;
use Psr\Log\LoggerInterface;

$container->singleton(LoggerInterface::class, ArrayLogger::class);

$logger = $container->use(LoggerInterface::class);
```

If you pass manual arguments while resolving a shared binding, the returned instance is not stored as the shared instance.

Rebinding an alias clears its cached shared instance.

`instance()` replaces an alias's binding with a fixed value. Use `replaceInstance()` when you need to update the cached value while preserving the original binding and scoped lifetime:

```php
use Fyre\Http\ServerRequest;

$request = $container->use(ServerRequest::class)
    ->withAttribute('user', $user);

$container->replaceInstance(ServerRequest::class, $request);
```

If the alias is scoped, `clearScoped()` removes that replacement and the next resolution uses the preserved binding again.

## Managing container state

| Method | Effect |
| --- | --- |
| `clearScoped()` | clear scoped instances and their dependent shared services |
| `unset($alias, $unsetDependents = true)` | remove a cached instance and, by default, its dependent shared services |
| `unscoped($alias)` | keep a binding but exclude it from `clearScoped()` |
| `Container::getInstance()` | return the global container |
| `Container::setInstance($container)` | replace the global container |

`clearScoped()` is intended for request-specific or job-specific state in long-running processes. Bindings remain available, so the next resolution creates fresh scoped instances.

## Dependency resolution

For both `build()` and `call()`, parameters are resolved in this order:

1. a matching named argument you pass explicitly
2. the next positional argument you pass explicitly
3. a contextual attribute on the parameter
4. the first value matching the parameter type, supplied under an unmatched argument name
5. a class or interface type-hint resolved through the container
6. the parameter default value or `null` when allowed
7. otherwise, a `ContainerException`

That keeps constructor and method signatures predictable while still allowing targeted overrides.

For example, passing `['entity' => $entity]` to a callback expecting `Entity $item` uses the supplied entity. Arguments named for other parameters remain reserved for those parameters. Matching values are consumed once, in the order provided. Unused arguments are still appended positionally.

Type matching supports classes, interfaces, built-in types, nullable types, unions, and intersections. Matching does not coerce scalar values, except that integers are accepted for `float` parameters as PHP permits. Union and intersection types can match supplied values but are not automatically constructed by the container.

## Contextual attributes

Contextual attributes let a parameter come from runtime context instead of a normal binding. Common examples include:

- a config value with `#[Config('App.name')]`
- the current user with `#[CurrentUser]`
- a route argument with `#[RouteArgument('id')]`
- a keyed service such as `#[DB('readonly')]` or `#[Cache('redis')]`

For the full list and custom attribute examples, see [Contextual attributes](contextual-attributes.md).

## Related

- [Engine](engine.md)
- [Contextual attributes](contextual-attributes.md)
- [Route Bindings](../routing/route-bindings.md)
- [Worker](../queue/worker.md)
