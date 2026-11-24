<?php
declare(strict_types=1);

namespace Fyre\DB\Handlers\Sqlite;

use Fyre\DB\Expressions\FunctionExpression;
use Fyre\DB\Expressions\ValueExpressionInterface;
use Fyre\DB\QueryGenerator;
use Fyre\DB\ValueBinder;
use InvalidArgumentException;
use Override;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function implode;
use function in_array;
use function sprintf;
use function strtolower;

/**
 * Compiles SQLite SQL for query builders.
 */
class SqliteQueryGenerator extends QueryGenerator
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function buildComparison(
        ValueExpressionInterface $field,
        string $operator,
        mixed $value,
        ValueBinder|null $binder = null
    ): string {
        $operator = match ($operator) {
            'IS DISTINCT FROM' => 'IS NOT',
            'IS NOT DISTINCT FROM' => 'IS',
            default => $operator,
        };

        return parent::buildComparison($field, $operator, $value, $binder);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function buildFunction(FunctionExpression $function, ValueBinder|null $binder = null): string
    {
        $name = $function->getName();
        $arguments = $function->getArguments();

        switch ($name) {
            case 'CONCAT':
                return array_map($this->parseExpression(?, $binder), $arguments)
                    |> implode(' || ', ?);
            case 'DATE_ADD':
            case 'DATE_SUB':
                [$expression, $value, $unit] = $arguments;
                $expression = $this->parseExpression($expression, $binder);
                $value = $this->parseExpression($value, $binder);
                $unit = strtolower((string) $unit);

                if ($unit === 'week') {
                    $value = '('.$value.' * 7)';
                    $unit = 'day';
                }

                if ($name === 'DATE_SUB') {
                    $value = '-'.$value;
                }

                return 'DATETIME('.$expression.', '.$value.' || '.$this->connection->quote(' '.$unit).')';
            case 'DATE_DIFF':
                [$start, $end] = $arguments;
                $start = $this->parseExpression($start, $binder);
                $end = $this->parseExpression($end, $binder);

                return '(JULIANDAY('.$start.') - JULIANDAY('.$end.'))';
            case 'DATE_PART':
            case 'EXTRACT':
                [$part, $expression] = $arguments;
                $part = strtolower((string) $part);
                $format = match ($part) {
                    'day' => '%d',
                    'dayofyear' => '%j',
                    'hour' => '%H',
                    'minute' => '%M',
                    'month' => '%m',
                    'second' => '%S',
                    'week' => '%W',
                    'year' => '%Y',
                    default => throw new InvalidArgumentException(sprintf(
                        'SQLite date part `%s` is not supported.',
                        $part
                    )),
                };
                $expression = $this->parseExpression($expression, $binder);

                return 'CAST(STRFTIME('.$this->connection->quote($format).', '.$expression.') AS INTEGER)';
            case 'DAY_OF_WEEK':
                [$expression] = $arguments;
                $expression = $this->parseExpression($expression, $binder);

                return '(CAST(STRFTIME(\'%w\', '.$expression.') AS INTEGER) + 1)';
            case 'JSON_VALUE':
                [$expression, $path] = $arguments;
                $expression = $this->parseExpression($expression, $binder);
                $path = $this->parseExpression($path, $binder);

                return 'JSON_EXTRACT('.$expression.', '.$path.')';
            case 'NOW':
                [$type] = $arguments;

                return match (strtolower((string) $type)) {
                    'date' => 'CURRENT_DATE',
                    'time' => 'CURRENT_TIME',
                    default => 'CURRENT_TIMESTAMP',
                };
            case 'WEEK_DAY':
                [$expression] = $arguments;
                $expression = $this->parseExpression($expression, $binder);

                return '((CAST(STRFTIME(\'%w\', '.$expression.') AS INTEGER) + 6) % 7)';
        }

        return parent::buildFunction($function, $binder);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function buildOnConflict(array $conflictKeys, array $values, array $excludeUpdateKeys): string
    {
        $excludeUpdateKeys = array_merge($conflictKeys, $excludeUpdateKeys) |> array_unique(...);
        $conflictKeys = array_map(
            $this->connection->quoteIdentifierPart(...),
            $conflictKeys
        );

        $query = ' ON CONFLICT';
        $query .= ' ('.implode(', ', $conflictKeys).')';
        $query .= ' DO UPDATE SET ';

        $query .= array_keys($values[0] ?? [])
            |> array_filter(
                ?,
                static fn(int|string $column): bool => !in_array($column, $excludeUpdateKeys, true)
            )
            |> array_map(function(int|string $column): string {
                $column = $this->connection->quoteIdentifierPart((string) $column);

                return $column.' = excluded.'.$column;
            }, ?)
            |> implode(', ', ?);

        return $query;
    }
}
