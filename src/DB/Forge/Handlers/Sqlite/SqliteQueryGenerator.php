<?php
declare(strict_types=1);

namespace Fyre\DB\Forge\Handlers\Sqlite;

use Fyre\DB\Forge\Column;
use Fyre\DB\Forge\Index;
use Fyre\DB\Forge\QueryGenerator;
use Fyre\DB\Forge\Table;
use InvalidArgumentException;
use Override;

use function array_map;
use function implode;
use function is_bool;
use function is_string;
use function sprintf;
use function strtoupper;

/**
 * Generates SQLite DDL SQL.
 */
class SqliteQueryGenerator extends QueryGenerator
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    public function buildColumn(Column $column): string
    {
        $type = $column->getType();

        $sql = $column->getName() |> $this->connection->quoteIdentifierPart(...);

        if ($column->isUnsigned()) {
            $sql .= ' UNSIGNED';
        }

        $sql .= ' ';
        $sql .= strtoupper($type);

        switch ($type) {
            case 'bit':
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'integer':
            case 'bigint':
                $precision = $column->getPrecision();
                if ($precision !== null) {
                    $sql .= '(';
                    $sql .= $precision;
                    $sql .= ')';
                }
                break;
            case 'char':
            case 'varchar':
                $length = $column->getLength();
                if ($length !== null) {
                    $sql .= '(';
                    $sql .= $length;
                    $sql .= ')';
                }
                break;
            case 'datetime':
            case 'time':
            case 'timestamp':
                $fractionalSeconds = $column->getFractionalSeconds();
                if ($fractionalSeconds !== null) {
                    $sql .= '(';
                    $sql .= $fractionalSeconds;
                    $sql .= ')';
                }
                break;
            case 'decimal':
            case 'numeric':
                $precision = $column->getPrecision();
                if ($precision !== null) {
                    $sql .= '(';
                    $sql .= $precision;
                    $sql .= ',';
                    $sql .= $column->getScale() ?? 0;
                    $sql .= ')';
                }
                break;
        }

        if ($column->isNullable()) {
            $sql .= ' NULL';
        } else {
            $sql .= ' NOT NULL';
        }

        $default = $column->getDefault();

        if ($default !== null) {
            $sql .= ' DEFAULT ';

            if (is_string($default)) {
                $sql .= $this->connection->quote($default);
            } else if (is_bool($default)) {
                $sql .= $default ? '1' : '0';
            } else {
                $sql .= (string) $default;
            }
        }

        return $sql;
    }

    /**
     * Generates SQL for a constraint.
     *
     * @param Index $index The Index.
     * @return string The SQL query.
     *
     * @throws InvalidArgumentException If the constraint is not valid.
     */
    public function buildConstraint(Index $index): string
    {
        if ($index->isPrimary()) {
            $sql = 'PRIMARY';
        } else if ($index->isUnique()) {
            $sql = $index->getName() |> $this->connection->quoteIdentifierPart(...);
            $sql .= ' UNIQUE';
        } else {
            throw new InvalidArgumentException(sprintf(
                'Constraint `%s` is not valid.',
                $index->getName()
            ));
        }

        $sql .= ' KEY (';
        $sql .= $index->getColumns()
            |> array_map($this->connection->quoteIdentifierPart(...), ?)
            |> implode(', ', ?);
        $sql .= ')';

        return $sql;
    }

    /**
     * Generates SQL for creating a table index.
     *
     * @param Index $index The Index.
     * @return string The SQL query.
     *
     * @throws InvalidArgumentException If the index is a primary key.
     */
    public function buildCreateIndex(Index $index): string
    {
        if ($index->isPrimary()) {
            throw new InvalidArgumentException('Primary keys cannot be added to existing tables.');
        }

        $sql = 'CREATE ';

        if ($index->isUnique()) {
            $sql .= 'UNIQUE ';
        }

        $sql .= 'INDEX ';
        $sql .= $index->getName() |> $this->connection->quoteIdentifierPart(...);
        $sql .= ' ON ';
        $sql .= $index->getTable()->getName() |> $this->connection->quoteIdentifierPart(...);
        $sql .= ' (';
        $sql .= $index->getColumns()
            |> array_map($this->connection->quoteIdentifierPart(...), ?)
            |> implode(', ', ?);
        $sql .= ')';

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function buildCreateTable(Table $table, array $options = []): string
    {
        $options['ifNotExists'] ??= false;

        $columns = $table->columns();
        $indexes = $table->indexes();
        $foreignKeys = $table->foreignKeys();

        $definitions = array_map(
            $this->buildColumn(...),
            $columns
        );

        foreach ($indexes as $name => $index) {
            if (isset($foreignKeys[$name])) {
                continue;
            }

            if ($index->isPrimary()) {
                $definitions[] = $this->buildConstraint($index);
            } else {
                continue;
            }
        }

        foreach ($foreignKeys as $foreignKey) {
            $definitions[] = $this->buildForeignKey($foreignKey);
        }

        $sql = 'CREATE TABLE ';

        if ($options['ifNotExists']) {
            $sql .= 'IF NOT EXISTS ';
        }

        $sql .= $table->getName() |> $this->connection->quoteIdentifierPart(...);

        $sql .= ' (';
        $sql .= implode(', ', $definitions);
        $sql .= ')';

        return $sql;
    }
}
