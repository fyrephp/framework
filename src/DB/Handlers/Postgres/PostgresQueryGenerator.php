<?php
declare(strict_types=1);

namespace Fyre\DB\Handlers\Postgres;

use Fyre\DB\Expressions\AggregateExpression;
use Fyre\DB\Expressions\FunctionExpression;
use Fyre\DB\QueryGenerator;
use Fyre\DB\ValueBinder;
use Override;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function implode;
use function in_array;
use function strtolower;
use function strtoupper;

/**
 * Compiles PostgreSQL SQL for query builders.
 */
class PostgresQueryGenerator extends QueryGenerator
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function buildAggregate(AggregateExpression $aggregate, ValueBinder|null $binder = null): string
    {
        $filter = $aggregate->getFilter();

        if ($filter === null) {
            return parent::buildAggregate($aggregate, $binder);
        }

        $argumentsSql = array_map($this->parseExpression(?, $binder), $aggregate->getArguments())
            |> implode(', ', ?);

        $query = $aggregate->getName().'(';

        if ($aggregate->getDistinct()) {
            $query .= 'DISTINCT ';
        }

        $query .= $argumentsSql.')';
        $query .= ' FILTER (WHERE '.$this->buildConditionExpression($filter, $binder).')';

        return $query;
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
            case 'DATE_ADD':
            case 'DATE_SUB':
                [$expression, $value, $unit] = $arguments;
                $expression = $this->parseExpression($expression, $binder);
                $value = $this->parseExpression($value, $binder);
                $operator = $name === 'DATE_ADD' ? '+' : '-';
                $interval = $this->connection->quote('1 '.$unit);

                return '('.$expression.' '.$operator.' ('.$value.' * INTERVAL '.$interval.'))';
            case 'DATE_DIFF':
                [$start, $end] = $arguments;
                $start = $this->parseExpression($start, $binder);
                $end = $this->parseExpression($end, $binder);

                return 'DATE_PART(\'day\', '.$start.' - '.$end.')';
            case 'DATE_PART':
                [$part, $expression] = $arguments;
                $part = $this->connection->quote((string) $part);
                $expression = $this->parseExpression($expression, $binder);

                return 'DATE_PART('.$part.', '.$expression.')';
            case 'DAY_OF_WEEK':
                [$expression] = $arguments;
                $expression = $this->parseExpression($expression, $binder);

                return '(EXTRACT(DOW FROM '.$expression.') + 1)';
            case 'EXTRACT':
                [$part, $expression] = $arguments;
                $part = strtoupper((string) $part);
                $expression = $this->parseExpression($expression, $binder);

                return 'EXTRACT('.$part.' FROM '.$expression.')';
            case 'JSON_VALUE':
                [$expression, $path] = $arguments;
                $expression = $this->parseExpression($expression, $binder);
                $path = $this->parseExpression($path, $binder);

                return '(JSONB_PATH_QUERY_FIRST('.$expression.'::jsonb, '.$path.') #>> \'{}\')';
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

                return '(EXTRACT(ISODOW FROM '.$expression.') - 1)';
        }

        return parent::buildFunction($function, $binder);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function buildLimit(int|null $limit, int $offset): string
    {
        $query = '';

        if ($limit) {
            $query .= ' LIMIT '.$limit;
        }

        if ($offset) {
            $query .= ' OFFSET '.$offset;
        }

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function buildOnConflict(array $conflictKeys, array $values, array $excludeUpdateKeys): string
    {
        $excludeUpdateKeys = array_merge($conflictKeys, $excludeUpdateKeys) |> array_unique(...);

        $query = ' ON CONFLICT';
        $conflictKeys = array_map(
            $this->connection->quoteIdentifierPart(...),
            $conflictKeys
        );
        $query .= ' ('.implode(', ', $conflictKeys).')';
        $query .= ' DO UPDATE SET ';

        $query .= array_keys($values[0] ?? [])
            |> array_filter(
                ?,
                static fn(int|string $column): bool => !in_array($column, $excludeUpdateKeys, true)
            )
            |> array_map(function(int|string $column): string {
                $column = $this->connection->quoteIdentifierPart((string) $column);

                return $column.' = EXCLUDED.'.$column;
            }, ?)
            |> implode(', ', ?);

        return $query;
    }
}
