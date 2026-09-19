<?php
declare(strict_types=1);

namespace Fyre\Core\Make;

use Fyre\Core\Make as MakeService;
use Fyre\Core\Make\Traits\EnumCaseParserTrait;
use Fyre\Core\Make\Traits\UseStatementBuilderTrait;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Schema\Column;
use Fyre\DB\Schema\ForeignKey;
use Fyre\DB\Schema\Index;
use Fyre\DB\Schema\Table;
use Fyre\DB\Types\BooleanType;
use Fyre\DB\Types\DateTimeType;
use Fyre\DB\Types\DecimalType;
use Fyre\DB\Types\FloatType;
use Fyre\DB\Types\IntegerType;
use Fyre\Form\Rule;
use Fyre\Form\Validator;
use Fyre\ORM\Attributes\BelongsTo;
use Fyre\ORM\Attributes\EnumField;
use Fyre\ORM\Attributes\HasMany;
use Fyre\ORM\Attributes\HasOne;
use Fyre\ORM\Attributes\ManyToMany;
use Fyre\ORM\Model;
use Fyre\ORM\ModelRegistry;
use Fyre\ORM\Relationships\BelongsTo as BelongsToRelationship;
use Fyre\ORM\Relationships\HasMany as HasManyRelationship;
use Fyre\ORM\Relationships\HasOne as HasOneRelationship;
use Fyre\ORM\Relationships\ManyToMany as ManyToManyRelationship;
use Fyre\ORM\RuleSet;
use Fyre\ORM\Traits\SoftDeleteTrait;
use Fyre\ORM\Traits\TimestampsTrait;
use Fyre\Utility\Inflector;
use InvalidArgumentException;
use Override;
use ReflectionClass;

use function array_diff;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_subclass_of;
use function method_exists;
use function natsort;
use function preg_replace;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function trim;
use function var_export;

use const PHP_EOL;

/**
 * Builds model source code from schema metadata.
 *
 * @internal
 *
 * @phpstan-type EnumData array{field: string, className: string, cases: string, values: string[]}
 * @phpstan-type RelationshipData array{type: 'belongsTo'|'hasMany'|'hasOne'|'manyToMany', alias: string, targetModel: string, foreignKey: string[], bindingKey: string[], nullable: bool, options: array<string, string>}
 * @phpstan-type ModelRelationshipData array{type: 'belongsTo'|'hasMany'|'hasOne'|'manyToMany', alias: string, targetModel: string, targetModelClass: string, foreignKey: string[], bindingKey: string[], nullable: bool, options: array<string, string>}
 * @phpstan-type ValidatorRuleData array{rule: string, options?: array<string, string>}
 */
class ModelSourceBuilder
{
    use EnumCaseParserTrait;
    use UseStatementBuilderTrait;

    public const BELONGS_TO = 'belongsTo';

    public const HAS_MANY = 'hasMany';

    public const HAS_ONE = 'hasOne';

    public const MANY_TO_MANY = 'manyToMany';

    /**
     * Constructs a ModelSourceBuilder.
     *
     * @param ModelRegistry $modelRegistry The ModelRegistry.
     * @param Inflector $inflector The Inflector.
     */
    public function __construct(
        protected ModelRegistry $modelRegistry,
        protected Inflector $inflector
    ) {}

