<?php
declare(strict_types=1);

namespace Fyre\DB;

use Closure;
use Fyre\Core\Container;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\DB\Exceptions\DbException;
use Fyre\DB\Exceptions\MissingConnectionException;
use Fyre\DB\Expressions\LiteralExpression;
use Fyre\DB\Queries\DeleteQuery;
use Fyre\DB\Queries\InsertFromQuery;
use Fyre\DB\Queries\InsertQuery;
use Fyre\DB\Queries\SelectQuery;
use Fyre\DB\Queries\UpdateBatchQuery;
use Fyre\DB\Queries\UpdateQuery;
use Fyre\DB\Queries\UpsertQuery;
use Fyre\Event\EventManager;
use Fyre\Event\Traits\EventDispatcherTrait;
use Fyre\Log\LogManager;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

use function array_filter;
use function array_is_list;
use function array_keys;
use function array_map;
use function array_replace_recursive;
use function array_reverse;
use function explode;
use function filter_var;
use function implode;
use function is_bool;
use function is_int;
use function is_resource;
use function is_string;
use function min;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function sprintf;
use function str_replace;
use function trim;
use function usort;

use const FILTER_VALIDATE_FLOAT;

/**
 * Provides a base database connection wrapper around PDO.
 *
 * Provides query-builder factories, transaction/savepoint handling, optional query logging,
 * and automatic retry/reconnect support for transient connection errors.
 */
abstract class Connection
{
    use DebugTrait;
    use EventDispatcherTrait;
    use MacroTrait;

    /**
     * @var array<string, mixed>
     */
    protected static array $defaults = [
        'log' => false,
    ];

    /**
     * @var string[]
     */
    protected static array $lockConstraintErrorCodes = [
        '23000', // integrity constraint violation
    ];

    /**
     * @var int[]
     */
    protected static array $retryDriverCodes = [];

    /**
     * @var string[]
     */
    protected static array $retryErrorCodes = [];

    protected int|null $affectedRows = null;

    /**
     * @var array<array<string, mixed>>
     */
    protected array $afterCommitCallbacks = [];

    /**
     * @var array<array{callback: Closure, savePointLevel: int}>
     */
    protected array $afterRollbackCallbacks = [];

    /**
     * @var array<string, mixed>
     */
    protected array $config;

    protected QueryGenerator $generator;

    protected bool $inTransaction = false;

    protected bool $logQueries = false;

    protected PDO|null $pdo = null;

    protected ConnectionRetry $retry;

    protected int $savePointLevel = 0;

    protected bool $useSavePoints = true;

    protected string|null $version = null;

    /**
     * Constructs a Connection.
     *
     * @param Container $container The Container.
     * @param EventManager $eventManager The EventManager.
     * @param LogManager $logManager The LogManager.
     * @param array<string, mixed> $options The options for the handler.
     */
    public function __construct(
        protected Container $container,
        protected EventManager $eventManager,
        protected LogManager $logManager,
        array $options = []
    ) {
        $this->config = array_replace_recursive(self::$defaults, static::$defaults, $options);
        $this->logQueries = $this->config['log'];

        $this->connect();
    }

    /**
     * Destructs the Connection.
     */
    public function __destruct()
    {
        if ($this->inTransaction) {
            $this->logManager->handle('warning', 'Connection closing while a transaction is in process.');
        }

        $this->disconnect();
    }

    /**
     * Returns the number of affected rows.
     *
     * @return int|null The number of affected rows.
     */
    public function affectedRows(): int|null
    {
        return $this->affectedRows;
    }

