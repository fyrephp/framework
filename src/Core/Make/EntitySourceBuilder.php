<?php
declare(strict_types=1);

namespace Fyre\Core\Make;

use Fyre\Core\Make as MakeService;
use Fyre\Core\Make\Traits\UseStatementBuilderTrait;
use Fyre\DB\Schema\Column;
use Fyre\DB\Types\BinaryType;
use Fyre\DB\Types\BooleanType;
use Fyre\DB\Types\DateTimeType;
use Fyre\DB\Types\DecimalType;
use Fyre\DB\Types\FloatType;
use Fyre\DB\Types\IntegerType;
use Fyre\DB\Types\JsonType;
use Fyre\DB\Types\SetType;
use Fyre\ORM\Entity;
use Fyre\ORM\EntityLocator;
use Fyre\ORM\Relationship;
use Fyre\Utility\Inflector;
use InvalidArgumentException;
use ReflectionClass;
use UnitEnum;

use function array_intersect;
use function array_map;
use function implode;
use function in_array;
use function substr;
use function var_export;

use const PHP_EOL;

/**
 * Builds entity source code from schema metadata.
 *
 * @internal
 *
 * @phpstan-import-type RelationshipData from ModelSourceBuilder
 *
 * @phpstan-type EntityRelationshipData array{property: string, type: string, className: string}
 */
class EntitySourceBuilder
{
    use UseStatementBuilderTrait;

    /**
     * Constructs an EntitySourceBuilder.
     *
     * @param Inflector $inflector The Inflector.
     * @param EntityLocator $entityLocator The EntityLocator.
     */
    public function __construct(
        protected Inflector $inflector,
        protected EntityLocator $entityLocator
    ) {}

    /**
     * Builds an entity class.
     *
     * @param string $namespace The entity namespace.
     * @param string $className The entity class name.
     * @param Column[] $fields The schema fields.
     * @param EntityRelationshipData[] $relationships The entity relationships.
     * @return string The entity source code.
     *
     * @throws InvalidArgumentException If imported classes have the same short name.
     */
    public function build(string $namespace, string $className, array $fields, array $relationships): string
    {
        return MakeService::loadStub('entity', [
            '{namespace}' => $namespace,
            '{class}' => $className,
            '{uses}' => static::buildUses($namespace, $fields, $relationships),
            '{docblock}'.PHP_EOL => static::buildDocBlock($fields, $relationships),
            '{body}' => static::buildBody($fields),
        ]);
    }

    /**
     * Builds entity relationship metadata from inferred model relationships.
     *
     * @param RelationshipData[] $relationships The inferred model relationships.
     * @param string $namespace The entity namespace.
     * @return EntityRelationshipData[] The entity relationships.
     */
    public function buildInferredRelationshipData(array $relationships, string $namespace): array
    {
        $defaultEntityClass = $this->entityLocator->getDefaultEntityClass();

        $results = [];

        foreach ($relationships as $relationship) {
            $multiple = in_array($relationship['type'], [
                ModelSourceBuilder::HAS_MANY,
                ModelSourceBuilder::MANY_TO_MANY,
            ], true);
            $alias = $relationship['alias'];

            if (!$multiple) {
                $alias = $this->inflector->singularize($alias);
            }

            $modelAlias = substr($relationship['targetModel'], 0, -5);
            $type = $this->inflector->singularize($modelAlias);
            $className = $this->entityLocator->find($modelAlias);

            if ($className === $defaultEntityClass) {
                $className = $namespace.'\\'.$type;
            }

            $results[] = [
                'property' => $this->inflector->underscore($alias),
                'type' => $type.($multiple ? '[]' : ''),
                'className' => $className,
            ];
        }

        return $results;
    }

