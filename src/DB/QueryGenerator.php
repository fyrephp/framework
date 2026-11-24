<?php
declare(strict_types=1);

namespace Fyre\DB;

use Closure;
use Fyre\Core\Traits\DebugTrait;
use Fyre\DB\Expressions\AggregateExpression;
use Fyre\DB\Expressions\CaseExpression;
use Fyre\DB\Expressions\ConditionExpression;
use Fyre\DB\Expressions\FunctionExpression;
use Fyre\DB\Expressions\IdentifierExpression;
use Fyre\DB\Expressions\LiteralExpression;
use Fyre\DB\Expressions\ValueExpressionInterface;
use Fyre\DB\Expressions\WindowExpression;
use Fyre\DB\Queries\DeleteQuery;
use Fyre\DB\Queries\InsertFromQuery;
use Fyre\DB\Queries\InsertQuery;
use Fyre\DB\Queries\SelectQuery;
use Fyre\DB\Queries\UpdateBatchQuery;
use Fyre\DB\Queries\UpdateQuery;
use Fyre\DB\Queries\UpsertQuery;
use Fyre\Utility\DateTime\AbstractDateTime;
use Fyre\Utility\DateTime\Date;
use Fyre\Utility\DateTime\DateTime;
use Fyre\Utility\DateTime\Time;
use Fyre\Utility\EnumHelper;
use InvalidArgumentException;
use UnitEnum;

use function array_any;
use function array_diff_key;
use function array_filter;
use function array_first;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function assert;
use function count;
use function filter_var;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_match;
use function preg_replace;
use function sprintf;
use function strtolower;
use function strtoupper;
use function trim;

use const FILTER_VALIDATE_FLOAT;

/**
 * SQL compiler for query builder objects.
 *
 * Implementations are database-specific and convert {@see Query} objects into executable SQL
 * strings with optional value binding via {@see ValueBinder}.
 */
abstract class QueryGenerator
{
    use DebugTrait;

    protected Query|null $currentQuery = null;

    /**
     * Combines conditions.
     *
     * @param string[] $fields The fields.
     * @param array<mixed> $values The values.
     * @return ConditionExpression The combined conditions.
     *
     * @throws InvalidArgumentException If the number of fields and values does not match.
     */
    public static function combineConditions(array $fields, array $values): ConditionExpression
    {
        if (count($fields) !== count($values)) {
            throw new InvalidArgumentException('Condition fields and values must contain the same number of elements.');
        }

        $fields = array_values($fields);
        $values = array_values($values);

        $conditions = new ConditionExpression();

        foreach ($fields as $i => $field) {
            $value = $values[$i];

            if ($value === null) {
                $conditions->isNull($field);
            } else {
                $conditions->eq($field, $value);
            }
        }

        return $conditions;
    }