    /**
     * Queues a callback to execute after the transaction is committed.
     *
     * Note: If no transaction is active, the callback is executed immediately.
     *
     * @param Closure $callback The callback.
     * @param int $priority The callback priority.
     * @param string|null $key The callback key.
     * @return static The Connection instance.
     */
    public function afterCommit(Closure $callback, int $priority = 1, string|null $key = null): static
    {
        if (!$this->savePointLevel) {
            $callback();
        } else {
            $data = [
                'callback' => $callback,
                'priority' => $priority,
                'savePointLevel' => $this->savePointLevel,
            ];

            if ($key === null) {
                $this->afterCommitCallbacks[] = $data;
            } else {
                $previous = $this->afterCommitCallbacks[$key] ?? null;

                if ($previous && $previous['savePointLevel'] < $this->savePointLevel) {
                    $this->afterRollback(function() use ($key, $previous): void {
                        if ($previous['savePointLevel'] <= $this->savePointLevel) {
                            $this->afterCommitCallbacks[$key] = $previous;
                        }
                    });
                }

                $this->afterCommitCallbacks[$key] = $data;
            }
        }

        return $this;
    }

    /**
     * Queues a callback to execute after its transaction or savepoint is rolled back.
     *
     * Callbacks run in reverse registration order. Releasing a savepoint retains its
     * callbacks for the enclosing transaction. Without an active transaction, this is a no-op.
     * Callback exceptions are ignored.
     *
     * @param Closure $callback The callback.
     * @return static The Connection instance.
     */
    public function afterRollback(Closure $callback): static
    {
        if ($this->savePointLevel) {
            $this->afterRollbackCallbacks[] = [
                'callback' => $callback,
                'savePointLevel' => $this->savePointLevel,
            ];
        }

        return $this;
    }

    /**
     * Begins a transaction.
     *
     * @return static The Connection instance.
     */
    public function begin(): static
    {
        if ($this->savePointLevel === 0) {
            $this->transBegin();
        } else if ($this->useSavePoints) {
            $this->transSavepoint($this->savePointLevel);
        }

        $this->savePointLevel++;

        return $this;
    }

    /**
     * Commits a transaction.
     *
     * Note: Callbacks registered via {@see Connection::afterCommit()} are only executed after the
     * outermost transaction is committed. Callback exceptions are ignored.
     *
     * @return static The Connection instance.
     */
    public function commit(): static
    {
        if (!$this->savePointLevel) {
            return $this;
        }

        if ($this->savePointLevel === 1) {
            $this->transCommit();
        } else if ($this->useSavePoints) {
            $this->transRelease($this->savePointLevel - 1);
        }

        $this->savePointLevel--;

        if ($this->savePointLevel === 0) {
            $callbacks = $this->afterCommitCallbacks;

            $this->afterCommitCallbacks = [];
            $this->afterRollbackCallbacks = [];

            usort($callbacks, static fn(array $a, $b): int => $a['priority'] <=> $b['priority']);

            foreach ($callbacks as $callback) {
                try {
                    $callback['callback']();
                } catch (Throwable $e) {
                }
            }
        } else {
            $this->afterCommitCallbacks = array_map(
                function(array $afterCommitCallback): array {
                    $afterCommitCallback['savePointLevel'] = min($afterCommitCallback['savePointLevel'], $this->savePointLevel);

                    return $afterCommitCallback;
                },
                $this->afterCommitCallbacks
            );

            $this->afterRollbackCallbacks = array_map(
                function(array $afterRollbackCallback): array {
                    $afterRollbackCallback['savePointLevel'] = min($afterRollbackCallback['savePointLevel'], $this->savePointLevel);

                    return $afterRollbackCallback;
                },
                $this->afterRollbackCallbacks
            );
        }

        return $this;
    }

    /**
     * Connects to the database.
     */
    abstract public function connect(): void;

    /**
     * Creates a DeleteQuery.
     *
     * @param string|string[] $alias The alias to delete.
     * @return DeleteQuery The new DeleteQuery instance.
     */
    public function delete(array|string $alias = []): DeleteQuery
    {
        return new DeleteQuery($this, $alias);
    }

    /**
     * Disables foreign key checks.
     *
     * @return static The Connection instance.
     */
    abstract public function disableForeignKeys(): static;

    /**
     * Disables query logging.
     *
     * @return static The Connection instance.
     */
    public function disableQueryLogging(): static
    {
        $this->logQueries = false;

        return $this;
    }

    /**
     * Disconnects from the database.
     *
     * @return bool Whether the connection was disconnected.
     */
    public function disconnect(): bool
    {
        $this->pdo = null;

        return true;
    }

