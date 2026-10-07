# Database connections

Use database connections to configure one or more databases and resolve the one a given piece of code should use.

Most applications define a single `default` connection and only add more when they need separate databases, credentials, or drivers.

## Table of Contents

- [Start here](#start-here)
- [Connection configuration](#connection-configuration)
  - [Common connection options](#common-connection-options)
  - [Example configuration](#example-configuration)
- [Built-in connection handlers](#built-in-connection-handlers)
  - [MySQL](#mysql)
  - [PostgreSQL](#postgresql)
  - [SQLite](#sqlite)
- [Selecting a connection](#selecting-a-connection)
- [Building one-off connections](#building-one-off-connections)
- [Managing connections](#managing-connections)
- [Running queries](#running-queries)
- [Transactions](#transactions)
- [Connection retries](#connection-retries)
- [Database locks](#database-locks)
- [Connection utilities](#connection-utilities)
- [Troubleshooting](#troubleshooting)
- [Behavior notes](#behavior-notes)
- [Related](#related)

## Start here

For the common application path:

1. Define a `default` entry under the `Database` config key.
2. Resolve it with `db()` or `ConnectionManager::use()`.
3. Build queries or run a transaction through the returned `Connection`.

Add named connections only when code needs a different database, driver, or credentials. Use `build()` for a temporary connection that should not be stored or shared.

## Connection configuration

Define connections under the `Database` key in your config (see [Config](../core/config.md)). Each named entry is an options array passed to the selected connection handler.

### Common connection options

These options apply to every handler:

| Option | Type | Default | Purpose |
| --- | --- | --- | --- |
| `className` | `class-string<Fyre\DB\Connection>` | none | connection class to build |
| `log` | `bool` | `false` | log queries at `debug` level with the `queries` scope |

When query logging is enabled, configure a logger that accepts the `debug` level and `queries` scope; see [Logging](../logging/index.md).

Other options depend on the selected handler.

### Example configuration

```php
use Fyre\DB\Handlers\Mysql\MysqlConnection;
use Fyre\DB\Handlers\Sqlite\SqliteConnection;

return [
    'Database' => [
        'default' => [
            'className' => MysqlConnection::class,
            'host' => '127.0.0.1',
            'username' => 'app',
            'password' => 'secret',
            'database' => 'app',
        ],
        'analytics' => [
            'className' => SqliteConnection::class,
            'database' => 'tmp/analytics.sqlite',
        ],
    ],
];
```

Example: enabling query logging for a connection

```php
use Fyre\DB\Handlers\Mysql\MysqlConnection;
use Fyre\Log\Handlers\FileLogger;

return [
    'Database' => [
        'default' => [
            'className' => MysqlConnection::class,
            'host' => '127.0.0.1',
            'username' => 'app',
            'password' => 'secret',
            'database' => 'app',
            'log' => true,
        ],
    ],
    'Log' => [
        'default' => [
            'className' => FileLogger::class,
            'path' => 'tmp/logs',
            'levels' => ['debug'],
            'scopes' => ['queries'],
        ],
    ],
];
```

## Built-in connection handlers

The options below are specific to the built-in handlers under `Fyre\DB\Handlers\*`.

### MySQL

Use `MysqlConnection::class` as `className`.

| Option | Type | Default |
| --- | --- | --- |
| `host` | `string` | `127.0.0.1` |
| `username` | `string` | `''` |
| `password` | `string` | `''` |
| `database` | `string` | `''` |
| `port` | `int\|string` | `'3306'` |
| `charset` | `string` | `utf8mb4` |
| `collation` | `string` | `utf8mb4_unicode_ci` |
| `compress` | `bool` | `false` |
| `persist` | `bool` | `false` |
| `timeout` | `mixed` | `null` |
| `ssl` | `array` | `key`, `cert`, `ca`, `capath`, and `cipher` set to `null` |
| `flags` | `array` | `[]` |

`flags` are merged into the default PDO driver options.

### PostgreSQL

Use `PostgresConnection::class` as `className`.

| Option | Type | Default |
| --- | --- | --- |
| `host` | `string` | `127.0.0.1` |
| `username` | `string` | `''` |
| `password` | `string` | `''` |
| `database` | `string` | `''` |
| `port` | `int\|string` | `'5432'` |
| `charset` | `string` | `utf8` |
| `schema` | `string` | `public` |
| `persist` | `bool` | `false` |
| `timeout` | `mixed` | `null` |
| `flags` | `array` | `[]` |

`flags` are merged into the default PDO driver options.

### SQLite

Use `SqliteConnection::class` as `className`.

| Option | Type | Default |
| --- | --- | --- |
| `database` | `string` | `:memory:` |
| `mask` | `int` | `0644` |
| `cache` | `string\|null` | `null` |
| `mode` | `string\|null` | `null` |
| `persist` | `bool` | `false` |
| `flags` | `array` | `[]` |

`mask` is applied when a file-backed database is created. `flags` are merged into the default PDO driver options.

## Selecting a connection

Use a connection key to select which stored config to use. When no key is provided, `ConnectionManager::DEFAULT` (`default`) is used.

If the key does not exist, `use()` will fail when it tries to build a connection from the missing config.

```php
use Fyre\DB\ConnectionManager;

$connections = app(ConnectionManager::class);

$default = $connections->use();
$analytics = $connections->use('analytics');
```

You can resolve connections by key directly:

```php
$default = db();
$analytics = db('analytics');
```

If you use contextual injection, `#[DB('default')]` can resolve a configured connection while the container is building an object or calling a callable; see [Contextual attributes](../core/contextual-attributes.md).

## Building one-off connections

Use `build()` to construct a connection directly from options without storing it under a key (and without sharing it). The options must include a valid `className` that extends `Fyre\DB\Connection`.

```php
use Fyre\DB\Handlers\Postgres\PostgresConnection;

$temp = $connections->build([
    'className' => PostgresConnection::class,
    'host' => '127.0.0.1',
    'username' => 'app',
    'password' => 'secret',
    'database' => 'temp',
]);
```

## Managing connections

`ConnectionManager` keeps configuration and loaded instances separate. The first call to `use($key)` builds a connection; later calls return that same instance.

| Method | Purpose |
| --- | --- |
| `getConfig($key = null)` | read one configuration, or all configurations |
| `hasConfig($key = 'default')` | check whether a configuration exists |
| `isLoaded($key = 'default')` | check whether a connection has been built |
| `setConfig($key, $options)` | add a runtime configuration |
| `alias($source, $alias)` | redirect a name to a configured connection before the original connection is loaded |
| `dropAlias($alias)` | remove a name redirection |
| `getAliases()` | read name redirections and their configured targets |
| `unload($key = 'default')` | remove a configuration and its loaded connection |
| `clear()` | remove every configuration, loaded connection, and alias |

`setConfig()` throws when the key already exists. Unload the existing entry before replacing it.

`use()` and `isLoaded()` resolve aliases. Configuration methods and `unload()` use the original configured names. Alias chains are not supported. For test bootstrap mappings, see [Test configuration](../testing/configuration.md).

## Running queries

Once you have a `Connection`, use its query builders for normal reads and writes:

```php
$users = $db->select(['id', 'email'])
    ->from('users')
    ->where(['active' => true])
    ->execute()
    ->all();
```

The connection provides builders for select, insert, update, delete, upsert, insert-from-select, and batch-update statements. See [Database queries](queries.md) for their composition, binding, and result APIs.

You can also run SQL directly on the connection when needed:

- `execute($sql, $params)` for parameterized SQL with bound values
- `query($sql)` for direct SQL that should return a normal `ResultSet`

Prefer bound values wherever possible. Query builders bind values by default (via `Query::execute()`), while raw SQL fragments bypass binding:

- Use query builder methods and condition arrays for parameterized values (see [Binding and expressions](queries.md#binding-and-expressions)).
- Use `Connection::execute()` when you need direct SQL with bound parameters.
- Use `Connection::query()` when you need to run direct SQL and want the normal `ResultSet` wrapper.
- Use `Query::literal()` only for safe, deliberate SQL fragments (like functions or column expressions).
- Use `Connection::rawQuery()` only when you specifically need the underlying `PDOStatement`.
- Avoid embedding user input into literals or raw snippets; see [Raw SQL fragments](queries.md#raw-sql-fragments).

## Transactions

Use `transactional()` for work that should commit together. An exception rolls the transaction back and is rethrown; returning `false` rolls it back and returns `false`.

```php
use Fyre\DB\Connection;

$saved = $db->transactional(static function(Connection $db): bool {
    $db->insert()
        ->into('audit_log')
        ->values([['event' => 'user.created']])
        ->execute();

    return true;
});
```

For manual control, use `begin()`, `commit()`, and `rollback()`. Nested transactions use savepoints, and `getSavePointLevel()` returns the current nesting level.

`afterCommit($callback, $priority = 1, $key = null)` schedules work after the outermost commit. When no transaction is active, the callback runs immediately. A key can be used to replace an already queued callback. Rolling back a savepoint restores any callback it replaced from an enclosing transaction.

`afterRollback($callback)` schedules cleanup for the current transaction or savepoint. Callbacks run in reverse registration order when that work is rolled back. Releasing a savepoint carries its callbacks into the enclosing transaction; committing the outermost transaction discards them. Without an active transaction, registration is a no-op. Callback exceptions are ignored, as with queued `afterCommit()` callbacks.

## Connection retries

Query execution recognizes a small set of driver-specific connection errors. Outside a transaction, the connection waits `100` milliseconds, reconnects, and retries the operation once. Queries are not retried while a transaction or savepoint is active because reconnecting would lose its state.

This behavior applies to `execute()` and `rawQuery()`, including normal query-builder execution. The initial connection attempt made during construction is not retried.

## Database locks

Use `Connection::lock()` when work must not run concurrently for the same name. Initialize lock storage once for each connection that uses locks outside the migration runner:

```bash
app db:lock:setup --db=default
```

Then acquire the lease and always release it in a `finally` block:

```php
$lock = db()->lock('daily-report', 60);

if ($lock->acquire(5)) {
    try {
        // Complete the protected work within the 60-second lease.
    } finally {
        $lock->release();
    }
}
```

The second argument to `lock()` is the lease lifetime in seconds and defaults to `300`. The argument to `acquire()` is the maximum time to wait and defaults to `0`, which fails immediately when another owner holds the name. Locks with different names do not block each other.

Call `refresh()` and check its return value before long-running work exceeds the lease. Only the current owner can refresh or release an active lock, and both methods return whether the operation succeeded.

`Lock::acquire()` never performs DDL. The migration runner initializes lock storage before an actual migrate or rollback operation, but planning, status, and dry runs do not. Expired rows no longer block acquisition but remain stored until `app db:lock:purge --db=default` removes them. Run that command manually or on a schedule; see [Database commands](migrations.md#database-commands).

## Connection utilities

| Method | Purpose |
| --- | --- |
| `affectedRows()` | get the affected row count from the latest statement |
| `insertId()` | get the last inserted ID from PDO |
| `getConfig()` | inspect this connection's merged configuration |
| `inTransaction()` | check whether a transaction is active |
| `getCharset()` / `setCharset($charset)` | read or change the connection charset |
| `version()` | get the database server version |
| `disconnect()` | close the PDO connection |
| `enableQueryLogging()` / `disableQueryLogging()` | control logging for this instance |
| `enableForeignKeys()` / `disableForeignKeys()` | control foreign-key checks |
| `truncate($tableName)` | truncate a table using driver-specific SQL |

`MysqlConnection::getCollation()` returns the current MySQL collation. `PostgresConnection::getSchema()` reads the configured schema and `setSchema($schema)` changes its search path.

## Troubleshooting

Common issues when setting up connections:

- If you get an `InvalidArgumentException` when calling `use()` / `build()`, make sure your connection config includes a valid `className` that extends `Fyre\DB\Connection` (see [Connection configuration](#connection-configuration)).
- If you call `use()` with a key that has not been configured, connection creation will fail because there is no stored config for that key.
- If a connection fails immediately on `use()`, connection handlers call `connect()` during construction, so network/credential/database-name errors surface as soon as you resolve the connection. Double-check `host`, `port`, `username`, `password`, and `database`.
- If SQLite reports “unable to open database file”, verify the directory for your SQLite file exists and is writable by the PHP process. Use an absolute path if your working directory differs between environments.
- If query logging is enabled but nothing is written, `log: true` emits debug-level logs with the `queries` scope. Ensure your logger is configured for `debug` and includes the `queries` scope (see the example under [Example configuration](#example-configuration)).

## Behavior notes

- Connection handlers connect during construction, so credential, host, and database-name errors usually surface as soon as you call `use()` or `build()`.
- Building fails when a config is missing `className` or `className` does not extend `Fyre\DB\Connection`.
- For file-backed SQLite databases, the handler applies `mask` when creating a new database file.

## Related

- [Database](index.md)
- [Config](../core/config.md)
- [Helpers](../core/helpers.md)
- [Contextual attributes](../core/contextual-attributes.md)
- [Logging](../logging/index.md)
- [Events](../events/index.md)
- [Database queries](queries.md)
- [Schema](schema.md)
- [Forge](forge.md)
- [Database migrations](migrations.md)
- [Database types](types.md)