    /**
     * Builds a model class.
     *
     * @param string $namespace The model namespace.
     * @param string $className The model class name.
     * @param string $entityNamespace The entity namespace.
     * @param string $entityClass The entity class name.
     * @param string $enumNamespace The enum namespace.
     * @param Column[] $fields The schema fields.
     * @param Index[] $indexes The schema indexes.
     * @param EnumData[] $enums The inferred enum definitions.
     * @param ModelRelationshipData[] $relationships The model relationships.
     * @param string $connection The database connection.
     * @param string|null $table The explicitly configured table.
     * @param bool $withValidation Whether to generate validation rules.
     * @param bool $withRules Whether to generate application rules.
     * @return string The model source code.
     *
     * @throws InvalidArgumentException If imported classes have the same short name.
     */
    public function build(
        string $namespace,
        string $className,
        string $entityNamespace,
        string $entityClass,
        string $enumNamespace,
        array $fields,
        array $indexes,
        array $enums,
        array $relationships,
        string $connection,
        string|null $table,
        bool $withValidation,
        bool $withRules
    ): string {
        $validator = $withValidation ? static::getValidator($fields, $enums) : [];
        $rules = $withRules ? static::getRules($indexes, $relationships) : [];
        $traits = static::getTraits($fields);

        return MakeService::loadStub('model', [
            '{namespace}' => $namespace,
            '{uses}' => static::buildUses(
                $namespace,
                $entityNamespace,
                $entityClass,
                $enumNamespace,
                $enums,
                $relationships,
                $traits,
                $validator
            ),
            '{docblock}' => static::buildDocBlock($entityClass, $relationships, $traits),
            '{attributes}'.PHP_EOL => static::buildAttributes($enums, $relationships),
            '{class}' => $className,
            '{traits}'.PHP_EOL => static::buildTraits($traits),
            '{properties}'.PHP_EOL => static::buildProperties($connection, $table),
            '{rules}'.PHP_EOL => static::buildStatements($rules),
            '{validator}'.PHP_EOL => static::buildStatements($validator),
        ]);
    }

    /**
     * Resolves model classes for inferred relationships.
     *
     * @param RelationshipData[] $relationships The inferred relationships.
     * @param string $namespace The generated model namespace.
     * @return ModelRelationshipData[] The resolved relationships.
     */
    public function buildRelationshipData(array $relationships, string $namespace): array
    {
        $data = [];

        foreach ($relationships as $relationship) {
            $targetModelClass = $namespace.'\\'.$relationship['targetModel'];

            foreach ($this->modelRegistry->getNamespaces() as $modelNamespace) {
                $className = $modelNamespace.$relationship['targetModel'];

                if (is_subclass_of($className, Model::class)) {
                    $targetModelClass = $className;
                    break;
                }
            }

            $data[] = [
                ...$relationship,
                'targetModelClass' => $targetModelClass,
            ];
        }

        return $data;
    }

    /**
     * Infers enum definitions from schema fields.
     *
     * @param Column[] $fields The schema fields.
     * @param string $entityClass The entity class name.
     * @return EnumData[] The inferred enum definitions.
     */
    public function inferEnums(array $fields, string $entityClass): array
    {
        $enums = [];

        foreach ($fields as $field) {
            $enum = $this->inferEnumFromColumn($field, $entityClass);

            if ($enum !== null) {
                $enums[] = $enum;
            }
        }

        return $enums;
    }

    /**
     * Infers relationships for a table.
     *
     * @param Table $table The source table.
     * @param string $sourceAlias The source model alias.
     * @return RelationshipData[] The inferred relationships.
     *
     * @throws InvalidArgumentException If two relationships use the same alias.
     */
    public function inferRelationships(Table $table, string $sourceAlias): array
    {
        $relationships = [];
        $schema = $table->getSchema();

        foreach ($table->foreignKeys() as $foreignKey) {
            $targetName = $foreignKey->getReferencedTable();

            if (!$targetName || !$schema->hasTable($targetName)) {
                continue;
            }

            $relationship = $this->belongsTo(
                $table,
                $schema->table($targetName),
                $foreignKey->getColumns(),
                $foreignKey->getReferencedColumns()
            );

            if ($relationship === null) {
                continue;
            }

            $origin = $table->getName().'.'.$foreignKey->getName();
            $relationships[$origin] = $relationship;
        }

        foreach ($schema->tables() as $otherTable) {
            $relationship = $this->manyToMany($table, $otherTable, $sourceAlias);

            if ($relationship !== null) {
                $relationships[$otherTable->getName()] = $relationship;

                continue;
            }

            if ($otherTable->getName() === $table->getName()) {
                continue;
            }

            foreach ($otherTable->foreignKeys() as $foreignKey) {
                $relationship = $this->child($table, $otherTable, $foreignKey, $sourceAlias);

                if ($relationship !== null) {
                    $origin = $otherTable->getName().'.'.$foreignKey->getName();
                    $relationships[$origin] = $relationship;
                }
            }
        }

        $usedAliases = [];

        foreach ($relationships as $origin => $relationship) {
            $alias = $relationship['alias'];

            if (isset($usedAliases[$alias])) {
                throw new InvalidArgumentException(sprintf(
                    'Relationship alias `%s` collides between `%s` and `%s`.',
                    $alias,
                    $usedAliases[$alias],
                    $origin
                ));
            }

            $usedAliases[$alias] = $origin;
        }

        return array_values($relationships);
    }

