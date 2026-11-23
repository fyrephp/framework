<?php
declare(strict_types=1);

namespace Fyre\ORM;

use Closure;
use Fyre\Core\Traits\DebugTrait;
use Fyre\DB\Expressions\ConditionExpression;
use Fyre\DB\QueryGenerator;
use Fyre\ORM\Queries\SelectQuery;
use Fyre\Utility\Collection;
use Fyre\Utility\Inflector;
use InvalidArgumentException;
use Traversable;

use function array_all;
use function array_filter;
use function array_first;
use function array_map;
use function array_merge;
use function count;
use function in_array;
use function is_array;
use function is_numeric;
use function sprintf;

/**
 * Provides a base relationship definition between models.
 *
 * Note: Relationships infer foreign/binding keys from model metadata when not explicitly
 * configured, and support multiple loading strategies (`select`, `subquery`, `cte`).
 *
 * @template TSource of Model = Model
 * @template TTarget of Model = Model
 *
 * @mixin TTarget
 */
abstract class Relationship
{
    use DebugTrait;

    protected string $bindingKey;

    protected string $classAlias;

    /**
     * @var array<mixed>|Closure|ConditionExpression|string|null
     */
    protected array|Closure|ConditionExpression|string|null $conditions = null;

    protected bool $dependent = false;

    protected string $foreignKey;

    protected string $joinType = 'LEFT';

    protected string $propertyName;

    /**
     * @var TSource
     */
    protected Model $source;

    protected string $strategy = 'select';

    /**
     * @var TTarget
     */
    protected Model $target;

    /**
     * @var string[]
     */
    protected array $validStrategies = ['select', 'subquery', 'cte'];

    /**
     * Constructs a Relationship.
     *
     * @param ModelRegistry $modelRegistry The ModelRegistry.
     * @param Inflector $inflector The Inflector.
     * @param string $name The relationship name.
     * @param array<string, mixed> $options The relationship options.
     */
    public function __construct(
        protected ModelRegistry $modelRegistry,
        protected Inflector $inflector,
        protected string $name,
        array $options = []
    ) {
        $defaults = [
            'source',
            'classAlias',
            'propertyName',
            'foreignKey',
            'bindingKey',
            'joinType',
            'conditions',
            'dependent',
        ];

        $options['classAlias'] ??= $this->name;

        foreach ($defaults as $property) {
            if (!isset($options[$property])) {
                continue;
            }

            $this->$property = $options[$property];
        }

        if (isset($options['strategy'])) {
            $this->setStrategy($options['strategy']);
        }
    }

    /**
     * Calls a method on the target model.
     *
     * @param string $method The method name.
     * @param array<mixed> $arguments The method arguments.
     * @return mixed The result.
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->getTarget()->$method(...$arguments);
    }

    /**
     * Returns a Relationship from the target model.
     *
     * @param string $name The property name.
     * @return Relationship The Relationship instance.
     */
    public function __get(string $name): Relationship
    {
        return $this->getTarget()->$name;
    }

    /**
     * Checks whether a Relationship exists on the target model.
     *
     * @param string $name The relationship name.
     * @return bool Whether the Relationship exists.
     */
    public function __isset(string $name): bool
    {
        return $this->getTarget()->hasRelationship($name);
    }

    /**
     * Builds join data.
     *
     * Note: Join conditions are derived from the owning side/binding key configuration and
     * merged with relationship and user-provided conditions.
     *
     * @param array<string, mixed> $options The join options.
     * @return array<string, array<string, mixed>> The join data.
     */
    public function buildJoins(array $options = []): array
    {
        $source = $this->getSource();
        $target = $this->getTarget();

        $options['alias'] ??= $target->getAlias();
        $options['sourceAlias'] ??= $source->getAlias();
        $options['type'] ??= $this->joinType;
        $options['conditions'] ??= null;

        if ($this->isOwningSide()) {
            $sourceKey = $this->getBindingKey();
            $targetKey = $this->getForeignKey();
        } else {
            $sourceKey = $this->getForeignKey();
            $targetKey = $this->getBindingKey();
        }

        $targetField = $target->aliasField($targetKey, $options['alias']);
        $sourceField = $source->aliasField($sourceKey, $options['sourceAlias']);
        $joinCondition = new ConditionExpression()
            ->equalFields($targetField, $sourceField);

        $conditions = static::reduceConditions(
            $joinCondition,
            $this->conditions,
            $options['conditions']
        );

        if ($conditions === null) {
            $conditions = [];
        } else if (!is_array($conditions)) {
            $conditions = [$conditions];
        }

        return [
            $options['alias'] => [
                'table' => $target->getTable(),
                'type' => $options['type'],
                'conditions' => $conditions,
            ],
        ];
    }

