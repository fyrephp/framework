<?php
declare(strict_types=1);

namespace Fyre\DB;

use Fyre\Core\Traits\DebugTrait;
use Fyre\DB\Exceptions\DbException;
use Fyre\DB\Expressions\CaseExpression;
use Fyre\DB\Expressions\ConditionExpression;
use Fyre\DB\Expressions\FunctionBuilder;
use Fyre\DB\Expressions\IdentifierExpression;
use Fyre\DB\Expressions\LiteralExpression;
use Fyre\DB\Queries\Traits\HintTrait;
use Override;
use Stringable;

use function array_is_list;
use function array_merge;
use function count;
use function is_string;

/**
 * Provides a base query builder.
 *
 * Provides table management, dirty state tracking, and execution via a {@see Connection}.
 */
abstract class Query implements Stringable
{
    use DebugTrait;
    use HintTrait;

    protected static bool $tableAliases = false;

    protected static bool $virtualTables = false;

    protected bool $dirty = false;

    protected FunctionBuilder $functionBuilder;

    protected bool $multipleTables = false;

    /**
     * @var array<mixed>
     */
    protected array $table = [];

    protected bool $useBinder = true;

    /**
     * Constructs a Query.
     *
     * @param Connection $connection The Connection.
     * @param array<mixed>|string|null $table The table.
     */
    public function __construct(
        protected Connection $connection,
        array|string|null $table = null
    ) {
        if ($table) {
            $this->table($table);
        }
    }

    /**
     * Generates the SQL query.
     *
     * @return string The SQL query.
     */
    #[Override]
    public function __toString(): string
    {
        return $this->sql();
    }

    /**
     * Creates a CaseExpression.
     *
     * @param mixed $value The case value.
     * @return CaseExpression The new CaseExpression instance.
     */
    public function case(mixed $value = null): CaseExpression
    {
        return new CaseExpression($value);
    }

    /**
     * Executes the query.
     *
     * Note: When binding is enabled, a {@see ValueBinder} is created if none is provided.
     * A successful execution resets the query dirty state.
     *
     * @param ValueBinder|null $binder The ValueBinder to use.
     * @param bool $retrySafe Whether execution can be repeated after a disconnect.
     * @return ResultSet The new ResultSet instance.
     *
     * @throws Exceptions\MissingConnectionException If the connection is not valid.
     * @throws DbException If the query threw an error.
     */
    public function execute(ValueBinder|null $binder = null, bool $retrySafe = false): ResultSet
    {
        if ($this->useBinder) {
            $binder ??= new ValueBinder();
        }

        $query = $this->sql($binder);

        $bindings = $binder ?
            $binder->bindings() :
            [];

        $result = $this->connection->execute($query, $bindings, $retrySafe);

        $this->dirty = false;

        return $result;
    }

    /**
     * Creates a condition expression.
     *
     * @param string $conjunction The condition conjunction.
     * @return ConditionExpression The new ConditionExpression instance.
     */
    public function expr(string $conjunction = 'AND'): ConditionExpression
    {
        return new ConditionExpression($conjunction);
    }

    /**
     * Returns the FunctionBuilder.
     *
     * @return FunctionBuilder The FunctionBuilder instance.
     */
    public function func(): FunctionBuilder
    {
        return $this->functionBuilder ??= new FunctionBuilder();
    }

    /**
     * Returns the Connection.
     *
     * @return Connection The Connection instance.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Returns the table.
     *
     * @return array<mixed> The table.
     */
    public function getTable(): array
    {
        return $this->table;
    }

    /**
     * Creates an IdentifierExpression.
     *
     * @param string $identifier The identifier.
     * @return IdentifierExpression The new IdentifierExpression instance.
     */
    public function identifier(string $identifier): IdentifierExpression
    {
        return new IdentifierExpression($identifier);
    }

    /**
     * Creates a LiteralExpression.
     *
     * @param string $string The literal string.
     * @return LiteralExpression The new LiteralExpression instance.
     */
    public function literal(string $string): LiteralExpression
    {
        return new LiteralExpression($string);
    }

    /**
     * Generates the SQL query.
     *
     * @return string The SQL query.
     */
    abstract public function sql(ValueBinder|null $binder = null): string;

    /**
     * Sets the table.
     *
     * @param array<mixed>|string $table The table.
     * @param bool $overwrite Whether to overwrite the existing table.
     * @return static The Query instance.
     *
     * @throws DbException If the table is not valid for the query.
     */
    public function table(array|string $table, bool $overwrite = false): static
    {
        $table = (array) $table;

        if (!static::$virtualTables) {
            foreach ($table as $test) {
                if (!is_string($test)) {
                    throw new DbException('Virtual tables are not supported for this query.');
                }
            }
        }

        if (!static::$tableAliases && !array_is_list($table)) {
            throw new DbException('Table aliases are not supported for this query.');
        }

        if ($overwrite) {
            $this->table = $table;
        } else {
            $this->table = array_merge($this->table, $table);
        }

        if (!$this->multipleTables && count($this->table) > 1) {
            throw new DbException('Multiple tables are not supported for this query.');
        }

        $this->dirty();

        return $this;
    }

    /**
     * Marks the query as dirty.
     */
    protected function dirty(): void
    {
        $this->dirty = true;
    }
}