    /**
     * Enables foreign key checks.
     *
     * @return static The Connection instance.
     */
    abstract public function enableForeignKeys(): static;

    /**
     * Enables query logging.
     *
     * @return static The Connection instance.
     */
    public function enableQueryLogging(): static
    {
        $this->logQueries = true;

        return $this;
    }

    /**
     * Executes a SQL query with bound parameters.
     *
     * @param string $sql The SQL query.
     * @param array<int|string, mixed> $params The parameters to bind.
     * @param bool $retrySafe Whether execution can be repeated after a disconnect.
     * @return ResultSet The new ResultSet instance.
     *
     * @throws MissingConnectionException If the connection is not valid.
     * @throws DbException If the query threw an error.
     */
    public function execute(string $sql, array $params, bool $retrySafe = false): ResultSet
    {
        try {
            $prepare = function() use ($sql, $params): PDOStatement {
                if (!$this->pdo) {
                    throw new MissingConnectionException();
                }

                $this->dispatchEvent('Db.query', ['sql' => $sql, 'params' => $params]);

                if ($this->logQueries) {
                    $logMessage = $sql;

                    if ($params !== []) {
                        $logParams = array_map(function(mixed $value): string {
                            if ($value === null) {
                                return 'NULL';
                            }

                            if ($value === false) {
                                return 'FALSE';
                            }

                            if ($value === true) {
                                return 'TRUE';
                            }

                            $value = (string) $value;

                            if (filter_var($value, FILTER_VALIDATE_FLOAT) !== false) {
                                return $value;
                            }

                            return $this->quote($value);
                        }, $params);

                        $logKeys = array_map(
                            static fn(int|string $key): string => is_string($key) ?
                                '/:'.preg_quote($key, '/').'\b/' :
                                '/[?]/',
                            array_keys($params)
                        );

                        $limit = array_is_list($params) ? 1 : -1;
                        $logMessage = preg_replace($logKeys, $logParams, $logMessage, $limit) ?? $sql;
                    }

                    $this->logManager->handle('debug', $logMessage, scope: 'queries');
                }

                return $this->pdo->prepare($sql) ?: throw new DbException('Failed to prepare the statement.');
            };

            $execute = function(PDOStatement $query) use ($params): ResultSet {
                if (array_is_list($params)) {
                    $query->execute($params);
                } else {
                    foreach ($params as $param => $value) {
                        if (is_resource($value)) {
                            $type = PDO::PARAM_LOB;
                        } else if (is_int($value)) {
                            $type = PDO::PARAM_INT;
                        } else if (is_bool($value)) {
                            $type = PDO::PARAM_BOOL;
                        } else if ($value === null) {
                            $type = PDO::PARAM_NULL;
                        } else {
                            $type = PDO::PARAM_STR;
                        }

                        $query->bindValue($param, $value, $type);
                    }

                    $query->execute();
                }

                return $this->result($query);
            };

            if ($retrySafe) {
                return $this->retry()->run(static fn(): ResultSet => $prepare() |> $execute);
            }

            return $this->retry()->run($prepare) |> $execute;
        } catch (PDOException $e) {
            throw new DbException(sprintf(
                'Database connection error: %s',
                $e->getMessage()
            ), previous: $e);
        }
    }

    /**
     * Returns the query generator.
     *
     * @return QueryGenerator The QueryGenerator instance.
     */
    abstract public function generator(): QueryGenerator;

    /**
     * Returns the connection charset.
     *
     * @return string The connection charset.
     */
    abstract public function getCharset(): string;

    /**
     * Returns the config.
     *
     * @return array<string, mixed> The config.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Returns the last connection error.
     *
     * @return string The last connection error.
     *
     * @throws MissingConnectionException If the connection is not valid.
     */
    public function getError(): string
    {
        if (!$this->pdo) {
            throw new MissingConnectionException();
        }

        $info = $this->pdo->errorInfo();

        return implode(' ', $info);
    }