    /**
     * Finds related data for entities.
     *
     * @param iterable<template-type<TSource, Model, 'TEntity'>> $entities The entities.
     * @param array<mixed>|string|null $fields The SELECT fields.
     * @param array<mixed>|string|null $contain The contain relationships.
     * @param array<array<string, mixed>>|null $join The JOIN tables.
     * @param array<mixed>|Closure|ConditionExpression|string|null $conditions The WHERE conditions.
     * @param array<string>|string|null $orderBy The ORDER BY fields.
     * @param string|string[]|null $groupBy The GROUP BY fields.
     * @param array<mixed>|string|null $having The HAVING conditions.
     * @param int|null $limit The LIMIT clause.
     * @param int|null $offset The OFFSET clause.
     * @param string|null $epilog The epilog.
     * @param string $connectionType The connection type.
     * @param string|null $alias The alias.
     * @param bool|null $autoFields Whether the query uses auto fields.
     * @param mixed ...$options The find options.
     * @return Collection<int, template-type<TTarget, Model, 'TEntity'>> The related entities.
     */
    public function findRelated(
        array|Traversable $entities,
        array|string|null $fields = null,
        array|string|null $contain = null,
        array|null $join = null,
        array|Closure|ConditionExpression|string|null $conditions = null,
        array|string|null $orderBy = null,
        array|string|null $groupBy = null,
        array|string|null $having = null,
        int|null $limit = null,
        int|null $offset = null,
        string|null $epilog = null,
        string $connectionType = Model::READ,
        string|null $alias = null,
        bool|null $autoFields = null,
        mixed ...$options
    ): Collection {
        $sourceValues = $this->getRelatedKeyValues($entities);

        if ($sourceValues === []) {
            return Collection::empty();
        }

        $query = $this->getTarget()
            ->find(
                $fields,
                $contain,
                $join,
                static::reduceConditions($conditions, $this->conditions),
                $orderBy ?? (isset($this->sort) ? $this->sort : null),
                $groupBy,
                $having,
                $limit,
                $offset,
                $epilog,
                $connectionType,
                $alias,
                $autoFields,
                ...$options
            );

        $this->findRelatedConditions($query, $sourceValues);

        if (
            $this->hasMultiple() &&
            count($sourceValues) > 1 &&
            $query->getLimit() !== null
        ) {
            $query
                ->groupLimit(
                    $query->getLimit(),
                    ($query->getAlias() |> $this->aliasTargetField(...)),
                    $query->getOffset()
                )
                ->limit(null, 0);
        }

        $result = $query->getResult();

        return new Collection($result->toList());
    }

    /**
     * Returns the binding key.
     *
     * @return string The binding key.
     */
    public function getBindingKey(): string
    {
        return $this->bindingKey ??= $this->source->getPrimaryKey()[0] ?? '';
    }

    /**
     * Returns the conditions.
     *
     * @return array<mixed>|Closure|ConditionExpression|string|null The conditions.
     */
    public function getConditions(): array|Closure|ConditionExpression|string|null
    {
        return $this->conditions;
    }

    /**
     * Returns the foreign key.
     *
     * @return string The foreign key.
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey ??= $this->source->getClassAlias()
            |> $this->modelKey(...);
    }

    /**
     * Returns the join type.
     *
     * @return string The join type.
     */
    public function getJoinType(): string
    {
        return $this->joinType;
    }

    /**
     * Returns the relationship name.
     *
     * @return string The relationship name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the relationship property name.
     *
     * @return string The relationship property name.
     */
    public function getProperty(): string
    {
        return $this->propertyName ??= $this->propertyName($this->name, $this->hasMultiple());
    }

    /**
     * Returns the source Model.
     *
     * @return TSource The Model instance for the source.
     */
    public function getSource(): Model
    {
        return $this->source;
    }