    /**
     * Builds a belongs-to relationship from a foreign key.
     *
     * @param Table $source The source table.
     * @param Table $target The referenced table.
     * @param string[] $columns The source columns.
     * @param string[] $referencedColumns The referenced columns.
     * @return RelationshipData|null The inferred relationship, or null when unsupported.
     */
    protected function belongsTo(Table $source, Table $target, array $columns, array $referencedColumns): array|null
    {
        if (count($columns) !== 1 || count($referencedColumns) !== 1) {
            return null;
        }

        $targetAlias = $target->getName() |> $this->modelAlias(...);
        $alias = $this->foreignKeyAlias($targetAlias, $columns[0]);
        $targetPrimary = $target->primaryKey() ?? [];
        $options = [];

        if ($columns[0] !== $this->modelKey($targetAlias)) {
            $options['foreignKey'] = $columns[0];
        }

        if ($referencedColumns[0] !== ($targetPrimary[0] ?? $referencedColumns[0])) {
            $options['bindingKey'] = $referencedColumns[0];
        }

        if ($alias !== $targetAlias) {
            $options['classAlias'] = $targetAlias;
        }

        return [
            'type' => static::BELONGS_TO,
            'alias' => $alias,
            'targetModel' => $targetAlias.'Model',
            'foreignKey' => $columns,
            'bindingKey' => $referencedColumns,
            'nullable' => $source->column($columns[0])->isNullable(),
            'options' => $options,
        ];
    }

    /**
     * Builds a relationship from a table that references the source table.
     *
     * @param Table $source The source table.
     * @param Table $target The referencing table.
     * @param ForeignKey $foreignKey The candidate foreign key.
     * @param string $sourceAlias The source model alias.
     * @return RelationshipData|null The inferred relationship, or null when unsupported.
     */
    protected function child(Table $source, Table $target, ForeignKey $foreignKey, string $sourceAlias): array|null
    {
        if ($foreignKey->getReferencedTable() !== $source->getName()) {
            return null;
        }

        $columns = $foreignKey->getColumns();
        $referencedColumns = $foreignKey->getReferencedColumns();

        if (count($columns) !== 1 || count($referencedColumns) !== 1) {
            return null;
        }

        $targetAlias = $target->getName() |> $this->modelAlias(...);
        $hasUniqueIndex = $target->indexes()->some(
            static fn(Index $index): bool => $index->isUnique() &&
                static::columnsMatch($index->getColumns(), $columns)
        );
        $type = $hasUniqueIndex ? static::HAS_ONE : static::HAS_MANY;
        $sourcePrimary = $source->primaryKey() ?? [];
        $options = [];

        if ($columns[0] !== $this->modelKey($sourceAlias)) {
            $options['foreignKey'] = $columns[0];
        }

        if ($referencedColumns[0] !== ($sourcePrimary[0] ?? $referencedColumns[0])) {
            $options['bindingKey'] = $referencedColumns[0];
        }

        return [
            'type' => $type,
            'alias' => $targetAlias,
            'targetModel' => $targetAlias.'Model',
            'foreignKey' => $columns,
            'bindingKey' => $referencedColumns,
            'nullable' => $target->column($columns[0])->isNullable(),
            'options' => $options,
        ];
    }

    /**
     * Builds a role-specific alias from a foreign key.
     *
     * @param string $targetAlias The target model alias.
     * @param string $foreignKey The foreign key column.
     * @return string The relationship alias.
     */
    protected function foreignKeyAlias(string $targetAlias, string $foreignKey): string
    {
        if ($foreignKey === $this->modelKey($targetAlias)) {
            return $targetAlias;
        }

        return (preg_replace('/_id\z/', '', $foreignKey) ?? $foreignKey)
            |> $this->inflector->classify(...);
    }

