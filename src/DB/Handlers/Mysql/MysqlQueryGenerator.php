<?php
declare(strict_types=1);

namespace Fyre\DB\Handlers\Mysql;

use Fyre\DB\Expressions\ValueExpressionInterface;
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

/**
 * Compiles MySQL SQL for query builders.
 */
class MysqlQueryGenerator extends QueryGenerator
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
        if (!in_array($operator, ['IS DISTINCT FROM', 'IS NOT DISTINCT FROM'])) {
            return parent::buildComparison($field, $operator, $value, $binder);
        }

        $comparison = $this->parseExpression($field, $binder, false).' <=> '.$this->parseExpression($value, $binder);

        return $operator === 'IS DISTINCT FROM' ?
            'NOT ('.$comparison.')' :
            $comparison;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function buildOnConflict(array $conflictKeys, array $values, array $excludeUpdateKeys): string
    {
        $excludeUpdateKeys = array_merge($conflictKeys, $excludeUpdateKeys) |> array_unique(...);

        $query = ' ON DUPLICATE KEY UPDATE ';

        $query .= array_keys($values[0] ?? [])
            |> array_filter(
                ?,
                static fn(int|string $column): bool => !in_array($column, $excludeUpdateKeys, true)
            )
            |> array_map(function(int|string $column): string {
                $column = $this->connection->quoteIdentifierPart((string) $column);

                return $column.' = VALUES('.$column.')';
            }, ?)
            |> implode(', ', ?);

        return $query;
    }
}