    /**
     * Returns the transaction save point level.
     *
     * @return int The transaction save point level.
     */
    public function getSavePointLevel(): int
    {
        return $this->savePointLevel;
    }

    /**
     * Creates an InsertQuery.
     *
     * @return InsertQuery The new InsertQuery instance.
     */
    public function insert(): InsertQuery
    {
        return new InsertQuery($this);
    }

    /**
     * Creates an InsertFromQuery.
     *
     * @param Closure|LiteralExpression|SelectQuery|string $from The query.
     * @param string[] $columns The columns.
     * @return InsertFromQuery The new InsertFromQuery instance.
     */
    public function insertFrom(Closure|LiteralExpression|SelectQuery|string $from, array $columns = []): InsertFromQuery
    {
        return new InsertFromQuery($this, $from, $columns);
    }

    /**
     * Returns the last inserted ID.
     *
     * @return int|null The last inserted ID.
     *
     * @throws MissingConnectionException If the connection is not valid.
     */
    public function insertId(): int|null
    {
        if (!$this->pdo) {
            throw new MissingConnectionException();
        }

        $lastId = $this->pdo->lastInsertId();

        if ($lastId === false) {
            return null;
        }

        return (int) $lastId;
    }

    /**
     * Checks whether a transaction is in progress.
     *
     * @return bool Whether a transaction is in progress.
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Creates a named database lock.
     *
     * @param string $name The lock name (up to 255 characters).
     * @param int $expires The lock lifetime in seconds.
     * @return Lock The new Lock instance.
     *
     * @throws InvalidArgumentException If the name or expiration is not valid.
     */
    public function lock(string $name, int $expires = 300): Lock
    {
        return $this->container->build(Lock::class, [
            'connection' => $this,
            'name' => $name,
            'expires' => $expires,
            'constraintErrorCodes' => static::$lockConstraintErrorCodes,
        ]);
    }

    /**
     * Executes a SQL query.
     *
     * @param string $sql The SQL query.
     * @param bool $retrySafe Whether execution can be repeated after a disconnect.
     * @return ResultSet The new ResultSet instance.
     */
    public function query(string $sql, bool $retrySafe = false): ResultSet
    {
        return $this->rawQuery($sql, $retrySafe) |> $this->result(...);
    }

    /**
     * Quotes a string for use in SQL queries.
     *
     * @param string $value The value to quote.
     * @return string The quoted value.
     *
     * @throws MissingConnectionException If the connection is not valid.
     */
    public function quote(string $value): string
    {
        if (!$this->pdo) {
            throw new MissingConnectionException();
        }

        return $this->pdo->quote($value);
    }

    /**
     * Quotes an identifier for use in SQL queries.
     *
     * @param string $identifier The identifier to quote.
     * @param bool $allowAlias Whether to allow an alias.
     * @return string The quoted identifier.
     */
    public function quoteIdentifier(string $identifier, bool $allowAlias = true): string
    {
        $identifier = trim($identifier);

        if ($identifier === '' || $identifier === '*') {
            return $identifier;
        }

        // identifier
        if (preg_match('/\A[a-z_]\w*(?:\.[a-z_]\w*)*(?:\.\*)?\z/i', $identifier)) {
            $parts = explode('.', $identifier);
            $parts = array_map(
                fn(string $part): string => $part === '*' ?
                    $part :
                    $this->quoteIdentifierPart($part),
                $parts
            );

            return implode('.', $parts);
        }

        // function(...)
        if (preg_match('/\A([a-z_]\w*(?:\.[a-z_]\w*)*)\((.*)\)\z/i', $identifier, $matches)) {
            return $matches[1].'('.$this->quoteIdentifier($matches[2], false).')';
        }

        if (!$allowAlias) {
            return $identifier;
        }

        // identifier AS alias
        if (preg_match('/\A([a-z_]\w*(?:\.[a-z_]\w*)*)\s+AS\s+([a-z_]\w*)\z/i', $identifier, $matches)) {
            return $this->quoteIdentifier($matches[1], false).' AS '.$this->quoteIdentifier($matches[2], false);
        }

        // function(...) AS alias
        if (preg_match('/\A([a-z_]\w*(?:\.[a-z_]\w*)*\(.*\))\s+AS\s+([a-z_]\w*)\z/i', $identifier, $matches)) {
            return $this->quoteIdentifier($matches[1], false).' AS '.$this->quoteIdentifier($matches[2], false);
        }

        return $identifier;
    }

