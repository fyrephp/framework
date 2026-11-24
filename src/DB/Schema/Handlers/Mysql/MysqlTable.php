<?php
declare(strict_types=1);

namespace Fyre\DB\Schema\Handlers\Mysql;

use Fyre\Core\Container;
use Fyre\DB\Expressions\ConditionExpression;
use Fyre\DB\Expressions\LiteralExpression;
use Fyre\DB\Query;
use Fyre\DB\Schema\Table;
use Fyre\DB\ValueBinder;
use Override;

use function array_map;
use function explode;
use function filter_var;
use function in_array;
use function is_string;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const FILTER_VALIDATE_FLOAT;
use const FILTER_VALIDATE_INT;

/**
 * Provides MySQL table metadata.
 */
class MysqlTable extends Table
{
    /**
     * Constructs a MysqlTable.
     *
     * @param Container $container The Container.
     * @param MysqlSchema $schema The MysqlSchema.
     * @param string $name The table name.
     * @param string|null $comment The table comment.
     * @param string|null $engine The table engine.
     * @param string|null $charset The table character set.
     * @param string|null $collation The table collation.
     */
    public function __construct(
        Container $container,
        MysqlSchema $schema,
        string $name,
        string|null $comment = null,
        protected string|null $engine = null,
        protected string|null $charset = null,
        protected string|null $collation = null,
    ) {
        parent::__construct($container, $schema, $name, $comment);
    }

    /**
     * Returns the table character set.
     *
     * @return string|null The table character set.
     */
    public function getCharset(): string|null
    {
        return $this->charset;
    }

    /**
     * Returns the table collation.
     *
     * @return string|null The table collation.
     */
    public function getCollation(): string|null
    {
        return $this->collation;
    }