    /**
     * Returns the select strategy.
     *
     * @return string The strategy.
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Returns the target Model.
     *
     * @return TTarget The Model instance for the target.
     */
    public function getTarget(): Model
    {
        if (!isset($this->target)) {
            /** @var TTarget $target */
            $target = $this->modelRegistry->use($this->name, $this->classAlias);
            $this->target = $target;
        }

        return $this->target;
    }

    /**
     * Checks whether the relationship has multiple related items.
     *
     * @return bool Whether the relationship has multiple related items.
     */
    public function hasMultiple(): bool
    {
        return true;
    }

    /**
     * Checks whether the target is dependent.
     *
     * @return bool Whether the target is dependent.
     */
    public function isDependent(): bool
    {
        return $this->dependent;
    }

    /**
     * Checks whether the source is the owning side of the relationship.
     *
     * @return bool Whether the source is the owning side of the relationship.
     */
    public function isOwningSide(): bool
    {
        return true;
    }

    /**
     * Loads related data for entities.
     *
     * Note: Related entities are loaded in bulk and then assigned onto each entity. The
     * relationship property is marked clean (`dirty=false`) after assignment.
     *
     * @param iterable<template-type<TSource, Model, 'TEntity'>> $entities The entities.
     * @param SelectQuery<template-type<TTarget, Model, 'TEntity'>>|null $query The SelectQuery.
     * @param array<mixed>|string|null $fields The SELECT fields.
     * @param array<mixed>|string|null $contain The contain relationships.
     * @param array<array<string, mixed>>|null $join The JOIN tables.
     * @param array<mixed>|Closure|ConditionExpression|string|null $conditions The WHERE conditions.
     * @param array<string>|string|null $orderBy The ORDER BY fields.
     * @param string|string[]|null $groupBy The GROUP BY fields.
     * @param array<mixed>|string|null $having The HAVING conditions.
     * @param int|null $limit The LIMIT clause.
     * @param int|null $offset The OFFSET clause.
     * @param string|null $epilog The epilog.
     * @param string|null $strategy The select strategy.
     * @param (Closure(SelectQuery<template-type<TTarget, Model, 'TEntity'>>): SelectQuery<template-type<TTarget, Model, 'TEntity'>>)|null $callback The contain callback.
     * @param string $connectionType The connection type.
     * @param bool|null $autoFields Whether the query uses auto fields.
     * @param mixed ...$options The find options.
     */
    public function loadRelated(
        array|Traversable $entities,
        SelectQuery|null $query = null,
        array|string|null $fields = null,
        array|string|null $contain = null,
        array|null $join = null,
        array|Closure|ConditionExpression|string|null $conditions = null,
        array|string|null $orderBy = null,
        array|string|null $groupBy = null,
        array|string|null $having = null,
        int|null $limit = null,
        int|null $offset = null,
        string|null $epilog = null,
        string|null $strategy = null,
        Closure|null $callback = null,
        string $connectionType = Model::READ,
        bool|null $autoFields = null,
        mixed ...$options
    ): void {
        $sourceValues = $this->getRelatedKeyValues($entities);
        $property = $this->getProperty();
        $hasMultiple = $this->hasMultiple();

        if ($sourceValues === []) {
            foreach ($entities as $entity) {
                if (!$hasMultiple) {
                    $entity->set($property, null);
                } else {
                    $entity->set($property, []);
                }

                $entity->setDirty($property, false);
            }

            return;
        }

        $strategy ??= $this->getStrategy();
        $target = $this->getTarget();

        if ($this->isOwningSide()) {
            $sourceKey = $this->getBindingKey();
            $targetKey = $this->getForeignKey();
        } else {
            $sourceKey = $this->getForeignKey();
            $targetKey = $this->getBindingKey();
        }

        if ($fields || !($autoFields ?? true)) {
            $fields ??= [];
            $fields = (array) $fields;
            $fields[] = $target->aliasField($targetKey);
        }

        if ($query) {
            $options = array_merge($query->getOptions(), $options);

            unset($options['connectionType']);
        }

        $newQuery = $target->find(
            $fields,
            $contain,
            $join,
            static::reduceConditions($conditions, $this->conditions),
            $orderBy ?? (isset($this->sort) ? $this->sort : null),
            $groupBy,
            $having,
            $limit,
            $offset,
            $epilog,
            $connectionType,
            $target->getAlias(),
            $autoFields,
            ...$options
        );

        if ($query && in_array($strategy, ['cte', 'subquery'], true)) {
            $this->findRelatedSubquery($newQuery, $query, $strategy === 'cte');
        } else {
            $this->findRelatedConditions($newQuery, $sourceValues);
        }

        if ($callback) {
            $newQuery = $callback($newQuery);
        }

        if (
            $hasMultiple &&
            count($sourceValues) > 1 &&
            $newQuery->getLimit() !== null
        ) {
            $newQuery
                ->groupLimit(
                    $newQuery->getLimit(),
                    ($newQuery->getAlias() |> $this->aliasTargetField(...)),
                    $newQuery->getOffset()
                )
                ->limit(null, 0);
        }

        $allChildren = $newQuery->toArray();

        foreach ($entities as $entity) {
            $sourceValue = $entity->get($sourceKey);

            $children = [];
            foreach ($allChildren as $child) {
                if ($child === null) {
                    continue;
                }

                $targetValue = $child->get($targetKey);

                if ($sourceValue !== $targetValue) {
                    continue;
                }

                $children[] = $child;

                if (!$hasMultiple) {
                    break;
                }
            }

            if (!$hasMultiple) {
                $entity->set($property, array_first($children));
            } else {
                $entity->set($property, $children);
            }

            $entity->setDirty($property, false);
        }
    }