    /**
     * Builds entity relationship metadata from ORM relationships.
     *
     * @param Relationship[] $relationships The ORM relationships.
     * @param string $namespace The entity namespace.
     * @return EntityRelationshipData[] The entity relationships.
     */
    public function buildRelationshipData(array $relationships, string $namespace): array
    {
        $defaultEntityClass = $this->entityLocator->getDefaultEntityClass();

        $results = [];

        foreach ($relationships as $relationship) {
            $alias = $relationship->getTarget()->getAlias();
            $type = $this->inflector->singularize($alias);
            $className = $this->entityLocator->find($alias);

            if ($className === $defaultEntityClass) {
                $className = $namespace.'\\'.$type;
            }

            $results[] = [
                'property' => $relationship->getProperty(),
                'type' => $type.($relationship->hasMultiple() ? '[]' : ''),
                'className' => $className,
            ];
        }

        return $results;
    }

    /**
     * Builds the entity body.
     *
     * @param Column[] $fields The schema fields.
     * @return string The entity body.
     */
    protected static function buildBody(array $fields): string
    {
        $hidden = array_intersect(array_map(
            static fn(Column $field): string => $field->getName(),
            $fields
        ), [
            'api_token',
            'password',
            'password_hash',
            'remember_token',
            'reset_token',
            'secret',
            'token',
        ]);

        if ($hidden === []) {
            return '    //';
        }

        $lines = ['    protected array $hidden = ['];

        foreach ($hidden as $name) {
            $lines[] = '        '.var_export($name, true).',';
        }

        $lines[] = '    ];';

        return implode(PHP_EOL, $lines);
    }

    /**
     * Builds the entity docblock.
     *
     * @param Column[] $fields The schema fields.
     * @param EntityRelationshipData[] $relationships The entity relationships.
     * @return string The entity docblock.
     */
    protected static function buildDocBlock(array $fields, array $relationships): string
    {
        if ($fields === [] && $relationships === []) {
            return '';
        }

        $lines = ['/**'];

        foreach ($fields as $column) {
            $lines[] = ' * @property '.static::phpType($column).' $'.$column->getName();
        }

        foreach ($relationships as $relationship) {
            $lines[] = ' * @property '.$relationship['type'].' $'.$relationship['property'];
        }

        $lines[] = ' */';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * Builds the entity use statements.
     *
     * @param string $namespace The entity namespace.
     * @param Column[] $fields The schema fields.
     * @param EntityRelationshipData[] $relationships The entity relationships.
     * @return string The use statements.
     */
    protected static function buildUses(string $namespace, array $fields, array $relationships): string
    {
        $imports = [Entity::class];

        foreach ($fields as $column) {
            $type = $column->type();

            if ($column->hasEnumClass()) {
                $imports[] = (string) $column->getEnumClass();
            } else if ($type instanceof DateTimeType) {
                $imports[] = $type->getValueClass();
            }
        }

        foreach ($relationships as $relationship) {
            $imports[] = $relationship['className'];
        }

        return static::buildUseStatements($namespace, $imports);
    }

    /**
     * Infers a PHP type from a schema column.
     *
     * @param Column $column The schema column.
     * @return string The PHP type.
     */
    protected static function phpType(Column $column): string
    {
        if ($column->hasEnumClass()) {
            /** @var class-string<UnitEnum> $enumClass */
            $enumClass = $column->getEnumClass();
            $type = new ReflectionClass($enumClass)->getShortName();
        } else {
            $columnType = $column->type();

            $type = match (true) {
                $columnType instanceof BooleanType => 'bool',
                $columnType instanceof IntegerType => 'int',
                $columnType instanceof DecimalType => 'string',
                $columnType instanceof FloatType => 'float',
                $columnType instanceof DateTimeType => new ReflectionClass($columnType->getValueClass())->getShortName(),
                $columnType instanceof BinaryType => 'resource',
                $columnType instanceof JsonType, $columnType instanceof SetType => 'array',
                default => 'string',
            };
        }

        return $type.($column->isNullable() ? '|null' : '');
    }
}