    /**
     * Quotes an identifier part for use in SQL queries.
     *
     * @param string $identifier The identifier part to quote.
     * @return string The quoted identifier part.
     */
    public function quoteIdentifierPart(string $identifier): string
    {
        $identifier = str_replace('"', '""', $identifier);

        return '"'.$identifier.'"';
    }

    /**
     * Executes a raw SQL query.
     *
     * @param string $sql The SQL query.
     * @param bool $retrySafe Whether execution can be repeated after a disconnect.
     * @return PDOStatement The PDOStatement instance containing the raw result.
     *
     * @throws MissingConnectionException If the connection is not valid.
     * @throws DbException If the query threw an error.
     */
    public function rawQuery(string $sql, bool $retrySafe = false): PDOStatement
    {
        try {
            $execute = function() use ($sql): PDOStatement {
                if (!$this->pdo) {
                    throw new MissingConnectionException();
                }

                $this->dispatchEvent('Db.query', ['sql' => $sql]);

                if ($this->logQueries) {
                    $this->logManager->handle('debug', $sql, scope: 'queries');
                }

                return $this->pdo->query($sql) ?: throw new DbException('Failed to execute the query.');
            };

            return $retrySafe ? $this->retry()->run($execute) : $execute();
        } catch (PDOException $e) {
            throw new DbException(sprintf(
                'Database error: %s',
                $e->getMessage()
            ), previous: $e);
        }
    }

    /**
     * Rolls back a transaction.
     *
     * @return static The Connection instance.
     */
    public function rollback(): static
    {
        if (!$this->savePointLevel) {
            return $this;
        }

        if ($this->savePointLevel === 1) {
            $this->transRollback();
        } else if ($this->useSavePoints) {
            $this->transRollbackTo($this->savePointLevel - 1);
        }

        $this->savePointLevel--;

        if ($this->savePointLevel === 0) {
            $this->afterCommitCallbacks = [];
        } else {
            $this->afterCommitCallbacks = array_filter(
                $this->afterCommitCallbacks,
                fn(array $callback): bool => $callback['savePointLevel'] <= $this->savePointLevel
            );
        }

        $callbacks = array_filter(
            $this->afterRollbackCallbacks,
            fn(array $callback): bool => $callback['savePointLevel'] > $this->savePointLevel
        ) |> array_reverse(...);

        $this->afterRollbackCallbacks = array_filter(
            $this->afterRollbackCallbacks,
            fn(array $callback): bool => $callback['savePointLevel'] <= $this->savePointLevel
        );

        foreach ($callbacks as $callback) {
            try {
                $callback['callback']();
            } catch (Throwable $e) {
            }
        }

        return $this;
    }

    /**
     * Creates a SelectQuery.
     *
     * @param array<mixed>|string $fields The fields.
     * @return SelectQuery<array<string, mixed>> The new SelectQuery instance.
     */
    public function select(array|string $fields = '*'): SelectQuery
    {
        return new SelectQuery($this, $fields);
    }

    /**
     * Sets the connection charset.
     *
     * @param string $charset The charset.
     * @return static The Connection instance.
     */
    public function setCharset(string $charset): static
    {
        $this->rawQuery('SET NAMES '.$this->quote($charset));

        return $this;
    }

    /**
     * Checks whether the connection supports a feature.
     *
     * @param DbFeature $feature The DB feature.
     * @return bool Whether the connection supports the feature.
     */
    abstract public function supports(DbFeature $feature): bool;

