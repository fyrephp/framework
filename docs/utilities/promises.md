# Promises

Use `Promise` (`Fyre\Utility\Promise\Promise`) to represent a value or failure and compose follow-up work with `then()`, `catch()`, and `finally()`. Use `AsyncPromise` for CLI work that should run in a forked child process.

## Table of Contents

- [Synchronous promises](#synchronous-promises)
- [Chaining](#chaining)
- [Combining promises](#combining-promises)
- [Async promises](#async-promises)
- [Settlement and materialization](#settlement-and-materialization)
- [Behavior notes](#behavior-notes)
- [Related](#related)

## Synchronous promises

`Promise` executes its callback immediately in the current process. A zero-argument callback fulfills with its return value:

```php
use Fyre\Utility\Promise\Promise;

$promise = new Promise(static fn(): string => 'ready');

$result = $promise
    ->then(strtoupper(...))
    ->then(static fn(string $value): string => $value.'!');

echo Promise::await($result); // READY!
```

A callback with parameters receives resolve and reject closures. Only the first call settles the promise; an exception thrown before settlement rejects it.

```php
use Closure;

$promise = new Promise(
    static function(Closure $resolve, Closure $reject): void {
        $resolve('ready');
    }
);
```

| Method | Result |
| --- | --- |
| `resolve($value = null)` | returns an existing `PromiseInterface` unchanged, or wraps a value as fulfilled |
| `reject($reason = null)` | creates a rejected promise; `null` becomes an empty `RuntimeException` |
| `await($promise)` | returns the fulfilled value or throws the rejection reason |

## Chaining

The `PromiseInterface` contract is shared by synchronous, asynchronous, fulfilled, and rejected promises:

| Method | When it runs | Chain result |
| --- | --- | --- |
| `then($onFulfilled, $onRejected = null)` | matching fulfillment or rejection handler | handler's value or promise |
| `catch($onRejected)` | rejection | handler's value or promise |
| `finally($onFinally)` | either outcome | original outcome unless cleanup throws or returns a rejected promise |

Handlers run as soon as the promise settles. Returning a `PromiseInterface` adopts that promise's outcome; returning any other value fulfills the next promise. A thrown exception rejects the next promise.

```php
use RuntimeException;

$value = Promise::reject(new RuntimeException('Unavailable'))
    ->catch(static fn(RuntimeException $e): string => 'fallback')
    ->finally(static function(): void {
        // Release resources.
    });
```

A promise cannot resolve with itself, and attempting to settle the same pending `Promise` more than once raises `LogicException`.

## Combining promises

Combination methods accept arrays containing promises, plain values, or both:

| Method | Fulfillment | Rejection | Empty input |
| --- | --- | --- | --- |
| `all($values)` | all results with the original keys | first rejected item | empty array |
| `any($values)` | first fulfilled value | `RuntimeException` after every item rejects | `RuntimeException` |
| `race($values)` | first settled value | first settled rejection | `null` |

```php
$result = Promise::await(Promise::all([
    'first' => Promise::resolve(1),
    'second' => 2,
]));
```

For already-settled synchronous values, "first" follows input iteration order. For `AsyncPromise` values, the methods repeatedly poll until they can determine the result.

## Async promises

`AsyncPromise` executes the callback in a forked child process and sends its result back over a Unix socket:

```php
use Closure;
use Fyre\Utility\Promise\AsyncPromise;

$promise = new AsyncPromise(
    static function(Closure $resolve, Closure $reject): void {
        $resolve(['pid' => getmypid()]);
    }
);

$result = Promise::await($promise);
```

The callback must accept the resolve and reject closures and must call one of them. A zero-argument async callback cannot return its value to the parent.

| Method | Behavior |
| --- | --- |
| `wait()` | blocks, polling every 100 milliseconds until the child settles |
| `cancel($message = null)` | kills and reaps a pending child, then rejects with `CancelledPromiseException` |

`AsyncPromise` requires the `pcntl`, `posix`, and `sockets` extensions and a SAPI that supports `pcntl_fork()`, normally CLI on a Unix-like platform. Both fulfilled values and rejection reasons cross the socket through PHP serialization and must be serializable.

The maximum child runtime is 300 seconds. A child still running beyond that limit, or reported as stopped, is cancelled during polling.

An `AsyncPromise` owns its child process. When the promise is destroyed, completed work is reaped and unfinished work is terminated and reaped, without invoking promise handlers. Retain the promise or a chain that references it while its work should continue. Reference cycles can delay destruction until garbage collection runs.

## Settlement and materialization

Synchronous promises settle during construction or callback execution. Chaining them does not defer work or schedule it on an event loop.

An async child's result is not applied to the parent-side promise until it is polled. Drive it with `wait()`, `Promise::await()`, or one of the combination methods. `await()` and the combination methods also drive async promises behind `then()`, `catch()`, and `finally()` chains. `all()`, `any()`, and `race()` poll async children in a tight loop; use direct `wait()` or `await()` when busy polling is undesirable.

Combination methods materialize their output arrays. `all()` retains the input keys; `any()` and `race()` return a single value.

## Behavior notes

- Rejection reasons are always `Throwable` instances. Calling `reject()` without one creates a `RuntimeException`.
- Unhandled rejections are not silent: a rejected promise may throw its reason during destruction when no rejection handler was attached.
- Attaching an `onRejected` callback through `then()`, `catch()`, or `finally()` marks that rejection as handled.
- After `all()`, `any()`, or `race()` has its result, remaining async promises are no longer polled. Retained promises remain usable; destroyed promises clean up their child processes. Rejection handlers are attached to prevent abandoned rejection errors.
- If a child exits without sending a valid serialized result, the parent throws `RuntimeException` when it polls the socket.
- `cancel()` does nothing after settlement and may throw if the child cannot be killed or reaped.
- `Promise` supports instance and static macros; see [Macros](../core/macros.md).

## Related

- [Utilities](index.md)
- [Queue](../queue/index.md)
- [Worker](../queue/worker.md)