    /**
     * Saves related data for an entity.
     *
     * @param Entity $entity The entity.
     * @param bool $saveRelated Whether to save related entities.
     * @param bool $checkRules Whether to check model RuleSet.
     * @param bool $events Whether to trigger events.
     * @param bool $clean Whether to clean the entity.
     * @param mixed ...$options The save options.
     *
     * @phpstan-param template-type<TSource, Model, 'TEntity'> $entity The entity.
     *
     * @return bool Whether the save was successful.
     */
    abstract public function saveRelated(
        Entity $entity,
        bool $saveRelated = true,
        bool $checkRules = true,
        bool $checkExists = true,
        bool $events = true,
        bool $clean = true,
        mixed ...$options
    ): bool;

    /**
     * Sets the binding key.
     *
     * @param string $bindingKey The binding key.
     * @return static The Relationship.
     */
    public function setBindingKey(string $bindingKey): static
    {
        $this->bindingKey = $bindingKey;

        return $this;
    }

    /**
     * Sets the conditions.
     *
     * @param array<mixed>|Closure|ConditionExpression|string|null $conditions The conditions.
     * @return static The Relationship.
     */
    public function setConditions(array|Closure|ConditionExpression|string|null $conditions): static
    {
        $this->conditions = $conditions;

        return $this;
    }

    /**
     * Sets whether the target is dependent.
     *
     * @param bool $dependent Whether the target is dependent.
     * @return static The Relationship.
     */
    public function setDependent(bool $dependent): static
    {
        $this->dependent = $dependent;

        return $this;
    }

    /**
     * Sets the foreign key.
     *
     * @param string $foreignKey The foreign key.
     * @return static The Relationship.
     */
    public function setForeignKey(string $foreignKey): static
    {
        $this->foreignKey = $foreignKey;

        return $this;
    }

    /**
     * Sets the join type.
     *
     * @param string $joinType The join type.
     * @return static The Relationship.
     */
    public function setJoinType(string $joinType): static
    {
        $this->joinType = $joinType;

        return $this;
    }

    /**
     * Sets the property name.
     *
     * @param string $propertyName The property name.
     * @return static The Relationship instance.
     */
    public function setProperty(string $propertyName): static
    {
        $this->propertyName = $propertyName;

        return $this;
    }