    /**
     * Normalizes conditions.
     *
     * @param string[] $fields The fields.
     * @param array<array<mixed>> $allValues The values.
     * @return ConditionExpression|null The normalized conditions.
     *
     * @throws InvalidArgumentException If the number of fields and values does not match.
     */
    public static function normalizeConditions(array $fields, array $allValues): ConditionExpression|null
    {
        if ($fields === [] || $allValues === []) {
            return null;
        }

        if (count($allValues) === 1) {
            return static::combineConditions($fields, array_first($allValues));
        }

        if (count($fields) > 1) {
            $conditions = new ConditionExpression('OR');

            foreach ($allValues as $values) {
                static::combineConditions($fields, $values) |> $conditions->add(...);
            }

            return $conditions;
        }

        $hasNull = false;
        $values = [];

        foreach ($allValues as $row) {
            if (count($row) !== 1) {
                throw new InvalidArgumentException('Condition fields and values must contain the same number of elements.');
            }

            $value = array_first($row);

            if ($value === null) {
                $hasNull = true;
            } else if (!in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        $valueCount = count($values);

        $field = array_first($fields);

        $conditions = new ConditionExpression('OR');

        if ($valueCount === 1) {
            $conditions->eq($field, array_first($values));
        } else if ($valueCount > 1) {
            $conditions->in($field, $values);
        }

        if ($hasNull) {
            $conditions->isNull($field);
        }

        return $conditions;
    }

    /**
     * Constructs a QueryGenerator.
     *
     * @param Connection $connection The connection.
     * @param TypeParser $typeParser The TypeParser.
     */
    public function __construct(
        protected Connection $connection,
        protected TypeParser $typeParser
    ) {}

    /**
     * Compiles a DeleteQuery to SQL.
     *
     * @param DeleteQuery $query The DeleteQuery.
     * @param ValueBinder|null $binder The ValueBinder.
     * @return string The compiled SQL query.
     */
    public function compileDelete(DeleteQuery $query, ValueBinder|null $binder = null): string
    {
        return $this->withQuery($query, function() use ($query, $binder): string {
            $sql = $this->buildDelete(
                $query->getTable(),
                $query->getAlias(),
                $query->getUsing() ?? [],
                $query->getHints(),
                $binder
            );
            $sql .= $this->buildJoin($query->getJoin(), $binder);
            $sql .= $this->buildWhere($query->getWhere(), $binder);
            $sql .= $this->buildOrderBy($query->getOrderBy());
            $sql .= $this->buildLimit($query->getLimit(), 0);
            $sql .= $this->buildEpilog($query->getEpilog());

            return $sql;
        });
    }

    /**
     * Compiles an InsertQuery to SQL.
     *
     * Note: When the connection supports {@see DbFeature::InsertReturning} and the query has
     * no epilog, this will set the epilog to `RETURNING *`.
     *
     * @param InsertQuery $query The InsertQuery.
     * @param ValueBinder|null $binder The ValueBinder.
     * @return string The compiled SQL query.
     */
    public function compileInsert(InsertQuery $query, ValueBinder|null $binder = null): string
    {
        return $this->withQuery($query, function() use ($query, $binder): string {
            if ($this->connection->supports(DbFeature::InsertReturning) && !$query->getEpilog()) {
                $query->epilog('RETURNING *');
            }

            $sql = $this->buildInsert($query->getTable(), $query->getValues(), $query->getHints(), $binder);
            $sql .= $this->buildEpilog($query->getEpilog());

            return $sql;
        });
    }

    /**
     * Compiles an InsertFromQuery to SQL.
     *
     * Note: When the connection supports {@see DbFeature::InsertReturning} and the query has
     * no epilog, this will set the epilog to `RETURNING *`.
     *
     * @param InsertFromQuery $query The InsertFromQuery.
     * @param ValueBinder|null $binder The ValueBinder.
     * @return string The compiled SQL query.
     */
    public function compileInsertFrom(InsertFromQuery $query, ValueBinder|null $binder = null): string
    {
        return $this->withQuery($query, function() use ($query, $binder): string {
            if ($this->connection->supports(DbFeature::InsertReturning) && !$query->getEpilog()) {
                $query->epilog('RETURNING *');
            }

            $sql = $this->buildInsertFrom(
                $query->getTable(),
                $query->getFrom(),
                $query->getColumns(),
                $query->getHints(),
                $binder
            );
            $sql .= $query->getEpilog() |> $this->buildEpilog(...);

            return $sql;
        });
    }

    /**
     * Compiles a SelectQuery to SQL.
     *
     * @param SelectQuery $query The SelectQuery.
     * @param ValueBinder|null $binder The ValueBinder.
     * @return string The compiled SQL query.
     */
    public function compileSelect(SelectQuery $query, ValueBinder|null $binder = null): string
    {
        return $this->withQuery($query, function() use ($query, $binder): string {
            if ($query->getGroupLimit() !== null) {
                return $this->compileGroupLimit($query, $binder);
            }

            $sql = $this->buildWith($query->getWith(), $binder);
            $sql .= $this->buildSelect(
                $query->getTable(),
                $query->getSelect(),
                $query->getDistinct(),
                $query->getHints(),
                $binder
            );
            $sql .= $this->buildJoin($query->getJoin(), $binder);
            $sql .= $this->buildWhere($query->getWhere(), $binder);

            $unions = $query->getUnion();
            if ($unions !== []) {
                $sql = '('.$sql.')';
                $sql .= $this->buildUnion($unions, $binder);
            }

            $sql .= $this->buildGroupBy($query->getGroupBy());
            $sql .= $this->buildHaving($query->getHaving(), $binder);
            $sql .= $this->buildOrderBy($query->getOrderBy());
            $sql .= $this->buildLimit($query->getLimit(), $query->getOffset());
            $sql .= $this->buildEpilog($query->getEpilog());

            return $sql;
        });
    }

    /**
     * Compiles an UpdateQuery to SQL.
     *
     * @param UpdateQuery $query The UpdateQuery.
     * @param ValueBinder|null $binder The ValueBinder.
     * @return string The compiled SQL query.
     */
    public function compileUpdate(UpdateQuery $query, ValueBinder|null $binder = null): string
    {
        return $this->withQuery($query, function() use ($query, $binder): string {
            $sql = $this->buildUpdate(
                $query->getTable(),
                $query->getData(),
                $query->getFrom() ?? [],
                $query->getHints(),
                $binder
            );
            $sql .= $this->buildJoin($query->getJoin(), $binder);
            $sql .= $this->buildWhere($query->getWhere(), $binder);
            $sql .= $this->buildEpilog($query->getEpilog());

            return $sql;
        });
    }

    /**
     * Compiles an UpdateBatchQuery to SQL.
     *
     * @param UpdateBatchQuery $query The UpdateBatchQuery.
     * @param ValueBinder|null $binder The ValueBinder.
     * @return string The compiled SQL query.
     */
    public function compileUpdateBatch(UpdateBatchQuery $query, ValueBinder|null $binder = null): string
    {
        return $this->withQuery($query, function() use ($query, $binder): string {
            $sql = $this->buildUpdateBatch(
                $query->getTable(),
                $query->getData(),
                $query->getKeys(),
                $query->getHints(),
                $binder
            );
            $sql .= $query->getEpilog() |> $this->buildEpilog(...);

            return $sql;
        });
    }

    /**
     * Compiles an UpsertQuery to SQL.
     *
     * @param UpsertQuery $query The UpsertQuery.
     * @param ValueBinder|null $binder The ValueBinder.
     * @return string The compiled SQL query.
     */
    public function compileUpsert(UpsertQuery $query, ValueBinder|null $binder = null): string
    {
        return $this->withQuery($query, function() use ($query, $binder): string {
            $sql = $this->buildInsert($query->getTable(), $query->getValues(), $query->getHints(), $binder);
            $sql .= $this->buildOnConflict($query->getConflictKeys(), $query->getValues(), $query->getExcludeUpdateKeys());
            $sql .= $query->getEpilog() |> $this->buildEpilog(...);

            return $sql;
        });
    }

    /**
     * Builds an aggregate expression.
     *
     * @param AggregateExpression $aggregate The AggregateExpression.
     * @param ValueBinder|null $binder The value binder.
     * @return string The aggregate expression.
     */
    protected function buildAggregate(AggregateExpression $aggregate, ValueBinder|null $binder = null): string
    {
        $arguments = $aggregate->getArguments();
        $filter = $aggregate->getFilter();

        if ($filter !== null) {
            $argument = $arguments[0];

            if ($argument instanceof IdentifierExpression && $argument->getIdentifier() === '*') {
                $argument = 1;
            }

            $arguments[0] = new CaseExpression()
                ->when($filter, $argument);
        }

        $arguments = array_map($this->parseExpression(?, $binder), $arguments);

        $query = $aggregate->getName().'(';

        if ($aggregate->getDistinct()) {
            $query .= 'DISTINCT ';
        }

        return $query.implode(', ', $arguments).')';
    }

    /**
     * Builds a CASE expression.
     *
     * @param CaseExpression $case The CaseExpression.
     * @param ValueBinder|null $binder The value binder.
     * @return string The CASE expression.
     *
     * @throws InvalidArgumentException If the case expression has no branches or an empty WHEN condition.
     */
    protected function buildCase(CaseExpression $case, ValueBinder|null $binder = null): string
    {
        $cases = $case->getCases();

        if ($cases === []) {
            throw new InvalidArgumentException('Query CASE expression requires at least one WHEN branch.');
        }

        $query = 'CASE';

        $value = $case->getValue();
        $simple = $value !== null;

        if ($simple) {
            $query .= ' '.$this->parseExpression($value, $binder);
        }

        foreach ($cases as $branch) {
            if (is_array($branch['when'])) {
                if ($simple) {
                    throw new InvalidArgumentException('Query simple CASE expression does not support array WHEN values.');
                }

                $when = $this->buildConditions($branch['when'], $binder);
            } else {
                $when = $this->parseExpression($branch['when'], $binder, $simple);
            }

            if ($when === null) {
                throw new InvalidArgumentException('Query CASE WHEN condition must not be empty.');
            }

            $query .= ' WHEN '.$when;
            $query .= ' THEN '.$this->parseExpression($branch['then'], $binder);
        }

        $else = $case->getElse();
        if ($else !== null) {
            $query .= ' ELSE '.$this->parseExpression($else, $binder);
        }

        return $query.' END';
    }

    /**
     * Builds a comparison expression.
     *
     * @param ValueExpressionInterface $field The field.
     * @param string $operator The comparison operator.
     * @param mixed $value The comparison value.
     * @param ValueBinder|null $binder The value binder.
     * @return string The comparison expression.
     *
     * @throws InvalidArgumentException If IN values are empty.
     */
    protected function buildComparison(
        ValueExpressionInterface $field,
        string $operator,
        mixed $value,
        ValueBinder|null $binder = null
    ): string {
        $field = $this->parseExpression($field, $binder, false);

        if ($value === null && in_array($operator, ['IS', 'IS NOT'], true)) {
            return $field.' '.$operator.' NULL';
        }

        if (in_array($operator, ['BETWEEN', 'NOT BETWEEN'], true)) {
            return $field.' '.$operator.' '.$this->parseExpression($value[0], $binder).' AND '.$this->parseExpression($value[1], $binder);
        }

        if (in_array($operator, ['IN', 'NOT IN'], true) && is_array($value)) {
            if ($value === []) {
                throw new InvalidArgumentException(sprintf(
                    'Condition expression %s values must not be empty.',
                    $operator
                ));
            }

            $values = array_map($this->parseExpression(?, $binder), $value);

            return $field.' '.$operator.' ('.implode(', ', $values).')';
        }

        return $field.' '.$operator.' '.$this->parseExpression($value, $binder);
    }

    /**
     * Builds a condition expression.
     *
     * @param ConditionExpression $expression The condition expression.
     * @param ValueBinder|null $binder The value binder.
     * @return string|null The condition expression.
     */
    protected function buildConditionExpression(
        ConditionExpression $expression,
        ValueBinder|null $binder = null
    ): string|null {
        $groups = [];

        $conditions = $expression->getConditions();

        foreach ($conditions as $condition) {
            if ($condition instanceof ConditionExpression) {
                $condition = $this->buildConditionExpression($condition, $binder);

                if ($condition !== null) {
                    $groups[] = [$condition];
                }

                continue;
            }

            $operator = $condition['operator'];

            if ($operator === 'EXISTS' || $operator === 'NOT EXISTS') {
                $groups[] = $operator.' '.$this->parseExpression($condition['query'], $binder);

                continue;
            }

            if ($operator === 'NOT') {
                $condition = $this->buildConditionExpression($condition['condition'], $binder);

                if ($condition !== null) {
                    $groups[] = 'NOT ('.$condition.')';
                }

                continue;
            }

            $groups[] = $this->buildComparison(
                $condition['field'],
                $operator,
                $condition['value'],
                $binder
            );
        }

        return static::buildConditionGroup($groups, $expression->getConjunction());
    }

    /**
     * Builds conditions recursively.
     *
     * @param array<mixed> $conditions The conditions.
     * @param ValueBinder|null $binder The value binder.
     * @param string $type The condition separator.
     * @return string|null The conditions.
     *
     * @throws InvalidArgumentException If IN values are empty.
     */
    protected function buildConditions(
        array $conditions,
        ValueBinder|null $binder = null,
        string $type = 'AND'
    ): string|null {
        $groups = [];

        foreach ($conditions as $field => $value) {
            if (is_array($value)) {
                if (is_numeric($field)) {
                    $subType = 'AND';
                } else {
                    $subType = strtoupper($field);
                }

                if (in_array($subType, ['AND', 'OR'])) {
                    $condition = $this->buildConditions($value, $binder, $subType);

                    if ($condition !== null) {
                        $groups[] = [$condition];
                    }

                    continue;
                }

                if ($subType === 'NOT') {
                    $condition = $this->buildConditions($value, $binder);

                    if ($condition !== null) {
                        $groups[] = 'NOT ('.$condition.')';
                    }

                    continue;
                }

                $field = trim((string) $field);

                if (preg_match('/\A(.+?)\s+((?:NOT\s+)?IN)\z/i', $field, $match)) {
                    $field = $match[1];
                    $comparison = strtoupper($match[2]);
                    $comparison = (string) preg_replace('/\s+/', ' ', $comparison);
                } else {
                    $comparison = 'IN';
                }

                if ($value === []) {
                    throw new InvalidArgumentException(sprintf(
                        'Query condition %s values must not be empty.',
                        $comparison
                    ));
                }

                $field = $this->connection->quoteIdentifier($field);
                $value = array_map($this->parseExpression(?, $binder), $value);

                $groups[] = $field.' '.$comparison.' ('.implode(', ', $value).')';

                continue;
            }

            if (is_numeric($field)) {
                if ($value instanceof Closure) {
                    $value = $value($this->currentQuery, $binder);
                }

                if ($value instanceof ConditionExpression) {
                    $condition = $this->buildConditionExpression($value, $binder);

                    if ($condition !== null) {
                        $expressionConditions = $value->getConditions();
                        $shouldGroup = count($expressionConditions) > 1 ||
                            array_any(
                                $expressionConditions,
                                static fn(mixed $condition): bool => $condition instanceof ConditionExpression
                            );

                        $groups[] = $shouldGroup ?
                            [$condition] :
                            $condition;
                    }
                } else {
                    $groups[] = $this->parseExpression($value, $binder, false);
                }

                continue;
            }

            $field = trim($field);

            if (preg_match('/\A(.+?)\s+([\>\<]\=?|\!?\=|(?:NOT\s+)?(?:LIKE|IN)|IS(?:\s+NOT)?)\z/i', $field, $match)) {
                $field = $match[1];
                $comparison = strtoupper($match[2]);
                $comparison = (string) preg_replace('/\s+/', ' ', $comparison);
            } else {
                $comparison = '=';
            }

            $field = $this->connection->quoteIdentifier($field);

            if ($value === null && in_array($comparison, ['IS', 'IS NOT'])) {
                $groups[] = $field.' '.$comparison.' NULL';
            } else {
                $groups[] = $field.' '.$comparison.' '.$this->parseExpression($value, $binder);
            }
        }

        return static::buildConditionGroup($groups, $type);
    }

    /**
     * Generates the DELETE portion of the query.
     *
     * @param string[] $tables The tables.
     * @param string[] $aliases The table aliases to delete.
     * @param array<mixed> $using The using tables.
     * @param string[] $hints The optimizer hints.
     * @param ValueBinder|null $binder The value binder.
     * @return string The query string.
     */
    protected function buildDelete(
        array $tables,
        array $aliases = [],
        array $using = [],
        array $hints = [],
        ValueBinder|null $binder = null
    ): string {
        if ($aliases === [] && count($tables) > 1) {
            $aliases = array_map(
                function(int|string $alias, string $table): string {
                    if (is_numeric($alias)) {
                        return $this->connection->quoteIdentifier($table);
                    }

                    return $this->connection->quoteIdentifierPart($alias);
                },
                array_keys($tables),
                $tables
            );
        } else {
            $aliases = array_map(
                $this->connection->quoteIdentifierPart(...),
                $aliases
            );
        }

        $query = 'DELETE';
        $query .= static::buildHints($hints);

        if ($aliases !== []) {
            $query .= ' ';
            $query .= implode(', ', $aliases);
        }

        $query .= ' FROM ';
        $query .= $this->buildTables($tables);

        if ($using !== []) {
            $query .= ' USING ';
            $query .= $this->buildTables($using, $binder);
        }

        return $query;
    }

    /**
     * Generates the epilog portion of the query.
     *
     * @param string $string The string.
     * @return string The query string.
     */
    protected function buildEpilog(string $string): string
    {
        if (!$string) {
            return '';
        }

        return ' '.$string;
    }

    /**
     * Builds a query function.
     *
     * @param FunctionExpression $function The FunctionExpression.
     * @param ValueBinder|null $binder The value binder.
     * @return string The query function.
     */
    protected function buildFunction(FunctionExpression $function, ValueBinder|null $binder = null): string
    {
        $name = $function->getName();
        $arguments = $function->getArguments();

        switch ($name) {
            case 'CAST':
                [$expression, $dataType] = $arguments;
                $expression = $this->parseExpression($expression, $binder);

                return 'CAST('.$expression.' AS '.$dataType.')';
            case 'DATE_ADD':
            case 'DATE_SUB':
                [$expression, $value, $unit] = $arguments;
                $expression = $this->parseExpression($expression, $binder);
                $value = $this->parseExpression($value, $binder);

                return $name.'('.$expression.', INTERVAL '.$value.' '.strtoupper((string) $unit).')';
            case 'DATE_DIFF':
                [$start, $end] = $arguments;
                $start = $this->parseExpression($start, $binder);
                $end = $this->parseExpression($end, $binder);

                return 'DATEDIFF('.$start.', '.$end.')';
            case 'DATE_PART':
            case 'EXTRACT':
                [$part, $expression] = $arguments;
                $part = strtoupper((string) $part);
                $expression = $this->parseExpression($expression, $binder);

                return 'EXTRACT('.$part.' FROM '.$expression.')';
            case 'DAY_OF_WEEK':
                [$expression] = $arguments;
                $expression = $this->parseExpression($expression, $binder);

                return 'DAYOFWEEK('.$expression.')';
            case 'JSON_VALUE':
                [$expression, $path] = $arguments;
                $expression = $this->parseExpression($expression, $binder);
                $path = $this->parseExpression($path, $binder);

                return 'JSON_VALUE('.$expression.', '.$path.')';
            case 'NOW':
                [$type] = $arguments;

                return match (strtolower((string) $type)) {
                    'date' => 'CURRENT_DATE()',
                    'time' => 'CURRENT_TIME()',
                    default => 'NOW()',
                };
            case 'WEEK_DAY':
                [$expression] = $arguments;
                $expression = $this->parseExpression($expression, $binder);

                return 'WEEKDAY('.$expression.')';
        }

        $arguments = array_map($this->parseExpression(?, $binder), $arguments);

        return $name.'('.implode(', ', $arguments).')';
    }

    /**
     * Generates the GROUP BY portion of the query.
     *
     * @param string[] $fields The fields.
     * @return string The query string.
     */
    protected function buildGroupBy(array $fields): string
    {
        if ($fields === []) {
            return '';
        }

        $fields = array_map(
            $this->connection->quoteIdentifier(...),
            $fields
        );

        $query = ' GROUP BY ';
        $query .= implode(', ', $fields);

        return $query;
    }

    /**
     * Generates the HAVING portion of the query.
     *
     * @param array<mixed> $conditions The conditions.
     * @param ValueBinder|null $binder The value binder.
     * @return string The query string.
     */
    protected function buildHaving(array $conditions, ValueBinder|null $binder = null): string
    {
        if ($conditions === []) {
            return '';
        }

        $conditions = $this->buildConditions($conditions, $binder);

        if ($conditions === null) {
            return '';
        }

        return ' HAVING '.$conditions;
    }

    /**
     * Generates an INSERT query.
     *
     * @param array<mixed> $tables The tables.
     * @param array<string, mixed>[] $values The values.
     * @param string[] $hints The optimizer hints.
     * @param ValueBinder|null $binder The value binder.
     * @return string The query string.
     */
    protected function buildInsert(
        array $tables,
        array $values,
        array $hints = [],
        ValueBinder|null $binder = null
    ): string {
        $firstRow = $values[0] ?? [];
        $columns = array_keys($firstRow);

        foreach ($values as $row) {
            if (
                count($row) !== count($firstRow) ||
                array_diff_key($row, $firstRow) !== []
            ) {
                throw new InvalidArgumentException('All rows must contain the same columns.');
            }
        }

        $values = array_map(
            function(array $values) use ($binder, $columns): string {
                $values = array_map(
                    fn(string $column): string => $this->parseExpression($values[$column], $binder),
                    $columns
                );

                return '('.implode(', ', $values).')';
            },
            $values
        );

        $query = 'INSERT';
        $query .= static::buildHints($hints);
        $query .= ' INTO ';
        $query .= $this->buildTables($tables);
        $columns = array_map(
            $this->connection->quoteIdentifierPart(...),
            $columns
        );
        $query .= ' ('.implode(', ', $columns).')';
        $query .= ' VALUES ';
        $query .= implode(', ', $values);

        return $query;
    }

    /**
     * Generates an INSERT query from another query.
     *
     * @param array<mixed> $tables The tables.
     * @param Closure|LiteralExpression|SelectQuery|string $from The query.
     * @param string[] $columns The columns.
     * @param string[] $hints The optimizer hints.
     * @param ValueBinder|null $binder The value binder.
     * @return string The query string.
     */
    protected function buildInsertFrom(
        array $tables,
        Closure|LiteralExpression|SelectQuery|string $from,
        array $columns,
        array $hints = [],
        ValueBinder|null $binder = null
    ): string {
        $query = 'INSERT';
        $query .= static::buildHints($hints);
        $query .= ' INTO ';
        $query .= $this->buildTables($tables);

        if ($columns !== []) {
            $columns = array_map(
                $this->connection->quoteIdentifierPart(...),
                $columns
            );
            $query .= ' ('.implode(', ', $columns).')';
        }

        $query .= ' ';
        $query .= $this->parseExpression($from, $binder, false, false);

        return $query;
    }

    /**
     * Generates the JOIN portion of the query.
     *
     * @param array<string, mixed>[] $joins The joins.
     * @param ValueBinder|null $binder The value binder.
     * @return string The query string.
     *
     * @throws InvalidArgumentException If JOIN conditions are empty.
     */
    protected function buildJoin(array $joins, ValueBinder|null $binder = null): string
    {
        if ($joins === []) {
            return '';
        }

        $query = '';

        foreach ($joins as $alias => $join) {
            $join['type'] ??= 'INNER';
            $join['using'] ??= null;
            $join['conditions'] ??= [];

            $query .= ' '.strtoupper($join['type']).' JOIN ';
            $query .= $this->buildTables([
                $alias => $join['table'],
            ], $binder);

            if ($join['using']) {
                $query .= ' USING ('.$this->connection->quoteIdentifier($join['using']).')';
            } else {
                $conditions = $this->buildConditions($join['conditions'], $binder);

                if ($conditions === null) {
                    throw new InvalidArgumentException('Query JOIN conditions must not be empty.');
                }

                $query .= ' ON '.$conditions;
            }
        }

        return $query;
    }

    /**
     * Generates the LIMIT portion of the query.
     *
     * @param int|null $limit The limit.
     * @param int $offset The offset.
     * @return string The query string.
     */
    protected function buildLimit(int|null $limit, int $offset): string
    {
        if (!$limit && !$offset) {
            return '';
        }

        $query = ' LIMIT ';

        if ($offset) {
            $query .= $offset.', ';
        }

        $query .= $limit ?? 'NULL';

        return $query;
    }

    /**
     * Generates the ON CONFLICT portion of the query.
     *
     * @param string[] $conflictKeys The conflict keys.
     * @param array<string, mixed>[] $values The values.
     * @param string[] $excludeUpdateKeys The keys to exclude when updating.
     * @return string The query string.
     */
    abstract protected function buildOnConflict(array $conflictKeys, array $values, array $excludeUpdateKeys): string;

    /**
     * Generates the ORDER BY portion of the query.
     *
     * @param string[] $fields The fields.
     * @return string The query string.
     */
    protected function buildOrderBy(array $fields): string
    {
        if ($fields === []) {
            return '';
        }

        $fields = array_map(
            fn(int|string $field, string $dir): string => is_numeric($field) ?
                $this->connection->quoteIdentifier($dir) :
                $this->connection->quoteIdentifier($field).' '.strtoupper($dir),
            array_keys($fields),
            $fields
        );

        $query = ' ORDER BY ';
        $query .= implode(', ', $fields);

        return $query;
    }

    /**
     * Generates the SELECT portion of the query.
     *
     * @param array<mixed> $tables The tables.
     * @param array<mixed> $fields The fields.
     * @param bool $distinct Whether to use a DISTINCT clause.
     * @param string[] $hints The optimizer hints.
     * @param ValueBinder|null $binder The value binder.
     * @return string The query string.
     */
    protected function buildSelect(
        array $tables,
        array $fields,
        bool $distinct = false,
        array $hints = [],
        ValueBinder|null $binder = null
    ): string {
        $fields = $this->buildSelectFields($fields, $binder);

        $query = 'SELECT';
        $query .= static::buildHints($hints);
        $query .= ' ';

        if ($distinct) {
            $query .= 'DISTINCT ';
        }

        $query .= implode(', ', $fields);

        if ($tables !== []) {
            $query .= ' FROM ';
            $query .= $this->buildTables($tables, $binder);
        }

        return $query;
    }

    /**
     * Builds the SELECT fields.
     *
     * @param array<mixed> $fields The fields.
     * @param ValueBinder|null $binder The value binder.
     * @return string[] The SELECT fields.
     */
    protected function buildSelectFields(array $fields, ValueBinder|null $binder): array
    {
        return array_map(
            function(int|string $key, mixed $value) use ($binder): string {
                $value = is_string($value) ?
                    $this->connection->quoteIdentifier($value) :
                    $this->parseExpression($value, $binder, false);

                if (is_numeric($key)) {
                    return $value;
                }

                return $value.' AS '.$this->connection->quoteIdentifierPart((string) $key);
            },
            array_keys($fields),
            $fields
        );
    }

    /**
     * Builds query tables.
     *
     * @param array<mixed> $tables The tables.
     * @param ValueBinder|null $binder The value binder.
     * @param bool $with Whether this is a WITH clause.
     * @return string The table string.
     *
     * @throws InvalidArgumentException If the tables are not valid.
     */
    protected function buildTables(array $tables, ValueBinder|null $binder = null, bool $with = false): string
    {
        if ($tables === []) {
            throw new InvalidArgumentException('A table is required for this query.');
        }

        $tables = array_map(
            function(int|string $alias, mixed $table) use ($binder, $with): string {
                if ($with) {
                    return $this->connection->quoteIdentifierPart((string) $alias).' AS '.$this->parseExpression($table, $binder, false);
                }

                $fullTable = is_string($table) ?
                    $this->connection->quoteIdentifier($table) :
                    $this->parseExpression($table, $binder, false);

                $query = $fullTable;

                if ($alias !== $table && !is_numeric($alias)) {
                    $query .= ' AS '.$this->connection->quoteIdentifierPart($alias);
                }

                return $query;
            },
            array_keys($tables),
            $tables
        );

        return implode(', ', $tables);
    }

    /**
     * Generates the UNION portion of the query.
     *
     * @param array<mixed>[] $unions The unions.
     * @param ValueBinder|null $binder The value binder.
     * @return string The query string.
     */
    protected function buildUnion(array $unions, ValueBinder|null $binder = null): string
    {
        if ($unions === []) {
            return '';
        }

        $query = '';

        foreach ($unions as $union) {
            switch ($union['type']) {
                case 'all':
                    $query .= ' UNION ALL ';
                    break;
                case 'distinct':
                    $query .= ' UNION DISTINCT ';
                    break;
                case 'except':
                    $query .= ' EXCEPT ';
                    break;
                case 'intersect':
                    $query .= ' INTERSECT ';
                    break;
            }

            $query .= $this->parseExpression($union['query'], $binder, false);
        }

        return $query;
    }

    /**
     * Generates the UPDATE portion of the query.
     *
     * @param array<mixed> $tables The tables.
     * @param array<mixed> $data The data.
     * @param array<mixed> $from The from tables.
     * @param string[] $hints The optimizer hints.
     * @param ValueBinder|null $binder The value binder.
     * @return string The query string.
     */
    protected function buildUpdate(
        array $tables,
        array $data,
        array $from = [],
        array $hints = [],
        ValueBinder|null $binder = null
    ): string {
        $data = array_map(
            function(int|string $field, mixed $value) use ($binder): string {
                if (is_numeric($field)) {
                    return $this->parseExpression($value, $binder, false);
                }

                return $this->connection->quoteIdentifierPart($field).' = '.$this->parseExpression($value, $binder);
            },
            array_keys($data),
            $data
        );

        $query = 'UPDATE';
        $query .= static::buildHints($hints);
        $query .= ' ';
        $query .= $this->buildTables($tables);

        $query .= ' SET ';
        $query .= implode(', ', $data);

        if ($from !== []) {
            $query .= ' FROM ';
            $query .= $this->buildTables($from, $binder);
        }

        return $query;
    }

    /**
     * Generates a batch UPDATE query.
     *
     * @param array<mixed> $tables The tables.
     * @param array<string, mixed>[] $data The data.
     * @param string[] $keys The key to use for updating.
     * @param string[] $hints The optimizer hints.
     * @param ValueBinder|null $binder The value binder.
     * @return string The query string.
     */
    protected function buildUpdateBatch(
        array $tables,
        array $data,
        array $keys,
        array $hints = [],
        ValueBinder|null $binder = null
    ): string {
        $columns = array_filter(
            array_keys($data[0] ?? []),
            static fn(int|string $column): bool => !in_array($column, $keys)
        );

        $columns = array_values($columns);

        $allConditions = [];
        $allValues = [];
        $updateData = [];

        foreach ($data as $j => $values) {
            $updateValues = array_map(
                static fn(string $column): mixed => $values[$column] ?? null,
                $keys
            );

            $allConditions[$j] = static::combineConditions($keys, $updateValues);
            $allValues[] = $updateValues;
        }

        foreach ($columns as $column) {
            $column = (string) $column;
            $quotedColumn = $this->connection->quoteIdentifierPart($column);
            $sql = $quotedColumn.' = CASE';

            $useElse = false;
            foreach ($data as $j => $values) {
                if (!array_key_exists($column, $values)) {
                    $useElse = true;

                    continue;
                }

                $sql .= ' WHEN ';
                $sql .= $this->buildConditionExpression($allConditions[$j], $binder);
                $sql .= ' THEN ';
                $sql .= $this->parseExpression($values[$column], $binder);
            }

            if ($useElse) {
                $sql .= ' ELSE '.$quotedColumn;
            }

            $sql .= ' END';

            $updateData[] = $sql;
        }

        $query = 'UPDATE';
        $query .= static::buildHints($hints);
        $query .= ' ';
        $query .= $this->buildTables($tables);
        $query .= ' SET ';
        $query .= implode(', ', $updateData);

        $conditions = static::normalizeConditions($keys, $allValues);
        $query .= $this->buildWhere($conditions, $binder);

        return $query;
    }

    /**
     * Generates the WHERE portion of the query.
     *
     * @param array<mixed>|ConditionExpression|null $conditions The conditions.
     * @param ValueBinder|null $binder The value binder.
     * @return string The query string.
     */
    protected function buildWhere(
        array|ConditionExpression|null $conditions,
        ValueBinder|null $binder = null
    ): string {
        if ($conditions === null || $conditions === []) {
            return '';
        }

        $conditions = $conditions instanceof ConditionExpression ?
            $this->buildConditionExpression($conditions, $binder) :
            $this->buildConditions($conditions, $binder);

        if ($conditions === null) {
            return '';
        }

        return ' WHERE '.$conditions;
    }

    /**
     * Builds a window expression.
     *
     * @param WindowExpression $window The WindowExpression.
     * @param ValueBinder|null $binder The value binder.
     * @return string The window expression.
     */
    protected function buildWindow(WindowExpression $window, ValueBinder|null $binder = null): string
    {
        $clauses = [];

        $partitionBy = $window->getPartitionBy();
        if ($partitionBy !== []) {
            $partitionBy = array_map($this->parseExpression(?, $binder, false), $partitionBy);

            $clauses[] = 'PARTITION BY '.implode(', ', $partitionBy);
        }

        $orderBy = $window->getOrderBy();
        if ($orderBy !== []) {
            $clauses[] = $this->buildOrderBy($orderBy) |> trim(...);
        }

        $frame = $window->getFrame();
        if ($frame !== null) {
            $clauses[] = $frame['type'].' BETWEEN '.$frame['start'].' AND '.$frame['end'];
        }

        $exclude = $window->getExclude();
        if ($exclude !== null) {
            $clauses[] = 'EXCLUDE '.$exclude;
        }

        $query = $this->parseExpression($window->getFunction(), $binder, false);

        return $query.' OVER ('.implode(' ', $clauses).')';
    }

    /**
     * Generates the WITH portion of the query.
     *
     * @param array<mixed>[] $withs The common table expressions.
     * @param ValueBinder|null $binder The value binder.
     * @return string The query string.
     */
    protected function buildWith(array $withs, ValueBinder|null $binder = null): string
    {
        if ($withs === []) {
            return '';
        }

        $query = 'WITH ';

        foreach ($withs as $with) {
            if (!$with['recursive']) {
                continue;
            }

            $query .= 'RECURSIVE ';
            break;
        }

        $withs = array_map(
            fn(array $with): string => $this->buildTables($with['cte'], $binder, true),
            $withs
        );

        $query .= implode(', ', $withs);
        $query .= ' ';

        return $query;
    }

    /**
     * Compiles a grouped SelectQuery to SQL.
     *
     * @param SelectQuery $query The SelectQuery.
     * @param ValueBinder|null $binder The ValueBinder.
     * @return string The compiled query.
     *
     * @throws InvalidArgumentException If the query cannot use a per-group limit.
     */
    protected function compileGroupLimit(SelectQuery $query, ValueBinder|null $binder = null): string
    {
        if ($query->getDistinct()) {
            throw new InvalidArgumentException('Query group limits cannot be used with DISTINCT.');
        }

        if ($query->getUnion() !== []) {
            throw new InvalidArgumentException('Query group limits cannot be used with UNION queries.');
        }

        $groupLimit = $query->getGroupLimit();

        assert($groupLimit !== null);

        $row = SelectQuery::GROUP_LIMIT_ROW;
        $inner = clone $query;

        $rowNumber = $inner->func()
            ->rowNumber()
            ->partitionBy($groupLimit['field'])
            ->orderBy($inner->getOrderBy());

        $inner
            ->groupLimit()
            ->select([
                $row => $rowNumber,
            ])
            ->orderBy([], true)
            ->limit(null, 0)
            ->epilog();

        $conditions = $inner->expr();

        if ($groupLimit['offset'] > 0) {
            $conditions->between(
                $row,
                $groupLimit['offset'] + 1,
                $groupLimit['offset'] + $groupLimit['limit']
            );
        } else {
            $conditions->lte($row, $groupLimit['limit']);
        }

        return $this->connection
            ->select()
            ->from([
                SelectQuery::GROUP_LIMIT_TABLE => $inner,
            ])
            ->where($conditions)
            ->orderBy($row)
            ->epilog($query->getEpilog())
            ->sql($binder);
    }

    /**
     * Parses an expression string.
     *
     * @param mixed $value The value to parse.
     * @param ValueBinder|null $binder The value binder.
     * @param bool $quote Whether to quote the string.
     * @param bool $wrapSql Whether to wrap SQL queries.
     * @return string The expression string.
     */
    protected function parseExpression(mixed $value, ValueBinder|null $binder = null, bool $quote = true, bool $wrapSql = true): string
    {
        if ($value instanceof Closure) {
            $value = $value($this->currentQuery, $binder);
        }

        if ($value instanceof SelectQuery) {
            $sql = $value->sql($binder);

            return $wrapSql ? '('.$sql.')' : $sql;
        }

        if ($value instanceof LiteralExpression) {
            return (string) $value;
        }

        if ($value instanceof CaseExpression) {
            return $this->buildCase($value, $binder);
        }

        if ($value instanceof ConditionExpression) {
            $value = $this->buildConditionExpression($value, $binder);

            if ($value === null) {
                throw new InvalidArgumentException('Condition expression must not be empty.');
            }

            return $value;
        }

        if ($value instanceof WindowExpression) {
            return $this->buildWindow($value, $binder);
        }

        if ($value instanceof AggregateExpression) {
            return $this->buildAggregate($value, $binder);
        }

        if ($value instanceof FunctionExpression) {
            return $this->buildFunction($value, $binder);
        }

        if ($value instanceof IdentifierExpression) {
            return $value->getIdentifier() |> $this->connection->quoteIdentifier(...);
        }

        if ($value instanceof UnitEnum) {
            $value = EnumHelper::normalizeValue($value);
        }

        if ($value instanceof AbstractDateTime) {
            $type = match (true) {
                $value instanceof Date => 'date',
                $value instanceof DateTime => 'datetime',
                $value instanceof Time => 'time',
                default => throw new InvalidArgumentException('Date/time value is not supported.'),
            };

            $value = $this->typeParser->use($type)->toDatabase($value);
        }

        if ($binder && $quote) {
            return $binder->bind($value);
        }

        if ($value === null) {
            return 'NULL';
        }

        if ($value === false) {
            return '0';
        }

        if ($value === true) {
            return '1';
        }

        $value = (string) $value;

        if (!$quote) {
            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_FLOAT) !== false) {
            return $value;
        }

        return $this->connection->quote($value);
    }

    /**
     * Compiles a query using the current query context.
     *
     * @param Query $query The Query.
     * @param Closure(): string $callback The compilation callback.
     * @return string The compiled query.
     */
    protected function withQuery(Query $query, Closure $callback): string
    {
        $previous = $this->currentQuery;
        $this->currentQuery = $query;

        try {
            return $callback();
        } finally {
            $this->currentQuery = $previous;
        }
    }

    /**
     * Builds a group of compiled conditions.
     *
     * @param array<array{string}|string> $groups The condition groups.
     * @param string $type The condition separator.
     * @return string|null The condition group.
     */
    protected static function buildConditionGroup(array $groups, string $type): string|null
    {
        if ($groups === []) {
            return null;
        }

        $group = count($groups) > 1;

        $conditions = array_map(
            static function(array|string $condition) use ($group): string {
                if (!is_array($condition)) {
                    return $condition;
                }

                $condition = array_first($condition);

                return $group ? '('.$condition.')' : $condition;
            },
            $groups
        );

        return implode(' '.$type.' ', $conditions);
    }

    /**
     * Generates the optimizer hints portion of the query.
     *
     * @param string[] $hints The optimizer hints.
     * @return string The query string.
     */
    protected static function buildHints(array $hints): string
    {
        if ($hints === []) {
            return '';
        }

        return ' /*+ '.implode(' ', $hints).' */';
    }
}
