# Cache

Cache expensive values behind named handlers, invalidate related entries with tags, and coordinate shared work with cache locks.

## Table of Contents

- [Start here](#start-here)
- [Configuring caches](#configuring-caches)
  - [Base cache options](#base-cache-options)
  - [Example configuration](#example-configuration)
- [Built-in cache handlers](#built-in-cache-handlers)
  - [Array handler](#array-handler)
  - [File handler](#file-handler)
  - [Redis handler](#redis-handler)
  - [Memcached handler](#memcached-handler)
  - [Null handler](#null-handler)
- [Using a cache](#using-a-cache)
- [Common operations](#common-operations)
  - [Tagged cache entries](#tagged-cache-entries)
  - [Cache locks](#cache-locks)
- [API summary](#api-summary)
- [Related](#related)

## Start here

After configuring a `default` handler, use `remember()` for the common read-or-compute path:

```php
$report = cache()->remember(
    'report.123',
    static fn() => buildReport(123),
    300
);
```

## Configuring caches

Cache configuration is read from the `Cache` key in your config (see [Config](../core/config.md)). Each named cache config is an options array passed to the selected handler.

### Base cache options

These options apply to all handlers:

| Option | Type | Default | Purpose |
| --- | --- | --- | --- |
| `className` | `class-string<Fyre\Cache\Cacher>` | required | cache handler class to build |
| `prefix` | `string` | `''` | value prepended to every cache key |
| `expire` | `int\|null` | `null` | default TTL in seconds when a call does not supply one |

Handler-specific options are documented below.

### Example configuration

```php
use Fyre\Cache\Handlers\File\FileCacher;
use Fyre\Cache\Handlers\Redis\RedisCacher;

return [
    'Cache' => [
        'default' => [
            'className' => FileCacher::class,
            'path' => 'tmp/cache',
            'prefix' => 'app_',
            'expire' => 3600,
        ],
        'redis' => [
            'className' => RedisCacher::class,
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 1,
            'prefix' => 'app:',
        ],
    ],
];
```

## Built-in cache handlers

The options below are specific to the built-in handlers under `Fyre\Cache\Handlers\*`.

### Array handler

`Fyre\Cache\Handlers\Array\ArrayCacher` keeps values in memory for the current PHP process. It has no handler-specific options.

### File handler

Caches values on the filesystem (`Fyre\Cache\Handlers\File\FileCacher`).

Make sure the configured `path` exists or can be created, and is writable by the PHP process.

| Option | Type | Default | Purpose |
| --- | --- | --- | --- |
| `path` | `string` | `/tmp/cache` | directory containing cache files |
| `mode` | `int` | `0640` | permissions applied when a new cache file is created |

The file handler rejects a `prefix` containing the system directory separator.

### Redis handler

Caches values using Redis (`Fyre\Cache\Handlers\Redis\RedisCacher`).

Requires `ext-redis` and a reachable Redis server.

| Option | Type | Default |
| --- | --- | --- |
| `host` | `string` | `127.0.0.1` |
| `password` | `string\|null` | `null` |
| `port` | `int\|string` | `6379` |
| `database` | `int\|string\|null` | `null` |
| `timeout` | `int\|string` | `0` |
| `persist` | `bool` | `true` |
| `flushDatabase` | `bool` | `false` |
| `tls` | `bool` | `false` |
| `ssl` | `array{key: string\|null, cert: string\|null, ca: string\|null}` | all values `null` |

`clear()` requires a non-empty `prefix` unless `flushDatabase` is enabled. Enabling `flushDatabase` allows an unprefixed handler to flush the selected Redis database.

### Memcached handler

Caches values using Memcached (`Fyre\Cache\Handlers\Memcached\MemcachedCacher`).

Requires `ext-memcached` and a reachable Memcached server.

| Option | Type | Default |
| --- | --- | --- |
| `host` | `string` | `127.0.0.1` |
| `port` | `int\|string` | `11211` |
| `weight` | `int` | `1` |
| `flushServer` | `bool` | `false` |

`clear()` throws a `CacheException` unless the prefix is empty and `flushServer` is `true`. A successful clear invalidates every item on the configured Memcached server, including other applications’ entries and active locks.

### Null handler

`Fyre\Cache\Handlers\Null\NullCacher` is a no-op handler with no handler-specific options. Reads return the provided default, writes are ignored, `increment()` returns `$amount`, and `decrement()` returns `-$amount` without persisting a counter.

## Using a cache

Use a config key to choose which named cache to work with. When no key is provided, `default` is used.

When caching is disabled, newly built caches resolve to a shared `NullCacher`, so reads miss and writes are ignored. `CacheManager` starts disabled when `App.debug` is enabled. Enabling or disabling the manager does not replace handlers that have already been loaded; unload or clear them before resolving them again.

```php
use Fyre\Cache\CacheManager;

$caches = app(CacheManager::class);

$default = $caches->use();
$redis = $caches->use('redis');
```

You can resolve a cache handler by key directly (see [Helpers](../core/helpers.md)):

```php
$default = cache();
$redis = cache('redis');
```

## Common operations

Cache handlers implement `Psr\SimpleCache\CacheInterface` (`get()`, `set()`, `delete()`, `clear()`, and the `*Multiple()` variants). The `Cacher` base class also provides higher-level helpers.

Examples below assume caching is enabled and the requested cache key exists.

```php
$cache = $caches->use();
// You can also do: $cache = cache();

$value = $cache->get('report.123');
$cache->set('report.123', $value, 300);

$value = $cache->remember('report.123', static fn() => buildReport(), 300);

$cache->increment('counters.reports_generated');
```

Stored keys cannot contain `{`, `}`, `(`, `)`, `/`, `\`, `@`, or `:`. Validation applies before the configured prefix is added. A `null` TTL uses the handler's configured `expire`; a zero or negative TTL deletes the affected entry.

### Tagged cache entries

Use `tags()` when you want cached values to become stale after one or more tag invalidations.

```php
$users = $cache->tags('users');

$users->set('user.1', $user, 300);
$user = $users->get('user.1');

$cache->invalidateTag('users');
```

Tags can be chained or provided as an array. Tags are compared as strings, and their order does not affect the cache namespace:

```php
$activeUsers = $cache->tags('users')->tags('active');
$activeUsers->set('user.1', $user, 300);

$same = $cache->tags(['active', 'users'])->get('user.1');
```

Tag invalidation is version-based. Invalidating a tag does not eagerly delete every tagged key; instead, tagged entries become stale and are treated as cache misses on the next read.

### Cache locks

Use `synchronized()` when a callback must run while holding a named lock. The lock is released in a `finally` block, including when the callback throws.

```php
$report = $cache->synchronized(
    'reports.daily',
    static fn() => buildDailyReport(),
    expires: 30,
    wait: 2
);
```

`expires` controls the lock lifetime. `wait` controls how long to wait for another owner to release the lock; the default `0` makes a single acquisition attempt.

For manual lock management, always release an acquired lock in a `finally` block:

```php
$lock = $cache->lock('reports.daily', 30);

if (!$lock->acquire(2)) {
    throw new RuntimeException('The report is already being generated.');
}

try {
    $report = buildDailyReport();
} finally {
    $lock->release();
}
```

Call `refresh()` during long-running manual work to extend a lock's lifetime. It succeeds only while this object still owns the lock.

Lock scope depends on the handler:

| Handler | Coordination scope |
| --- | --- |
| array | locks created by the same cacher instance |
| file, Redis, or Memcached | workers that share the same backend |
| null | no coordination |

`synchronized()` throws a `CacheException` when the lock cannot be acquired within `wait`. The lock expiry must be greater than zero, and the wait time cannot be negative.

## API summary

### Cache manager

| Method | Purpose |
| --- | --- |
| `use($key = 'default')` | get the shared handler for a configuration |
| `build($options)` | build a one-off handler without storing it |
| `getConfig($key = null)` | read one configuration, or all configurations |
| `hasConfig($key = 'default')` | check whether a configuration exists |
| `isLoaded($key = 'default')` | check whether a handler has been built |
| `setConfig($key, $options)` | add a runtime configuration |
| `unload($key = 'default')` | remove a configuration and its loaded handler |
| `clear()` | remove every configuration and loaded handler |
| `enable()` / `disable()` | control whether newly built handlers cache values |
| `isEnabled()` | check the current manager state |

### Cache operations

| Method | Purpose |
| --- | --- |
| `remember($key, $callback, $expire = null)` | read a value or compute and store it on a miss |
| `increment($key, $amount = 1)` / `decrement($key, $amount = 1)` | change a numeric value |
| `tags($tags)` | create a tagged cache wrapper |
| `invalidateTag($tag)` / `invalidateTags($tags)` | make tagged entries stale |
| `lock($key, $expires = 30)` | create an owner-specific lock |
| `synchronized($key, $callback, $expires = 30, $wait = 0)` | run a callback while holding a lock |

`TaggedCacher` supports the normal `get()`, `set()`, `delete()`, and `remember()` operations within its tag namespace. Calling `tags()` on it returns a new wrapper with the additional tags merged in.

## Related

- [Config](../core/config.md)
- [Helpers](../core/helpers.md)
