# Sessions

Use `Fyre\Http\Session\Session` when you need state to survive across requests, such as login state, flash messages, or short-lived workflow data.

It wraps PHP sessions and adds dot-notation access, flash and temporary values, and pluggable storage handlers.

## Table of Contents

- [Start here](#start-here)
- [Using sessions in requests](#using-sessions-in-requests)
- [Working with session data](#working-with-session-data)
- [Session lifecycle](#session-lifecycle)
  - [Starting and closing](#starting-and-closing)
  - [Refreshing the session ID](#refreshing-the-session-id)
  - [Destroying the session](#destroying-the-session)
- [Session configuration](#session-configuration)
  - [Top-level options](#top-level-options)
  - [Example configuration](#example-configuration)
- [Session handlers](#session-handlers)
  - [File storage](#file-storage)
  - [Database storage](#database-storage)
  - [Redis storage](#redis-storage)
  - [Memcached storage](#memcached-storage)
  - [Custom handlers](#custom-handlers)
- [Behavior notes](#behavior-notes)
- [Related](#related)

## Start here

A `Session` instance wraps PHP session handling and gives you a simpler application API:

- session start happens lazily (the first `get()`, `set()`, `has()`, etc. starts the session if needed), unless something starts it explicitly (for example, `SessionMiddleware`)
- values are stored under `$_SESSION` and can be addressed with dot-notation keys
- “flash” values survive for one subsequent session start, and then are automatically cleared
- “temporary” values are cleared after their expiry time when the session is started

## Using sessions in requests

`SessionMiddleware` starts a session for each request and makes it available to downstream middleware/handlers via the request attributes.

To register it in your application’s middleware queue, see [HTTP Middleware](middleware.md).

- The session is injected under the `session` attribute key.
- The session is closed after the handler returns a response (a close is attempted even when exceptions are thrown).
- For safe HTTP methods (`GET`, `HEAD`, `OPTIONS`, `TRACE`), the middleware starts a read-only session when `Session::allowReadOnly()` returns true.

After `SessionMiddleware` has run for the current request, you can access the session via request attributes:

```php
use Fyre\Http\Session\Session;
use Psr\Http\Message\ServerRequestInterface;

function handle(ServerRequestInterface $request): void
{
    $session = $request->getAttribute('session');
    if (!($session instanceof Session)) {
        return;
    }

    $userId = $session->get('user.id');
}
```

## Working with session data

Session keys support dot notation, and data access starts the session lazily when necessary.

| Method | Purpose |
| --- | --- |
| `get($key, $default = null)` | read a value |
| `has($key)` | check whether a key exists |
| `set($key, $value)` | write a value |
| `delete($key)` | remove a value |
| `consume($key)` | read and remove a value |
| `setFlash($key, $value)` | keep a value for the next session start |
| `setTemp($key, $value, $expire = 300)` | keep a value until its lifetime expires |
| `clear()` | remove all session data |

```php
$session->set('user.id', 123);
$session->setFlash('notice', 'Profile saved.');
$session->setTemp('mfa.challenge', 'pending', 300);
```

The `session()` helper returns the current `Session` with no arguments, reads with one argument, and writes with two (see [Helpers](../core/helpers.md)).

## Session lifecycle

Sessions can be started, closed, refreshed (ID regeneration), or destroyed. In HTTP requests, `SessionMiddleware` handles start/close automatically; the methods below are still useful in custom flows (for example, explicit logout).

### Starting and closing

The session starts when you call `Session::start()`, or implicitly when you access session data (for example, `get()` calls `start()` internally).

In CLI, starting a session initializes `$_SESSION` when needed and uses the fixed session ID `cli`.

`Session::close()`:

- closes the underlying PHP session (non-CLI)
- resets the in-memory “started” state on the `Session` instance

`Session::startReadOnly()` starts the session in read-only mode and skips the write-oriented housekeeping that happens during a normal `start()`.

### Refreshing the session ID

Use `Session::refresh(bool $deleteOldSession = false): void` to regenerate the session ID (non-CLI). This is commonly done after authentication state changes.

### Destroying the session

Use `Session::destroy(): void` to destroy the current session and clear all in-memory session data.

## Session configuration

Session configuration is read from the `Session` key in your config (see [Config](../core/config.md)).

### Top-level options

- `expires` (`int|null`): idle timeout in seconds. When `null`, it defaults to PHP’s `session.gc_maxlifetime`.
- `path` (`string`): the session save path used by PHP (`session.save_path`).
  - When using `DatabaseSessionHandler`, this value is treated as the database table name.
- `allowReadOnly` (`bool`): whether read-only sessions are allowed (used by `SessionMiddleware` to pick between `start()` and `startReadOnly()`).
- `cookie` (`array`): cookie settings applied via PHP ini (`session.cookie_*` and `session.name`):
  - `name` (`string`)
  - `expires` (`int`) cookie lifetime in seconds (0 means “until the browser is closed”)
  - `domain` (`string`)
  - `path` (`string`)
  - `secure` (`bool`)
  - `sameSite` (`string`) for example `Lax`

Fyre also enables the usual safety-focused PHP session flags at runtime, such as HTTP-only cookies and strict mode.

### Example configuration

```php
use Fyre\Http\Session\Handlers\FileSessionHandler;

return [
    'Session' => [
        'expires' => 3600,
        'path' => 'tmp/sessions',
        'allowReadOnly' => true,
        'cookie' => [
            'name' => 'FyreSession',
            'secure' => true,
            'sameSite' => 'Lax',
        ],
        'handler' => [
            'className' => FileSessionHandler::class,
        ],
    ],
];
```

## Session handlers

By default, Fyre stores session data under the configured save path. You can switch to database, Redis, or Memcached storage through `Session.handler`.

Handlers are configured under `Session.handler`:

- `className` (`class-string<SessionHandlerInterface&SessionUpdateTimestampHandlerInterface>`): the handler class to build and register.
- `expires` (`int`): handler expiration in seconds. Defaults to `Session.expires`.
- `prefix` (`string`): optional storage key prefix for handlers that support it.

### File storage

Implemented by `FileSessionHandler`.

Stores one file per session under `Session.path`. The stored filename is `prefix + sessionId`.

On Unix, use a dedicated directory owned by the PHP worker. The handler creates missing directories with mode `0700`.

To create new session files with mode `0600`, start the PHP worker with a umask of `0077`. This mask also applies to other files the worker creates.

Notes:
- Writes use `LOCK_EX` to reduce race conditions.
- Missing session files read as an empty string (errors are suppressed).
- Garbage collection removes only expired regular files whose names match the configured prefix followed by a valid session ID. Symbolic links are skipped.
- Use a dedicated session directory when no prefix is configured. Shared directories require nonempty prefixes that do not overlap.

Common options:

- `prefix` (`string`): storage key prefix for the filename.

### Database storage

Implemented by `DatabaseSessionHandler`.

Stores session rows in a database table. `Session.path` is treated as the table name.

Common options:

- `connectionKey` (`string`): the database connection key to use (defaults to `default`).

The table must have at least:

- `id` (session id)
- `data` (session payload)
- `created`
- `modified`

Expired sessions are removed by comparing `modified` against the session lifetime.

### Redis storage

Implemented by `RedisSessionHandler`.

Stores session payloads in Redis, with a TTL based on `expires`.

Notes:
- The handler ignores `Session.path`.
- Redis TTL handles expiration internally; explicit garbage collection is not performed by the handler.

Common options:

- `host` (`string`)
- `port` (`int`)
- `password` (`string|null`)
- `database` (`int|null`)
- `timeout` (`int`)
- `tls` (`bool`)
- `ssl` (`array`):
  - `key` (`string|null`)
  - `cert` (`string|null`)
  - `ca` (`string|null`)
- `prefix` (`string`)

### Memcached storage

Implemented by `MemcachedSessionHandler`.

Stores session payloads in Memcached, with an expiration time based on `expires`.

Notes:
- The handler ignores `Session.path`.
- Memcached handles expiration internally; explicit garbage collection is not performed by the handler.

Common options:

- `host` (`string`)
- `port` (`int`)
- `weight` (`int`)
- `prefix` (`string`)

### Custom handlers

If you build a custom handler, extend the framework's `SessionHandler` base class. Custom handlers must implement `validateId()` so PHP strict mode can reject unknown IDs, and `updateTimestamp()` so unchanged sessions can refresh their expiry without rewriting their data.

## Behavior notes

- `startReadOnly()` does not enforce `allowReadOnly()`, so check `allowReadOnly()` when choosing to start read-only mode outside of `SessionMiddleware`.
- Writing methods throw a `SessionException` when the session is started in read-only mode.
- `startReadOnly()` does not update activity tracking, rotate flash values, or clear expired temporary values; these happen during `start()`.
- The default session cookie is marked `Secure`, so browsers will not send it over plain HTTP.
- When a session has expired, Fyre clears it and starts a new one on the next request. Use `refresh()` after authentication state changes when you want a new session ID as well.

## Related

- [Deployment](../deployment.md)
- [HTTP Middleware](middleware.md)
- [HTTP Requests](requests.md)
- [Cookies](cookies.md)
- [Auth](../auth/index.md)