    /**
     * Executes a callback inside a database transaction.
     *
     * The callback is invoked with this connection as the first argument.
     * If the callback returns `false`, the transaction is rolled back.
     *
     * @param Closure(Connection): mixed $callback The callback.
     * @return bool Whether the transaction was successful.
     *
     * @throws Throwable If the callback or a transaction operation throws an exception.
     */
    public function transactional(Closure $callback): bool
    {
        $this->begin();
        $rollback = true;

        try {
            if ($callback($this) === false) {
                return false;
            }

            $this->commit();
            $rollback = false;
        } finally {
            if ($rollback) {
                $this->rollback();
            }
        }

        return true;
    }

    /**
     * Truncates a table.
     *
     * @param string $tableName The table name.
     * @return static The Connection instance.
     */
    abstract public function truncate(string $tableName): static;

    /**
     * Creates an UpdateQuery.
     *
     * @param array<mixed>|string|null $table The table.
     * @return UpdateQuery The new UpdateQuery instance.
     */
    public function update(array|string|null $table = null): UpdateQuery
    {
        return new UpdateQuery($this, $table);
    }

    /**
     * Creates an UpdateBatchQuery.
     *
     * @param string|null $table The table.
     * @return UpdateBatchQuery The new UpdateBatchQuery instance.
     */
    public function updateBatch(string|null $table = null): UpdateBatchQuery
    {
        return new UpdateBatchQuery($this, $table);
    }

    /**
     * Creates an UpsertQuery.
     *
     * @param string|string[] $conflictKeys The conflict keys.
     * @return UpsertQuery The new UpsertQuery instance.
     */
    public function upsert(array|string $conflictKeys): UpsertQuery
    {
        return new UpsertQuery($this, $conflictKeys);
    }

    /**
     * Returns the server version.
     *
     * @return string The server version.
     *
     * @throws MissingConnectionException If the connection is not valid.
     */
    public function version(): string
    {
        if ($this->version === null) {
            if (!$this->pdo) {
                throw new MissingConnectionException();
            }

            $this->version = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        }

        return (string) $this->version;
    }

    /**
     * Generates a result set from a raw result.
     *
     * @param PDOStatement $result The raw result.
     * @return ResultSet The new ResultSet instance.
     */
    protected function result(PDOStatement $result): ResultSet
    {
        $this->affectedRows = $result->rowCount();

        /** @var class-string<StatementResultSet> $className */
        $className = static::resultSetClass();

        return $this->container->build($className, ['result' => $result]);
    }

    /**
     * Returns the ResultSet class.
     *
     * @return class-string<StatementResultSet> The ResultSet class.
     */
    abstract protected static function resultSetClass(): string;

    /**
     * Returns the ConnectionRetry.
     *
     * @return ConnectionRetry The ConnectionRetry instance.
     */
    protected function retry(): ConnectionRetry
    {
        return $this->retry ??= new ConnectionRetry(
            $this,
            errorCodes: static::$retryErrorCodes,
            driverCodes: static::$retryDriverCodes
        );
    }

    /**
     * Begins a transaction.
     */
    protected function transBegin(): void
    {
        $this->rawQuery('BEGIN');
        $this->inTransaction = true;
    }

    /**
     * Commits a transaction.
     */
    protected function transCommit(): void
    {
        $this->rawQuery('COMMIT');
        $this->inTransaction = false;
    }

    /**
     * Releases a transaction savepoint.
     *
     * @param int $savePoint The save point name.
     */
    protected function transRelease(int $savePoint): void
    {
        $this->rawQuery('RELEASE SAVEPOINT sp_'.$savePoint);
    }

    /**
     * Rolls back a transaction.
     */
    protected function transRollback(): void
    {
        $this->rawQuery('ROLLBACK');
        $this->inTransaction = false;
    }

    /**
     * Rolls back to a transaction savepoint.
     *
     * @param int $savePoint The save point name.
     */
    protected function transRollbackTo(int $savePoint): void
    {
        $this->rawQuery('ROLLBACK TO SAVEPOINT sp_'.$savePoint);
    }

    /**
     * Saves a transaction save point.
     *
     * @param int $savePoint The save point name.
     */
    protected function transSavepoint(int $savePoint): void
    {
        $this->rawQuery('SAVEPOINT sp_'.$savePoint);
    }
}