    /**
     * Infers an enum definition from a schema column comment.
     *
     * @param Column $column The schema column.
     * @param string $entityClass The entity class name.
     * @return EnumData|null The inferred enum definition.
     */
    protected function inferEnumFromColumn(Column $column, string $entityClass): array|null
    {
        $comment = trim($column->getComment() ?? '');

        if (!str_starts_with($comment, '[enum]')) {
            return null;
        }

        $cases = substr($comment, 6) |> trim(...);

        if ($cases === '') {
            return null;
        }

        $field = $column->getName();
        $enumCases = $this->parseEnumCases($cases);

        return [
            'field' => $field,
            'className' => $entityClass.$this->inflector->camelize($field),
            'cases' => $cases,
            'values' => $enumCases['stringBacked'] ?
                array_values($enumCases['cases']) :
                [],
        ];
    }

    /**
     * Builds a many-to-many relationship through a junction table.
     *
     * @param Table $source The source table.
     * @param Table $junction The candidate junction table.
     * @param string $sourceAlias The source model alias.
     * @return RelationshipData|null The inferred relationship, or null when unsupported.
     */
    protected function manyToMany(Table $source, Table $junction, string $sourceAlias): array|null
    {
        if ($junction->getName() === $source->getName()) {
            return null;
        }

        $foreignKeys = $junction->foreignKeys();

        if (
            count($foreignKeys) !== 2 ||
            !$foreignKeys->every(
                static fn(ForeignKey $foreignKey): bool => $foreignKey->getColumns() |> count(...) === 1 &&
                    $foreignKey->getReferencedColumns() |> count(...) === 1
            )
        ) {
            return null;
        }

        $sourceForeignKey = $foreignKeys->find(
            static fn(ForeignKey $foreignKey): bool => $foreignKey->getReferencedTable() === $source->getName()
        );
        $targetForeignKey = $foreignKeys->find(
            static fn(ForeignKey $foreignKey): bool => $foreignKey->getReferencedTable() !== $source->getName()
        );
        $targetName = $targetForeignKey?->getReferencedTable();

        if (!$sourceForeignKey || !$targetForeignKey || !$targetName || !$source->getSchema()->hasTable($targetName)) {
            return null;
        }

        $junctionColumns = [
            ...$sourceForeignKey->getColumns(),
            ...$targetForeignKey->getColumns(),
        ] |> array_unique(...);

        if (count($junctionColumns) !== 2 || !static::columnsMatch($junction->columnNames(), $junctionColumns)) {
            return null;
        }

        $hasUniqueConstraint = static::columnsMatch($junction->primaryKey() ?? [], $junctionColumns) ||
            $junction->indexes()->some(
                fn(Index $index): bool => $index->isUnique() &&
                    static::columnsMatch($index->getColumns(), $junctionColumns)
            );

        if (!$hasUniqueConstraint) {
            return null;
        }

        $targetAlias = $this->modelAlias($targetName);
        $junctionAlias = $junction->getName() |> $this->modelAlias(...);
        $sourceColumns = $sourceForeignKey->getColumns();
        $sourceReferencedColumns = $sourceForeignKey->getReferencedColumns();
        $targetColumns = $targetForeignKey->getColumns();
        $aliases = [$sourceAlias, $targetAlias];
        $options = [];

        natsort($aliases);

        if ($junctionAlias !== implode('', $aliases)) {
            $options['through'] = $junctionAlias;
        }

        if ($sourceColumns[0] !== $this->modelKey($sourceAlias)) {
            $options['foreignKey'] = $sourceColumns[0];
        }

        if ($sourceReferencedColumns[0] !== 'id') {
            $options['bindingKey'] = $sourceReferencedColumns[0];
        }

        if ($targetColumns[0] !== $this->modelKey($targetAlias)) {
            $options['targetForeignKey'] = $targetColumns[0];
        }

        return [
            'type' => static::MANY_TO_MANY,
            'alias' => $targetAlias,
            'targetModel' => $targetAlias.'Model',
            'foreignKey' => $sourceColumns,
            'bindingKey' => $sourceReferencedColumns,
            'nullable' => false,
            'options' => $options,
        ];
    }

    /**
     * Infers a model alias from a table name.
     *
     * @param string $tableName The table name.
     * @return string The model alias.
     */
    protected function modelAlias(string $tableName): string
    {
        return $this->inflector->classify($tableName)
            |> $this->inflector->pluralize(...);
    }

