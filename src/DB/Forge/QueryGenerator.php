<?php
declare(strict_types=1);

namespace Fyre\DB\Forge;

use Fyre\Core\Traits\DebugTrait;
use Fyre\DB\Connection;

use function array_map;
use function implode;
use function strtoupper;

/**
 * DDL SQL generator for {@see Forge} operations.
 */
abstract class QueryGenerator
{
    use DebugTrait;

    /**
     * Constructs a QueryGenerator.
     *
     * @param Connection $connection The Connection.
     */
    public function __construct(
        protected Connection $connection
    ) {}

    /**
     * Generates SQL for adding a column to a table.
     *
     * @param Column $column The Column.
     * @return string The SQL query.
     */
    public function buildAddColumn(Column $column): string
    {
        $sql = 'ADD COLUMN ';
        $sql .= $this->buildColumn($column);

        return $sql;
    }

    /**
     * Generates SQL for altering a table.
     *
     * @param string $table The table name.
     * @param string[] $statements The statements.
     * @return string The SQL query.
     */
    public function buildAlterTable(string $table, array $statements): string
    {
        $sql = 'ALTER TABLE ';
        $sql .= $this->connection->quoteIdentifierPart($table);
        $sql .= ' ';
        $sql .= implode(', ', $statements);

        return $sql;
    }

    /**
     * Generates SQL for a column.
     *
     * @param Column $column The Column.
     * @return string The SQL query.
     */
    abstract public function buildColumn(Column $column): string;

    /**
     * Generates SQL for creating a new table.
     *
     * @param Table $table The Table.
     * @param array<string, mixed> $options The table options.
     * @return string The SQL query.
     */
    abstract public function buildCreateTable(Table $table, array $options = []): string;

    /**
     * Generates SQL for dropping a column from a table.
     *
     * @param string $column The column name.
     * @param array<string, mixed> $options The options for dropping the column.
     * @return string The SQL query.
     */
    public function buildDropColumn(string $column, array $options = []): string
    {
        $options['ifExists'] ??= false;

        $sql = 'DROP COLUMN ';

        if ($options['ifExists']) {
            $sql .= 'IF EXISTS ';
        }

        $sql .= $this->connection->quoteIdentifierPart($column);

        return $sql;
    }

    /**
     * Generates SQL for dropping an index from a table.
     *
     * @param string $index The index name.
     * @return string The SQL query.
     */
    public function buildDropIndex(string $index): string
    {
        $sql = 'DROP INDEX ';
        $sql .= $this->connection->quoteIdentifierPart($index);

        return $sql;
    }

    /**
     * Generates SQL for dropping a table.
     *
     * @param string $table The table name.
     * @param array<string, mixed> $options The options for dropping the table.
     * @return string The SQL query.
     */
    public function buildDropTable(string $table, array $options = []): string
    {
        $options['ifExists'] ??= false;

        $sql = 'DROP TABLE ';

        if ($options['ifExists']) {
            $sql .= 'IF EXISTS ';
        }

        $sql .= $this->connection->quoteIdentifierPart($table);

        return $sql;
    }

    /**
     * Generates SQL for a foreign key.
     *
     * @param ForeignKey $foreignKey The ForeignKey.
     * @return string The SQL query.
     */
    public function buildForeignKey(ForeignKey $foreignKey): string
    {
        $onUpdate = $foreignKey->getOnUpdate();
        $onDelete = $foreignKey->getOnDelete();

        $sql = 'CONSTRAINT ';
        $sql .= $foreignKey->getName() |> $this->connection->quoteIdentifierPart(...);
        $sql .= ' FOREIGN KEY ';
        $sql .= '(';
        $sql .= $foreignKey->getColumns()
            |> array_map($this->connection->quoteIdentifierPart(...), ?)
            |> implode(', ', ?);
        $sql .= ')';
        $sql .= ' REFERENCES ';
        $sql .= $foreignKey->getReferencedTable() |> $this->connection->quoteIdentifierPart(...);
        $sql .= ' (';
        $sql .= $foreignKey->getReferencedColumns()
            |> array_map($this->connection->quoteIdentifierPart(...), ?)
            |> implode(', ', ?);
        $sql .= ')';

        if ($onUpdate) {
            $sql .= ' ON UPDATE ';
            $sql .= strtoupper($onUpdate);
        }

        if ($onDelete) {
            $sql .= ' ON DELETE ';
            $sql .= strtoupper($onDelete);
        }

        return $sql;
    }

    /**
     * Generates SQL for renaming a column.
     *
     * @param string $column The column name.
     * @param string $newColumn The new column name.
     * @return string The SQL query.
     */
    public function buildRenameColumn(string $column, string $newColumn): string
    {
        $sql = 'RENAME COLUMN ';
        $sql .= $this->connection->quoteIdentifierPart($column);
        $sql .= ' TO ';
        $sql .= $this->connection->quoteIdentifierPart($newColumn);

        return $sql;
    }

    /**
     * Generates SQL for renaming a table.
     *
     * @param string $table The new table name.
     * @return string The SQL query.
     */
    public function buildRenameTable(string $table): string
    {
        $sql = 'RENAME TO ';
        $sql .= $this->connection->quoteIdentifierPart($table);

        return $sql;
    }
}