    /**
     * Returns the table engine.
     *
     * @return string|null The table engine.
     */
    public function getEngine(): string|null
    {
        return $this->engine;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'engine' => $this->engine,
            'charset' => $this->charset,
            'collation' => $this->collation,
            'comment' => $this->comment,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @return MysqlColumn The MysqlColumn instance.
     */
    #[Override]
    protected function buildColumn(string $name, array $data): MysqlColumn
    {
        return $this->container->build(MysqlColumn::class, [
            'table' => $this,
            'name' => $name,
            ...$data,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function readColumns(): array
    {
        $connection = $this->schema->getConnection();
        $isMariaDb = str_contains($connection->version(), 'MariaDB');

        $results = $connection
            ->select([
                'name' => 'Columns.COLUMN_NAME',
                'type' => 'Columns.DATA_TYPE',
                'char_length' => 'Columns.CHARACTER_MAXIMUM_LENGTH',
                'nullable' => 'Columns.IS_NULLABLE',
                'col_type' => 'Columns.COLUMN_TYPE',
                'col_default' => 'Columns.COLUMN_DEFAULT',
                'charset' => 'Columns.CHARACTER_SET_NAME',
                'collation' => 'Columns.COLLATION_NAME',
                'extra' => 'Columns.EXTRA',
                'comment' => 'Columns.COLUMN_COMMENT',
            ])
            ->from([
                'Columns' => 'INFORMATION_SCHEMA.COLUMNS',
            ])
            ->where([
                'Columns.TABLE_SCHEMA' => $this->schema->getDatabaseName(),
                'Columns.TABLE_NAME' => $this->name,
            ])
            ->orderBy([
                'Columns.ORDINAL_POSITION' => 'ASC',
            ])
            ->execute()
            ->all();

        $columns = [];

        foreach ($results as $result) {
            $columnName = $result['name'];

            $values = null;
            $length = null;
            $precision = null;
            $scale = null;
            $fractionalSeconds = null;
            $unsigned = str_ends_with($result['col_type'], 'unsigned');
            $type = $result['type'];

            if (preg_match('/\A(?:decimal|numeric)\(([0-9]+),([0-9]+)\)/', $result['col_type'], $match)) {
                $precision = (int) $match[1];
                $scale = (int) $match[2];
            } else if (preg_match('/\A(?:tinyint|smallint|mediumint|int|bigint|bit)\(([0-9]+)\)/', $result['col_type'], $match)) {
                $precision = (int) $match[1];
            } else if (preg_match('/\A(?:datetime|time|timestamp)\(([0-9]+)\)/', $result['col_type'], $match)) {
                $fractionalSeconds = (int) $match[1];
            } else if (preg_match('/\A(?:enum|set)\((.*)\)\z/', $result['col_type'], $match)) {
                $values = explode(',', $match[1]) |> array_map(substr(?, 1, -1), ?);
            } else if (in_array($type, ['bigint', 'bit', 'int', 'mediumint', 'smallint', 'tinyint'], true)) {
                $precision = match ($type) {
                    'bit' => 1,
                    'tinyint' => 4,
                    'smallint' => 6,
                    'mediumint' => 8,
                    'int' => 11,
                    default => 20,
                };
            } else if (!in_array($type, ['double', 'float', 'real'], true)) {
                $length = $result['char_length'];
            }

            $nullable = $result['nullable'] === 'YES';
            $default = static::parseDefaultValue($result['col_default'], $type, $precision, $isMariaDb);

            $columns[$columnName] = [
                'type' => $type,
                'length' => $length,
                'precision' => $precision,
                'scale' => $scale,
                'fractionalSeconds' => $fractionalSeconds,
                'values' => $values,
                'nullable' => $nullable,
                'unsigned' => $unsigned,
                'default' => $default,
                'charset' => $result['charset'],
                'collation' => $result['collation'],
                'comment' => $result['comment'],
                'autoIncrement' => $result['extra'] === 'auto_increment',
            ];
        }

        return $columns;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function readForeignKeys(): array
    {
        $results = $this->schema->getConnection()
            ->select([
                'name' => 'KeyColumns.CONSTRAINT_NAME',
                'column_name' => 'KeyColumns.COLUMN_NAME',
                'ref_table_name' => 'KeyColumns.REFERENCED_TABLE_NAME',
                'ref_column' => 'KeyColumns.REFERENCED_COLUMN_NAME',
                'on_update' => 'ReferentialConstraints.UPDATE_RULE',
                'on_delete' => 'ReferentialConstraints.DELETE_RULE',
            ])
            ->from([
                'KeyColumns' => 'INFORMATION_SCHEMA.KEY_COLUMN_USAGE',
            ])
            ->join([
                [
                    'table' => 'INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS',
                    'alias' => 'ReferentialConstraints',
                    'type' => 'INNER',
                    'conditions' => static fn(Query $query): ConditionExpression => $query->expr()
                        ->equalFields(
                            'ReferentialConstraints.CONSTRAINT_SCHEMA',
                            'KeyColumns.CONSTRAINT_SCHEMA'
                        )
                        ->equalFields(
                            'ReferentialConstraints.CONSTRAINT_NAME',
                            'KeyColumns.CONSTRAINT_NAME'
                        )
                        ->equalFields(
                            'ReferentialConstraints.TABLE_NAME',
                            'KeyColumns.TABLE_NAME'
                        ),
                ],
            ])
            ->where([
                'KeyColumns.TABLE_SCHEMA' => $this->schema->getDatabaseName(),
                'KeyColumns.TABLE_NAME' => $this->name,
            ])
            ->orderBy([
                'KeyColumns.ORDINAL_POSITION' => 'ASC',
            ])
            ->execute()
            ->all();

        $foreignKeys = [];

        foreach ($results as $result) {
            $constraintName = $result['name'];

            $foreignKeys[$constraintName] ??= [
                'columns' => [],
                'referencedTable' => $result['ref_table_name'],
                'referencedColumns' => [],
                'onUpdate' => $result['on_update'],
                'onDelete' => $result['on_delete'],
            ];

            $foreignKeys[$constraintName]['columns'][] = $result['column_name'];
            $foreignKeys[$constraintName]['referencedColumns'][] = $result['ref_column'];
        }

        return $foreignKeys;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function readIndexes(): array
    {
        $binder = new ValueBinder();
        $p0 = $binder->bind('PRIMARY');

        $results = $this->schema->getConnection()
            ->select([
                'name' => 'Statistics.INDEX_NAME',
                'column_name' => 'Statistics.COLUMN_NAME',
                'not_unique' => 'Statistics.NON_UNIQUE',
                'type' => 'Statistics.INDEX_TYPE',
            ])
            ->from([
                'Statistics' => 'INFORMATION_SCHEMA.STATISTICS',
            ])
            ->where([
                'Statistics.TABLE_SCHEMA' => $this->schema->getDatabaseName(),
                'Statistics.TABLE_NAME' => $this->name,
            ])
            ->orderBy([
                '(Statistics.INDEX_NAME = '.$p0.') DESC',
                'Statistics.NON_UNIQUE' => 'ASC',
                'Statistics.INDEX_NAME' => 'ASC',
                'Statistics.SEQ_IN_INDEX' => 'ASC',
            ])
            ->execute($binder)
            ->all();

        $indexes = [];

        foreach ($results as $result) {
            $indexName = $result['name'];

            $indexes[$indexName] ??= [
                'columns' => [],
                'unique' => !$result['not_unique'],
                'primary' => $indexName === 'PRIMARY',
                'type' => strtolower($result['type']),
            ];

            if (!in_array($result['column_name'], $indexes[$indexName]['columns'], true)) {
                $indexes[$indexName]['columns'][] = $result['column_name'];
            }
        }

        return $indexes;
    }

    /**
     * Parses a column default value from INFORMATION_SCHEMA.
     *
     * The raw value comes from `INFORMATION_SCHEMA.COLUMNS.COLUMN_DEFAULT`, which is not consistently formatted across
     * MySQL/MariaDB. This method normalizes the raw default into either a scalar (string|int|float|bool|null) or a
     * {@see LiteralExpression} for SQL expressions.
     *
     * Supported patterns include:
     * - `NULL`/`null` (string) => `null`
     * - `CURRENT_TIMESTAMP...` => {@see LiteralExpression}(`CURRENT_TIMESTAMP`)
     * - quoted strings: `'abc'` (MySQL escaping `''`) => `"abc"`
     * - charset introducers / optional collations (observed in metadata):
     *   - `_utf8mb4'abc'`
     *   - `_utf8mb4\\'abc\\'`
     *   - `_utf8mb4\\'{\"key\": \"value\"}\\' COLLATE utf8mb4_bin`
     *
     * On MariaDB, defaults that cannot be safely interpreted as a scalar are returned as {@see LiteralExpression}. On MySQL,
     * unknown patterns are returned as the raw string to avoid misclassifying server-specific expressions.
     *
     * Note: {@see LiteralExpression} is raw SQL; values originate from database metadata and should not be user-supplied.
     *
     * @param mixed $default The raw default value.
     * @param string $type The column type.
     * @param int|null $precision The column precision.
     * @param bool $isMariaDb Whether the server is MariaDB.
     * @return bool|float|int|LiteralExpression|string|null The normalized default.
     */
    protected static function parseDefaultValue(mixed $default, string $type, int|null $precision, bool $isMariaDb): bool|float|int|LiteralExpression|string|null
    {
        if ($default === null || !is_string($default)) {
            return $default;
        }

        $defaultLower = strtolower($default);

        if ($defaultLower === 'null') {
            return null;
        }

        if (str_starts_with($defaultLower, 'current_timestamp')) {
            return new LiteralExpression('CURRENT_TIMESTAMP');
        }

        if (preg_match('/\A(?:_[a-z0-9_]+)?(\\\\)?\'(.*?)\1?\'(?:\s+COLLATE\s+[a-z0-9_]+)?\z/s', $default, $matches)) {
            return $matches[1] ?
                str_replace(['\\\\', "\\'"], ['\\', "'"], $matches[2]) :
                str_replace("''", "'", $matches[2]);
        }

        if ($type === 'tinyint' && $precision === 1) {
            $type = 'boolean';
        }

        $result = null;

        switch ($type) {
            case 'bigint':
            case 'int':
            case 'integer':
            case 'mediumint':
            case 'smallint':
            case 'tinyint':
                $result = filter_var($default, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
                break;
            case 'boolean':
                $result = filter_var($default, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                break;
            case 'decimal':
            case 'double':
            case 'float':
            case 'numeric':
            case 'real':
                $result = filter_var($default, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
                break;
            default:
                if (!$isMariaDb) {
                    return $default;
                }
                break;
        }

        return $result ?? new LiteralExpression($default);
    }
}