    /**
     * Sets the source Model.
     *
     * @param TSource $source The Model representing the source.
     * @return static The Relationship instance.
     */
    public function setSource(Model $source): static
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Sets the select strategy.
     *
     * @param string $strategy The select strategy.
     * @return static The Relationship instance.
     *
     * @throws InvalidArgumentException If the strategy is not valid.
     */
    public function setStrategy(string $strategy): static
    {
        if (!in_array($strategy, $this->validStrategies, true)) {
            throw new InvalidArgumentException(sprintf(
                'Relationship strategy `%s` is not valid.',
                $strategy
            ));
        }

        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Sets the target Model.
     *
     * @param TTarget $target The Model representing the target.
     * @return static The Relationship instance.
     */
    public function setTarget(Model $target): static
    {
        $this->target = $target;

        return $this;
    }

    /**
     * Removes related data from entities.
     *
     * @param iterable<template-type<TSource, Model, 'TEntity'>> $entities The entities.
     * @param bool $cascade Whether to delete related children.
     * @param bool $events Whether to trigger events.
     * @param array<mixed>|Closure|ConditionExpression|string|null $conditions The WHERE conditions.
     * @param mixed ...$options The delete options.
     * @return bool Whether the unlink was successful.
     */
    public function unlinkAll(
        array|Traversable $entities,
        bool $cascade = true,
        bool $events = true,
        array|Closure|ConditionExpression|string|null $conditions = null,
        mixed ...$options
    ): bool {
        $relations = $this->findRelated($entities, ...$options, conditions: $conditions);

        if ($relations->isEmpty()) {
            return true;
        }

        $target = $this->getTarget();

        $foreignKey = $this->getForeignKey();

        if ($this->isDependent() || !$target->getSchema()->column($foreignKey)->isNullable()) {
            if (!$target->deleteMany($relations, $cascade, $events, ...$options)) {
                return false;
            }

            return true;
        }

        foreach ($relations as $relation) {
            $target->setTemporaryField($relation, $foreignKey, null);
        }

        if (!$target->saveMany($relations, ...$options)) {
            return false;
        }

        return true;
    }

    /**
     * Returns an aliased target field used to match related entities.
     *
     * @param string $alias The table alias.
     * @return string The target field.
     */
    protected function aliasTargetField(string $alias): string
    {
        $targetKey = $this->isOwningSide() ?
            $this->getForeignKey() :
            $this->getBindingKey();

        return $this->getTarget()->aliasField($targetKey, $alias);
    }

    /**
     * Attaches the find related conditions to a query.
     *
     * @param SelectQuery<template-type<TTarget, Model, 'TEntity'>> $newQuery The new SelectQuery.
     * @param mixed[] $sourceValues The source values.
     */
    protected function findRelatedConditions(SelectQuery $newQuery, array $sourceValues): void
    {
        $targetField = $newQuery->getAlias() |> $this->aliasTargetField(...);

        $conditions = $newQuery->expr();

        if (count($sourceValues) > 1) {
            $conditions->in($targetField, $sourceValues);
        } else {
            $conditions->eq($targetField, array_first($sourceValues));
        }

        $newQuery->where($conditions);
    }

    /**
     * Attaches the find related subquery to a query.
     *
     * @param SelectQuery<template-type<TTarget, Model, 'TEntity'>> $newQuery The new SelectQuery.
     * @param SelectQuery<template-type<TTarget, Model, 'TEntity'>> $query The SelectQuery.
     * @param bool $cte Whether to use CTE strategy.
     */
    protected function findRelatedSubquery(SelectQuery $newQuery, SelectQuery $query, bool $cte = false): void
    {
        if ($this->isOwningSide()) {
            $sourceKey = $this->getBindingKey();
            $targetKey = $this->getForeignKey();
        } else {
            $sourceKey = $this->getForeignKey();
            $targetKey = $this->getBindingKey();
        }

        $targetField = $this->getTarget()->aliasField($targetKey);

        $alias = $query->getAlias();
        $sourceField = $this->source->aliasField($sourceKey, $alias);

        $query = clone $query;

        $fields = $groupBy = [$sourceField];
        $orderBy = $query->getOrderBy();
        $limit = $query->getLimit();
        $offset = $query->getOffset();

        if (!$limit && $orderBy === []) {
            $limit = null;
            $offset = 0;
        } else {
            $columns = $query->getSelect();
            foreach ($orderBy as $key => $value) {
                if (is_numeric($key) || !isset($columns[$key])) {
                    continue;
                }

                $fields[$key] = $columns[$key];
            }
        }

        // disable auto alias
        Closure::bind(function(): void {
            /** @var SelectQuery $this */
            $this->autoAlias = false;
        }, $query, SelectQuery::class)();

        $query
            ->select($fields, true)
            ->contain([], true)
            ->groupBy($groupBy, true)
            ->orderBy($orderBy, true)
            ->having([], true)
            ->limit($limit)
            ->offset($offset)
            ->epilog('');

        if ($cte) {
            $targetAlias = 'Target__'.$alias;
            $sourceField = $this->source->aliasField($sourceKey, $targetAlias);
            $newQuery
                ->with([
                    $targetAlias => $query,
                ])
                ->join([
                    [
                        'table' => $targetAlias,
                        'type' => 'INNER',
                        'conditions' => $newQuery->expr()
                            ->equalFields($sourceField, $targetField),
                    ],
                ]);
        } else {
            $newQuery->join([
                [
                    'table' => $query,
                    'alias' => $alias,
                    'type' => 'INNER',
                    'conditions' => $newQuery->expr()
                        ->equalFields($sourceField, $targetField),
                ],
            ]);
        }
    }

    /**
     * Returns the related key values.
     *
     * @param iterable<template-type<TSource, Model, 'TEntity'>> $entities The entities.
     * @return mixed[] The related key values.
     */
    protected function getRelatedKeyValues(array|Traversable $entities): array
    {
        if ($this->isOwningSide()) {
            $sourceKey = $this->getBindingKey();
        } else {
            $sourceKey = $this->getForeignKey();
        }

        $sourceValues = [];

        foreach ($entities as $entity) {
            if (!$entity->hasValue($sourceKey)) {
                continue;
            }

            $sourceValues[] = $entity->get($sourceKey);
        }

        return $sourceValues;
    }

    /**
     * Returns a foreign key from a model alias.
     *
     * @param string $alias The model alias.
     * @return string The foreign key.
     */
    protected function modelKey(string $alias): string
    {
        $alias = $this->inflector->singularize($alias);
        $alias .= 'Id';

        return $this->inflector->underscore($alias);
    }

    /**
     * Returns a property name from a model alias.
     *
     * @param string $alias The model alias.
     * @param bool $plural Whether to use a plural name.
     * @return string The property name.
     */
    protected function propertyName(string $alias, bool $plural = false): string
    {
        if (!$plural) {
            $alias = $this->inflector->singularize($alias);
        }

        return $this->inflector->underscore($alias);
    }

    /**
     * Builds exclusion conditions for existing entities.
     *
     * Note: Only entities with complete primary keys contribute to the exclusion list.
     *
     * @param Model $model The Model.
     * @param Entity[] $entities The entities.
     * @return ConditionExpression|null The exclusion conditions.
     */
    protected static function excludeConditions(Model $model, array $entities): ConditionExpression|null
    {
        $primaryKeys = $model->getPrimaryKey();

        if ($primaryKeys === []) {
            return null;
        }

        $preserveValues = [];
        foreach ($entities as $entity) {
            if (!array_all($primaryKeys, $entity->hasValue(...))) {
                continue;
            }

            $preserveValues[] = $entity->extract($primaryKeys);
        }

        if ($preserveValues === []) {
            return null;
        }

        $primaryKeys = array_map(
            $model->aliasField(...),
            $primaryKeys
        );

        /**
         * @var ConditionExpression $conditions
         */
        $conditions = QueryGenerator::normalizeConditions($primaryKeys, $preserveValues);

        return new ConditionExpression()->not($conditions);
    }

    /**
     * Reduce query conditions.
     *
     * @param array<mixed>|Closure|ConditionExpression|string|null ...$conditions The conditions.
     * @return array<mixed>|Closure|ConditionExpression|string|null The reduced conditions.
     */
    protected static function reduceConditions(
        array|Closure|ConditionExpression|string|null ...$conditions
    ): array|Closure|ConditionExpression|string|null {
        $conditions = array_filter($conditions, static fn($value): bool => $value !== null);

        if ($conditions === []) {
            return null;
        }

        if (count($conditions) === 1) {
            return array_first($conditions);
        }

        $combined = [];

        foreach ($conditions as $condition) {
            if (is_array($condition)) {
                $combined = array_merge($combined, $condition);
            } else {
                $combined[] = $condition;
            }
        }

        return $combined;
    }
}
