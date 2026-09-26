<?php
declare(strict_types=1);

namespace Fyre\ORM;

use Countable;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\DB\ResultSet;
use Fyre\DB\Type;
use Fyre\ORM\Queries\SelectQuery;
use Fyre\Utility\Collection;
use Fyre\Utility\EnumHelper;
use Generator;
use Iterator;
use IteratorAggregate;
use JsonSerializable;
use Override;
use Stringable;

use function array_any;
use function array_first;
use function array_merge;
use function array_reverse;
use function assert;
use function count;
use function explode;
use function in_array;

/**
 * Wraps database results and maps rows to ORM entities, supporting buffered or streaming iteration.
 *
 * Note: Iteration consumes the underlying {@see ResultSet} and will free it when complete.
 * When buffering is disabled, eager-loading can be performed incrementally while streaming
 * results.
 *
 * @template TEntity of Entity = Entity
 *
 * @mixin Collection<int, TEntity>
 *
 * @implements IteratorAggregate<int, TEntity>
 */
class Result implements Countable, IteratorAggregate, JsonSerializable, Stringable
{
    use DebugTrait;
    use MacroTrait {
        __call as protected callMacro;
    }

    protected const ENTITY_OPTIONS = [
        'guard' => false,
        'mutate' => false,
        'parse' => false,
        'validate' => false,
        'clean' => true,
        'new' => false,
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array|null $aliasMap = null;

    /**
     * @var Collection<int, TEntity>
     */
    protected Collection $collection;

    protected bool $freed = false;

    /**
     * @var ResultSet<TEntity>
     */
    protected ResultSet $result;

    /**
     * Constructs a Result.
     *
     * Note: When `$buffer` is true, the result collection is cached in memory. When
     * `$buffer` is false and the query includes eager-load paths, related data may be
     * loaded while streaming.
     *
     * @param ResultSet $result The ResultSet.
     * @param SelectQuery<TEntity> $query The SelectQuery.
     * @param bool $buffer Whether to buffer the results.
     */
    public function __construct(
        ResultSet $result,
        protected SelectQuery $query,
        bool $buffer = true
    ) {
        $eagerLoad = $this->query->getEagerLoadPaths() !== [];

        $this->result = $result
            ->decorate(function(array $row) use ($eagerLoad, $buffer): Entity {
                $entity = $this->parseRow($row) |> $this->buildEntity(...);

                if ($eagerLoad && !$buffer) {
                    static::loadContain([$entity], $this->query->getContain(), $this->query->getModel(), $this->query, $this->query->getAlias());
                }

                return $entity;
            });

        $this->collection = new Collection(function(): Generator {
            while ($this->result->valid()) {
                if ($this->freed) {
                    break;
                }

                $entity = $this->result->current();

                $this->result->key() |> $this->result->clearBuffer(...);

                $this->result->next();

                if (!$this->result->valid()) {
                    $this->free();
                }

                yield $entity;
            }

            $this->free();
        });

        if ($buffer) {
            $this->collection = $this->collection->cache();
        }

        if ($eagerLoad && $buffer) {
            static::loadContain($this->collection->toArray(), $this->query->getContain(), $this->query->getModel(), $this->query, $this->query->getAlias());
        }
    }

    /**
     * Destroys the Result.
     */
    public function __destruct()
    {
        $this->free();
    }

    /**
     * Calls a Collection method.
     *
     * @param string $method The method.
     * @param array<mixed> $arguments Arguments to pass to the method.
     * @return mixed The return value.
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (static::hasMacro($method)) {
            return $this->callMacro($method, $arguments);
        }

        return $this->collection->$method(...$arguments);
    }

    /**
     * Converts the collection to a JSON encoded string.
     *
     * @return string The JSON encoded string.
     */
    #[Override]
    public function __toString(): string
    {
        return (string) $this->collection;
    }

    /**
     * Returns the column count.
     *
     * @return int The column count.
     */
    public function columnCount(): int
    {
        return $this->result->columnCount();
    }

    /**
     * Returns the result columns.
     *
     * @return string[] The result columns.
     */
    public function columns(): array
    {
        return $this->result->columns();
    }

    /**
     * Returns the result count.
     *
     * @return int The result count.
     */
    #[Override]
    public function count(): int
    {
        return $this->result->count();
    }

    /**
     * Returns a result by index.
     *
     * Note: This iterates the collection until the index is reached; when buffering is
     * disabled this may advance the underlying result cursor.
     *
     * @param int $index The index.
     * @return TEntity|null The result.
     */
    public function fetch(int $index): Entity|null
    {
        foreach ($this as $key => $entity) {
            if ($key === $index) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * Frees the result from memory.
     *
     * Note: This is idempotent and also stops streaming iteration.
     */
    public function free(): void
    {
        if (!$this->freed) {
            $this->freed = true;
            $this->result->free();
        }
    }

    /**
     * Returns the collection Iterator.
     *
     * @return Iterator<int, TEntity> The collection Iterator.
     */
    #[Override]
    public function getIterator(): Iterator
    {
        return $this->collection->getIterator();
    }

    /**
     * Returns a Type class for a column.
     *
     * @param string $name The column name.
     * @return Type|null The Type.
     */
    public function getType(string $name): Type|null
    {
        return $this->result->getType($name);
    }

    /**
     * Converts the collection to an array for JSON serializing.
     *
     * @return mixed[] The array for serializing.
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->collection->jsonSerialize();
    }

    /**
     * Builds an entity from parsed data.
     *
     * @param array<string, mixed> $data The parsed data.
     * @return TEntity The Entity.
     */
    protected function buildEntity(array $data): Entity
    {
        $matching = $this->query->getMatching();

        foreach ($matching as $name => $relationship) {
            $data['_matchingData'][$name] = $relationship->getTarget()
                ->newEntity($data['_matchingData'][$name] ?? [], ...static::ENTITY_OPTIONS);
        }

        /** @var TEntity $entity */
        $entity = $this->query->getModel()->newEntity($data, ...static::ENTITY_OPTIONS);

        return $entity;
    }

    /**
     * Returns the alias map.
     *
     * @return array<string, array<string, mixed>> The alias map.
     */
    protected function getAliasMap(): array
    {
        if ($this->aliasMap === null) {
            $this->aliasMap = [
                $this->query->getAlias() => [
                    'model' => $this->query->getModel(),
                    'properties' => [],
                ],
            ];

            static::buildAliasMap($this->aliasMap, $this->query->getContain(), $this->query->getModel());
        }

        return (array) $this->aliasMap;
    }

    /**
     * Parses a result row.
     *
     * Note: Joined columns are expected to use the `Alias__column` naming convention.
     * Schema types are preferred when available, with result-set metadata used as a fallback.
     *
     * @param array<string, mixed> $row The row.
     * @return array<string, mixed> The parsed data.
     */
    protected function parseRow(array $row): array
    {
        $aliasMap = $this->getAliasMap();
        $matching = $this->query->getMatching();

        $data = [];

        foreach ($row as $column => $value) {
            $schema = null;
            $parts = explode('__', $column, 2);

            $pointer = &$data;
            if (
                count($parts) === 2 &&
                (
                    isset($matching[array_first($parts)]) ||
                    isset($aliasMap[array_first($parts)])
                )
            ) {
                [$alias, $column] = $parts;

                if (isset($matching[$alias])) {
                    $data['_matchingData'] ??= [];
                    $data['_matchingData'][$alias] ??= [];
                    $data['_matchingData'][$alias][$column] = $value;
                }

                if (isset($aliasMap[$alias])) {
                    $schema = $aliasMap[$alias]['model']->getSchema();
                    foreach ($aliasMap[$alias]['properties'] as $property) {
                        $pointer[$property] ??= [];
                        $pointer = & $pointer[$property];
                    }
                } else {
                    continue;
                }
            }

            if ($schema && $schema->hasColumn($column)) {
                $type = $schema->column($column)->type();
            } else {
                $type = $this->getType($column);
            }

            if ($type === null) {
                continue;
            }

            if ($schema && $schema->hasColumn($column)) {
                $schemaColumn = $schema->column($column);
                $value = $schemaColumn->type()->fromDatabase($value);
                $pointer[$column] = $schemaColumn->hasEnumClass() ?
                    EnumHelper::parseValue($schemaColumn->getEnumClass(), $value) :
                    $value;
            } else {
                $pointer[$column] = $type->fromDatabase($value);
            }
        }

        // Normalize nested joins before their parents.
        $reverseMap = array_reverse($aliasMap);

        foreach ($reverseMap as $alias) {
            if ($alias['properties'] === []) {
                continue;
            }

            $pointer = &$data;
            $pathExists = true;

            foreach ($alias['properties'] as $property) {
                if (!isset($pointer[$property])) {
                    $pathExists = false;
                    break;
                }

                $pointer = &$pointer[$property];
            }

            if (!$pathExists) {
                continue;
            }

            if (!array_any($pointer, static fn(mixed $value): bool => $value !== null && $value !== [])) {
                $pointer = null;
            }
        }

        return $data;
    }

    /**
     * Builds the alias map.
     *
     * @param array<string, array<string, mixed>> $aliasMap The alias map.
     * @param array<string, array<string, mixed>> $contain The contain relationships.
     * @param Model $model The Model.
     * @param string[] $properties The properties.
     */
    protected static function buildAliasMap(array &$aliasMap, array $contain, Model $model, array $properties = []): void
    {
        foreach ($contain as $name => $data) {
            $relationship = $model->getRelationship($name);

            assert($relationship instanceof Relationship);

            $data['strategy'] ??= $relationship->getStrategy();

            if ($data['strategy'] !== 'join' || isset($aliasMap[$name])) {
                continue;
            }

            $property = $relationship->getProperty();

            $aliasMap[$name] = [
                'model' => $relationship->getTarget(),
                'properties' => array_merge($properties, [$property]),
            ];

            static::buildAliasMap($aliasMap, $data['contain'], $relationship->getTarget(), $aliasMap[$name]['properties']);
        }
    }

    /**
     * Loads contain relationships for entities.
     *
     * @param Entity[] $entities The entities.
     * @param array<string, array<string, mixed>> $contain The contain relationships.
     * @param Model $model The Model.
     * @param SelectQuery $query The Query.
     * @param string $pathPrefix The path prefix.
     */
    protected static function loadContain(array $entities, array $contain, Model $model, SelectQuery $query, string $pathPrefix): void
    {
        if ($entities === []) {
            return;
        }

        $eagerLoadPaths = $query->getEagerLoadPaths();

        foreach ($contain as $name => $data) {
            $path = $pathPrefix.'.'.$name;

            $relationship = $model->getRelationship($name);

            assert($relationship instanceof Relationship);

            $data['strategy'] ??= $relationship->getStrategy();

            if ($data['strategy'] !== 'join' || in_array($path, $eagerLoadPaths, true)) {
                $data['connectionType'] ??= $query->getConnectionType();
                $relationship->loadRelated($entities, $query, ...$data);

                continue;
            }

            $property = $relationship->getProperty();

            $relations = [];
            foreach ($entities as $entity) {
                if (!$entity->hasValue($property)) {
                    continue;
                }

                $relations[] = $entity->get($property);
            }

            static::loadContain($relations, $data['contain'], $relationship->getTarget(), $query, $path);
        }
    }
}