    /**
     * Infers the conventional foreign key for a model alias.
     *
     * @param string $alias The model alias.
     * @return string The foreign key.
     */
    protected function modelKey(string $alias): string
    {
        return $this->inflector->singularize($alias).'Id'
            |> $this->inflector->underscore(...);
    }

    /**
     * Builds model attributes.
     *
     * @param EnumData[] $enums The enum fields.
     * @param ModelRelationshipData[] $relationships The model relationships.
     * @return string The model attributes.
     */
    protected static function buildAttributes(array $enums, array $relationships): string
    {
        $lines = [];

        foreach ($enums as $enum) {
            $lines[] = '#[EnumField('.var_export($enum['field'], true).', '.$enum['className'].'::class)]';
        }

        foreach ($relationships as $relationship) {
            $attributeClass = match ($relationship['type']) {
                static::BELONGS_TO => BelongsTo::class,
                static::HAS_ONE => HasOne::class,
                static::MANY_TO_MANY => ManyToMany::class,
                default => HasMany::class,
            };
            $attribute = new ReflectionClass($attributeClass)->getShortName();
            $line = '#['.$attribute.'('.var_export($relationship['alias'], true);

            if ($relationship['options'] !== []) {
                $line .= ', [';

                foreach ($relationship['options'] as $key => $value) {
                    $line .= PHP_EOL.'    '.var_export($key, true).' => '.var_export($value, true).',';
                }

                $line .= PHP_EOL.']';
            }

            $line .= ')]';
            $lines[] = $line;
        }

        return $lines === [] ? '' : implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * Builds the model docblock.
     *
     * @param string $entityClass The entity class name.
     * @param ModelRelationshipData[] $relationships The model relationships.
     * @param class-string[] $traits The trait names.
     * @return string The model docblock.
     */
    protected static function buildDocBlock(string $entityClass, array $relationships, array $traits): string
    {
        $lines = [
            '/**',
            ' * @extends Model<'.$entityClass.'>',
        ];

        if ($relationships !== [] || $traits !== []) {
            $lines[] = ' *';
        }

        foreach ($relationships as $relationship) {
            $relationshipAlias = match ($relationship['type']) {
                static::BELONGS_TO => 'BelongsToRelationship',
                static::HAS_ONE => 'HasOneRelationship',
                static::MANY_TO_MANY => 'ManyToManyRelationship',
                default => 'HasManyRelationship',
            };
            $lines[] = ' * @property '.$relationshipAlias.
                '<static, '.$relationship['targetModel'].'> $'.$relationship['alias'];
        }

        foreach ($traits as $trait) {
            $lines[] = ' * @use '.new ReflectionClass($trait)->getShortName().'<'.$entityClass.'>';
        }

        $lines[] = ' */';

        return implode(PHP_EOL, $lines);
    }

    /**
     * Builds model configuration properties.
     *
     * @param string $connection The database connection.
     * @param string|null $table The explicitly configured table.
     * @return string The property declarations.
     */
    protected static function buildProperties(string $connection, string|null $table): string
    {
        $properties = [];

        if ($connection !== ConnectionManager::DEFAULT) {
            $properties[] = implode(PHP_EOL, [
                '    protected array $connectionKeys = [',
                '        self::WRITE => '.var_export($connection, true).',',
                '    ];',
            ]);
        }

        if ($table !== null) {
            $properties[] = '    protected string $table = '.var_export($table, true).';';
        }

        if ($properties === []) {
            return '';
        }

        return implode(PHP_EOL.PHP_EOL, $properties).PHP_EOL.PHP_EOL;
    }

    /**
     * Indents generated statements inside a method.
     *
     * @param string[] $lines The statements.
     * @return string The indented statements followed by a blank line.
     */
    protected static function buildStatements(array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        $lines = array_map(
            static fn(string $line): string => $line === '' ?
                '' :
                '        '.$line,
            $lines
        );

        return implode(PHP_EOL, $lines).PHP_EOL.PHP_EOL;
    }

    /**
     * Builds model trait declarations.
     *
     * @param class-string[] $traits The trait names.
     * @return string The trait declarations.
     */
    protected static function buildTraits(array $traits): string
    {
        if ($traits === []) {
            return '';
        }

        $traits = array_map(
            static fn(string $trait): string => '    use '.new ReflectionClass($trait)->getShortName().';',
            $traits
        );

        return implode(PHP_EOL, $traits).PHP_EOL.PHP_EOL;
    }

    /**
     * Builds the model use statements.
     *
     * @param string $namespace The model namespace.
     * @param string $entityNamespace The entity namespace.
     * @param string $entityClass The entity class name.
     * @param string $enumNamespace The enum namespace.
     * @param EnumData[] $enums The enum fields.
     * @param ModelRelationshipData[] $relationships The model relationships.
     * @param class-string[] $traits The trait names.
     * @param string[] $validator The validator statements.
     * @return string The use statements.
     */
    protected static function buildUses(
        string $namespace,
        string $entityNamespace,
        string $entityClass,
        string $enumNamespace,
        array $enums,
        array $relationships,
        array $traits,
        array $validator
    ): string {
        $aliases = [];
        $imports = [
            Model::class,
            Override::class,
            RuleSet::class,
            Validator::class,
            $entityNamespace.'\\'.$entityClass,
            ...$traits,
        ];

        if ($validator !== []) {
            $imports[] = Rule::class;
        }

        if ($enums !== []) {
            $imports[] = EnumField::class;
        }

        foreach ($enums as $enum) {
            $imports[] = $enumNamespace.'\\'.$enum['className'];
        }

        foreach ($relationships as $relationship) {
            [$attribute, $relationshipClass, $relationshipAlias] = match ($relationship['type']) {
                static::BELONGS_TO => [
                    BelongsTo::class,
                    BelongsToRelationship::class,
                    'BelongsToRelationship',
                ],
                static::HAS_ONE => [
                    HasOne::class,
                    HasOneRelationship::class,
                    'HasOneRelationship',
                ],
                static::MANY_TO_MANY => [
                    ManyToMany::class,
                    ManyToManyRelationship::class,
                    'ManyToManyRelationship',
                ],
                default => [
                    HasMany::class,
                    HasManyRelationship::class,
                    'HasManyRelationship',
                ],
            };

            $aliases[$relationshipClass] = $relationshipAlias;
            $imports[] = $attribute;
            $imports[] = $relationshipClass;
            $imports[] = $relationship['targetModelClass'];
        }

        return static::buildUseStatements($namespace, $imports, $aliases);
    }

    /**
     * Compares column sets without considering their order.
     *
     * @param string[] $first The first column set.
     * @param string[] $second The second column set.
     * @return bool Whether the column sets match.
     */
    protected static function columnsMatch(array $first, array $second): bool
    {
        return count($first) === count($second) &&
            array_diff($first, $second) === [];
    }

    /**
     * Formats a PHP array containing strings.
     *
     * @param string[] $values The values.
     * @return string The formatted array.
     */
    protected static function formatStringArray(array $values): string
    {
        $values = array_map(
            static fn(string $value): string => var_export($value, true),
            $values
        );

        return '['.implode(', ', $values).']';
    }

    /**
     * Builds RuleSet statements.
     *
     * @param Index[] $indexes The schema indexes.
     * @param ModelRelationshipData[] $relationships The model relationships.
     * @return string[] The RuleSet statements.
     */
    protected static function getRules(array $indexes, array $relationships): array
    {
        $existsInRules = [];

        foreach ($relationships as $relationship) {
            if ($relationship['type'] !== static::BELONGS_TO) {
                continue;
            }

            $arguments = [
                static::formatStringArray($relationship['foreignKey']),
                var_export($relationship['alias'], true),
            ];

            if ($relationship['nullable']) {
                $arguments[] = 'allowNullableNulls: true';
            }

            if (isset($relationship['options']['bindingKey'])) {
                $arguments[] = 'targetFields: '.static::formatStringArray($relationship['bindingKey']);
            }

            $existsInRules[] = '$rules->add(RuleSet::existsIn('.implode(', ', $arguments).'));';
        }

        $uniqueRules = [];

        foreach ($indexes as $index) {
            if (!$index->isUnique() || $index->isPrimary() || $index->getColumns() === []) {
                continue;
            }

            $uniqueRules[] = '$rules->add(RuleSet::isUnique('.static::formatStringArray($index->getColumns()).'));';
        }

        if ($existsInRules !== [] && $uniqueRules !== []) {
            $existsInRules[] = '';
        }

        return [...$existsInRules, ...$uniqueRules];
    }

    /**
     * Infers trait names from fields.
     *
     * @param Column[] $fields The schema fields.
     * @return class-string[] The trait class names.
     */
    protected static function getTraits(array $fields): array
    {
        $traits = [];
        $names = array_map(
            static fn(Column $field): string => $field->getName(),
            $fields
        );

        if (in_array('deleted', $names, true)) {
            $traits[] = SoftDeleteTrait::class;
        }

        if (in_array('created', $names, true) || in_array('modified', $names, true)) {
            $traits[] = TimestampsTrait::class;
        }

        return $traits;
    }

    /**
     * Builds validator statements.
     *
     * @param Column[] $fields The schema fields.
     * @param EnumData[] $enums The inferred enum definitions.
     * @return string[] The validator statements.
     */
    protected static function getValidator(array $fields, array $enums): array
    {
        if ($fields === []) {
            return [];
        }

        $enumValues = [];

        foreach ($enums as $enum) {
            $enumValues[$enum['field']] = $enum['values'];
        }

        $lines = [];

        foreach ($fields as $column) {
            $name = $column->getName();

            if ($column->isAutoIncrement()) {
                continue;
            }

            $rules = static::validatorRules($column, $enumValues[$name] ?? []);

            if ($rules === []) {
                continue;
            }

            if ($lines !== []) {
                $lines[] = '';
            }

            foreach ($rules as $ruleName => $rule) {
                $arguments = [
                    var_export($name, true),
                    $rule['rule'],
                ];

                foreach ($rule['options'] ?? [] as $option => $value) {
                    $arguments[] = $option.': '.var_export($value, true);
                }

                $arguments[] = 'name: '.var_export($ruleName, true);
                $lines[] = '$validator->add('.implode(', ', $arguments).');';
            }
        }

        return $lines;
    }

    /**
     * Builds validation rules for a schema field.
     *
     * @param Column $column The schema column.
     * @param string[] $enumValues The inferred enum values.
     * @return array<string, ValidatorRuleData> The rule definitions keyed by name.
     */
    protected static function validatorRules(Column $column, array $enumValues): array
    {
        $name = $column->getName();
        $type = $column->type();
        $rules = [];

        if (
            !$column->isNullable() &&
            $column->getDefault() === null &&
            !in_array($name, ['created', 'deleted', 'modified'], true)
        ) {
            $rules['required'] = [
                'rule' => 'Rule::required()',
                'options' => [
                    'on' => 'create',
                ],
            ];
        }

        if ($type instanceof IntegerType) {
            $rule = $column->isUnsigned() ? 'naturalNumber' : 'integer';
            $rules[$rule] = [
                'rule' => 'Rule::'.$rule.'()',
            ];
        } else if ($type instanceof DecimalType || $type instanceof FloatType) {
            $rules['decimal'] = [
                'rule' => 'Rule::decimal()',
            ];
        } else if ($type instanceof BooleanType) {
            $rules['boolean'] = [
                'rule' => 'Rule::boolean()',
            ];
        } else if ($type instanceof DateTimeType) {
            $rule = $column->getType();
            $rules[$rule] = [
                'rule' => match ($rule) {
                    'date' => 'Rule::date()',
                    'time' => 'Rule::time()',
                    default => 'Rule::dateTime()',
                },
            ];
        }

        if ($name === 'email' || str_ends_with($name, '_email')) {
            $rules['email'] = [
                'rule' => 'Rule::email()',
            ];
        }

        if (in_array($name, ['url', 'website'], true) || str_ends_with($name, '_url')) {
            $rules['url'] = [
                'rule' => 'Rule::url()',
            ];
        }

        if ($name === 'ip' || str_ends_with($name, '_ip')) {
            $rules['ip'] = [
                'rule' => 'Rule::ip()',
            ];
        }

        if ($column->getLength() !== null) {
            $rules['maxLength'] = [
                'rule' => 'Rule::maxLength('.$column->getLength().')',
            ];
        }

        if ($enumValues === [] && method_exists($column, 'getValues')) {
            $enumValues = $column->getValues() ?? [];
        }

        if ($enumValues !== []) {
            $rules['in'] = [
                'rule' => 'Rule::in('.static::formatStringArray($enumValues).')',
            ];
        }

        return $rules;
    }
}
