<?php
declare(strict_types=1);

namespace Fyre\DB\Forge\Handlers\Mysql;

use Fyre\DB\Forge\Column;
use Fyre\DB\Forge\ForeignKey;
use Fyre\DB\Forge\Index;
use Fyre\DB\Forge\QueryGenerator;
use Fyre\DB\Forge\Table;
use Fyre\DB\Handlers\Mysql\MysqlConnection;
use InvalidArgumentException;
use Override;

use function array_map;
use function assert;
use function implode;
use function in_array;
use function is_bool;
use function is_string;
use function sprintf;
use function strtoupper;

/**
 * Generates MySQL DDL SQL.
 */
class MysqlQueryGenerator extends QueryGenerator
{
    protected const TEXT_TYPES = [
        'binary',
        'blob',
        'geometry',
        'json',
        'linestring',
        'longblob',
        'longtext',
        'mediumblob',
        'mediumtext',
        'point',
        'polygon',
        'text',
        'tinyblob',
        'tinytext',
        'varbinary',
    ];

    /**
     * Generates SQL for adding a column to a table.
     *
     * @param Column $column The Column.
     * @param array<string, mixed> $options The column options.
     * @return string The SQL query.
     */
    #[Override]
    public function buildAddColumn(Column $column, array $options = []): string
    {
        $sql = 'ADD COLUMN ';
        $sql .= $this->buildColumn($column, $options);

        return $sql;
    }

    /**
     * Generates SQL for adding a foreign key to a table.
     *
     * @param ForeignKey $foreignKey The ForeignKey.
     * @return string The SQL query.
     */
    public function buildAddForeignKey(ForeignKey $foreignKey): string
    {
        $sql = 'ADD ';
        $sql .= $this->buildForeignKey($foreignKey);

        return $sql;
    }

    /**
     * Generates SQL for adding an index to a table.
     *
     * @param Index $index The Index.
     * @return string The SQL query.
     */
    public function buildAddIndex(Index $index): string
    {
        $sql = 'ADD ';
        $sql .= $this->buildIndex($index);

        return $sql;
    }

    /**
     * Generates SQL for changing a table column.
     *
     * @param Column $column The Column.
     * @param array<string, mixed> $options The column options.
     * @return string The SQL query.
     */
    public function buildChangeColumn(Column $column, array $options = []): string
    {
        $sql = 'CHANGE COLUMN ';
        $sql .= $this->connection->quoteIdentifierPart($options['name'] ?? $column->getName());
        $sql .= ' ';
        $sql .= $this->buildColumn($column, $options);

        return $sql;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $options The column options (supports `after`, `first` and `forceComment`).
     */
    #[Override]
    public function buildColumn(Column $column, array $options = []): string
    {
        $options['after'] ??= null;
        $options['first'] ??= false;
        $options['forceComment'] ??= false;

        assert($column instanceof MysqlColumn);

        $type = $column->getType();

        $sql = $column->getName() |> $this->connection->quoteIdentifierPart(...);
        $sql .= ' ';
        $sql .= strtoupper($type);

        switch ($type) {
            case 'bit':
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
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
                $precision = $column->getPrecision();
                if ($precision !== null) {
                    $sql .= '(';
                    $sql .= $precision;
                    $sql .= ',';
                    $sql .= $column->getScale() ?? 0;
                    $sql .= ')';
                }
                break;
            case 'enum':
            case 'set':
                $values = $column->getValues();
                if ($values !== null) {
                    $sql .= '(';
                    $sql .= array_map(
                        fn(mixed $value): string => $this->connection->quote((string) $value),
                        $values
                    ) |> implode(',', ?);
                    $sql .= ')';
                }
                break;
        }

        if ($column->isUnsigned()) {
            $sql .= ' UNSIGNED';
        }

        $charset = $column->getCharset();

        if ($charset) {
            $sql .= ' CHARACTER SET '.$this->connection->quote($charset);
        }

        $collation = $column->getCollation();

        if ($collation) {
            $sql .= ' COLLATE '.$this->connection->quote($collation);
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
                if (in_array($type, static::TEXT_TYPES, true)) {
                    $sql .= '('.$this->connection->quote($default).')';
                } else {
                    $sql .= $this->connection->quote($default);
                }
            } else if (is_bool($default)) {
                $sql .= $default ? '1' : '0';
            } else {
                $sql .= (string) $default;
            }
        }

        if ($column->isAutoIncrement()) {
            $sql .= ' AUTO_INCREMENT';
        }

        $comment = $column->getComment();

        if ($comment || $options['forceComment']) {
            $sql .= ' COMMENT '.$this->connection->quote((string) $comment);
        }

        if ($options['after']) {
            $sql .= ' AFTER '.$this->connection->quoteIdentifierPart($options['after']);
        } else if ($options['first']) {
            $sql .= ' FIRST';
        }

        return $sql;
    }

    /**
     * Generates SQL for creating a new schema.
     *
     * @param string $schema The schema name.
     * @param array<string, mixed> $options The schema options.
     * @return string The SQL query.
     */
    public function buildCreateSchema(string $schema, array $options = []): string
    {
        $connection = $this->connection;

        assert($connection instanceof MysqlConnection);

        $options['ifNotExists'] ??= false;
        $options['charset'] ??= $connection->getCharset();
        $options['collation'] ??= $connection->getCollation();

        $sql = 'CREATE SCHEMA ';

        if ($options['ifNotExists']) {
            $sql .= 'IF NOT EXISTS ';
        }

        $sql .= $this->connection->quoteIdentifierPart($schema);

        if ($options['charset']) {
            $sql .= ' CHARACTER SET = '.$connection->quote($options['charset']);
        }

        if ($options['collation']) {
            $sql .= ' COLLATE = '.$connection->quote($options['collation']);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function buildCreateTable(Table $table, array $options = []): string
    {
        $options['ifNotExists'] ??= false;

        assert($table instanceof MysqlTable);

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

            $definitions[] = $this->buildIndex($index);
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

        $engine = $table->getEngine();

        if ($engine) {
            $sql .= ' '.$this->buildTableEngine($engine);
        }

        $charset = $table->getCharset();

        if ($charset) {
            $sql .= ' '.$this->buildTableCharset($charset);
        }

        $collation = $table->getCollation();

        if ($collation) {
            $sql .= ' '.$this->buildTableCollation($collation);
        }

        $comment = $table->getComment();

        if ($comment) {
            $sql .= ' '.$this->buildTableComment($comment);
        }

        return $sql;
    }

    /**
     * Generates SQL for dropping a foreign key from a table.
     *
     * @param string $foreignKey The foreign key name.
     * @return string The SQL query.
     */
    public function buildDropForeignKey(string $foreignKey): string
    {
        $sql = 'DROP FOREIGN KEY ';
        $sql .= $this->connection->quoteIdentifierPart($foreignKey);

        return $sql;
    }

    /**
     * Generates SQL for dropping a primary key from a table.
     *
     * @return string The SQL query.
     */
    public function buildDropPrimaryKey(): string
    {
        return 'DROP PRIMARY KEY';
    }

    /**
     * Generates SQL for dropping a schema.
     *
     * @param string $schema The schema name.
     * @param array<string, mixed> $options The options for dropping the schema.
     * @return string The SQL query.
     */
    public function buildDropSchema(string $schema, array $options = []): string
    {
        $options['ifExists'] ??= false;

        $sql = 'DROP SCHEMA ';

        if ($options['ifExists']) {
            $sql .= 'IF EXISTS ';
        }

        $sql .= $this->connection->quoteIdentifierPart($schema);

        return $sql;
    }

    /**
     * Generates SQL for an index.
     *
     * @param Index $index The Index.
     * @return string The SQL query.
     *
     * @throws InvalidArgumentException If primary key index type is not valid.
     */
    public function buildIndex(Index $index): string
    {
        $columns = $index->getColumns()
            |> array_map($this->connection->quoteIdentifierPart(...), ?)
            |> implode(', ', ?);

        $type = (string) $index->getType();

        if ($index->isPrimary()) {
            if ($type !== 'btree') {
                throw new InvalidArgumentException(sprintf(
                    'Index type `%s` is not valid.',
                    $type
                ));
            }

            return 'PRIMARY KEY ('.$columns.')';
        }

        $name = $index->getName() |> $this->connection->quoteIdentifierPart(...);

        if ($index->isUnique()) {
            return 'CONSTRAINT '.$name.' UNIQUE KEY ('.$columns.') USING '.strtoupper($type);
        }

        switch ($type) {
            case 'fulltext':
                return 'FULLTEXT INDEX '.$name.' ('.$columns.')';
            case 'spatial':
                return 'SPATIAL INDEX '.$name.' ('.$columns.')';
            default:
                return 'INDEX '.$name.' ('.$columns.') USING '.strtoupper($type);
        }
    }

    /**
     * Generates SQL for the table character set option.
     *
     * @param string $charset The character set.
     * @return string The SQL query.
     */
    public function buildTableCharset(string $charset): string
    {
        return 'DEFAULT CHARSET = '.$this->connection->quote($charset);
    }

    /**
     * Generates SQL for the table collation option.
     *
     * @param string $collation The collation.
     * @return string The SQL query.
     */
    public function buildTableCollation(string $collation): string
    {
        return 'COLLATE = '.$this->connection->quote($collation);
    }

    /**
     * Generates SQL for the table comment option.
     *
     * @param string $comment The comment.
     * @return string The SQL query.
     */
    public function buildTableComment(string $comment): string
    {
        return 'COMMENT '.$this->connection->quote($comment);
    }

    /**
     * Generates SQL for the table engine option.
     *
     * @param string $engine The engine.
     * @return string The SQL query.
     */
    public function buildTableEngine(string $engine): string
    {
        return 'ENGINE = '.$engine;
    }
}
