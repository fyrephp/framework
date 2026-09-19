<?php
declare(strict_types=1);

namespace Fyre\ORM\Relationships;

use Closure;
use Fyre\Core\Container;
use Fyre\DB\Expressions\ConditionExpression;
use Fyre\ORM\Entity;
use Fyre\ORM\Model;
use Fyre\ORM\ModelRegistry;
use Fyre\ORM\Queries\SelectQuery;
use Fyre\ORM\Relationship;
use Fyre\Utility\Collection;
use Fyre\Utility\Inflector;
use InvalidArgumentException;
use Override;
use Traversable;

use function array_filter;
use function array_merge;
use function array_values;
use function assert;
use function count;
use function implode;
use function in_array;
use function is_array;
use function natsort;
use function sprintf;

/**
 * Defines a many-to-many relationship.
 *
 * Uses a junction model to link source and target entities. When loading, join row data is
 * attached to each target entity under `_joinData`.
 *
 * @template TSource of Model = Model
 * @template TTarget of Model = Model
 * @template TJunction of Model = Model
 *
 * @extends Relationship<TSource, TTarget>
 */
class ManyToMany extends Relationship
{
    /**
     * @var TJunction|null
     */
    protected Model|null $junction = null;

    protected string $saveStrategy = 'replace';

    /**
     * @var array<string>|string|null
     */
    protected array|string|null $sort = null;

    /**
     * @var HasMany<TSource, TJunction>|null
     */
    protected HasMany|null $sourceRelationship = null;

    protected string $targetForeignKey;

    /**
     * @var BelongsTo<TJunction, TTarget>|null
     */
    protected BelongsTo|null $targetRelationship = null;

    protected string $through;

    /**
     * Constructs a ManyToMany.
     *
     * @param Container $container The Container.
     * @param ModelRegistry $modelRegistry The ModelRegistry.
     * @param Inflector $inflector The Inflector.
     * @param string $name The relationship name.
     * @param array<string, mixed> $options The relationship options.
     */
    public function __construct(
        protected Container $container,
        ModelRegistry $modelRegistry,
        Inflector $inflector,
        string $name,
        array $options = []
    ) {
        parent::__construct($modelRegistry, $inflector, $name, $options);

        if (!isset($options['through'])) {
            $aliases = [
                $this->source->getClassAlias(),
                $this->name,
            ];

            natsort($aliases);

            $options['through'] = implode('', $aliases);
        }

        $this->through = $options['through'];

        if (isset($options['saveStrategy'])) {
            $this->setSaveStrategy($options['saveStrategy']);
        }

        if (isset($options['sort'])) {
            $this->setSort($options['sort']);
        }

        if (isset($options['targetForeignKey'])) {
            $this->setTargetForeignKey($options['targetForeignKey']);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Builds the JOIN sequence needed to link the source to the junction model, and the
     * junction model to the target model.
     */
    #[Override]
    public function buildJoins(array $options = []): array
    {
        $sourceJoins = $this->getSourceRelationship()->buildJoins([
            'sourceAlias' => $options['sourceAlias'] ?? null,
            'type' => $options['type'] ?? null,
        ]);

        $targetJoins = $this->getTargetRelationship()->buildJoins([
            'alias' => $options['alias'] ?? null,
            'type' => $options['type'] ?? null,
            'conditions' => $options['conditions'] ?? null,
        ]);

        return array_merge($sourceJoins, $targetJoins);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
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
        $targetRelationship = $this->getTargetRelationship();
        $joinProperty = $targetRelationship->getProperty();
        $targetName = $targetRelationship->getName();

        return $this->getSourceRelationship()
            ->findRelated(
                $entities,
                $fields,
                [
                    $targetName => $contain ?? [],
                ],
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
            )
            ->map(static function(Entity|null $child) use ($joinProperty): Entity {
                assert($child instanceof Entity);

                $realChild = $child->get($joinProperty);
                $child->unset($joinProperty);

                $realChild->set('_joinData', $child);
                $realChild->setDirty('_joinData', false);

                return $realChild;
            });
    }

    /**
     * Returns the junction Model.
     *
     * @return TJunction The Model instance for the junction.
     */
    public function getJunction(): Model
    {
        /**
         * @var TJunction
         */
        return $this->junction ??= $this->modelRegistry->use($this->through);
    }

    /**
     * Returns the save strategy.
     *
     * @return string The save strategy.
     */
    public function getSaveStrategy(): string
    {
        return $this->saveStrategy;
    }

    /**
     * Returns the sort order.
     *
     * @return array<string>|string|null The sort order.
     */
    public function getSort(): array|string|null
    {
        return $this->sort;
    }

    /**
     * Returns the source relationship.
     *
     * @return HasMany<TSource, TJunction> The HasMany instance.
     */
    public function getSourceRelationship(): HasMany
    {
        return $this->sourceRelationship ??= $this->container->build(HasMany::class, [
            'name' => $this->getJunction()->getAlias(),
            'options' => [
                'source' => $this->getSource(),
                'foreignKey' => $this->getForeignKey(),
                'bindingKey' => $this->getBindingKey(),
                'dependent' => true,
            ],
        ]);
    }

    /**
     * Returns the target foreign key.
     *
     * @return string The target foreign key.
     */
    public function getTargetForeignKey(): string
    {
        return $this->targetForeignKey ??= $this->modelKey($this->name);
    }

    /**
     * Returns the target relationship, reusing the junction's relationship when configured.
     *
     * @return BelongsTo<TJunction, TTarget> The BelongsTo instance.
     *
     * @throws InvalidArgumentException If the junction's relationship has an incompatible type, foreign key, or target.
     */
    public function getTargetRelationship(): BelongsTo
    {
        if ($this->targetRelationship) {
            return $this->targetRelationship;
        }

        $junction = $this->getJunction();
        $relationship = $junction->getRelationship($this->name);

        if ($relationship !== null) {
            if (
                !($relationship instanceof BelongsTo) ||
                $relationship->getForeignKey() !== $this->getTargetForeignKey() ||
                $relationship->getTarget() !== $this->getTarget()
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Relationship `%s` on `%s` is incompatible with the many-to-many relationship on `%s`.',
                    $this->name,
                    $junction->getAlias(),
                    $this->getSource()->getAlias()
                ));
            }

            return $this->targetRelationship = $relationship;
        }

        $this->targetRelationship = $this->container->build(BelongsTo::class, [
            'name' => $this->name,
            'options' => [
                'source' => $junction,
                'classAlias' => $this->classAlias,
                'foreignKey' => $this->getTargetForeignKey(),
            ],
        ]);

        assert($this->targetRelationship instanceof Relationship);

        $junction->addRelationship($this->targetRelationship);

        return $this->targetRelationship;
    }

    /**
     * {@inheritDoc}
     *
     * @param SelectQuery<template-type<TSource, Model, 'TEntity'>>|null $query The SelectQuery.
     * @param (Closure(SelectQuery<template-type<TJunction, Model, 'TEntity'>>): SelectQuery<template-type<TJunction, Model, 'TEntity'>>)|null $callback The contain callback.
     */
    #[Override]
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

        if ($sourceValues === []) {
            foreach ($entities as $entity) {
                $entity->set($property, []);
                $entity->setDirty($property, false);
            }

            return;
        }

        $strategy ??= $this->getStrategy();
        $junction = $this->getJunction();
        $bindingKey = $this->getBindingKey();
        $foreignKey = $this->getForeignKey();
        $targetRelationship = $this->getTargetRelationship();
        $joinProperty = $targetRelationship->getProperty();
        $targetName = $targetRelationship->getName();

        if ($fields || !($autoFields ?? true)) {
            $fields ??= [];
            $fields = (array) $fields;
            $fields[] = $junction->aliasField($foreignKey);
        }

        if ($query) {
            $options = array_merge($query->getOptions(), $options);

            unset($options['connectionType']);
        }

        $newQuery = $junction->find(
            $fields,
            [$targetName => $contain ?? []],
            $join,
            static::reduceConditions($conditions, $this->conditions),
            $orderBy ?? (isset($this->sort) ? $this->sort : null),
            $groupBy,
            $having,
            $limit,
            $offset,
            $epilog,
            $connectionType,
            $junction->getAlias(),
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
            count($sourceValues) > 1 &&
            $newQuery->getLimit() !== null
        ) {
            $newQuery
                ->groupLimit(
                    $newQuery->getLimit(),
                    $junction->aliasField($foreignKey, $newQuery->getAlias()),
                    $newQuery->getOffset()
                )
                ->limit(null, 0);
        }

        $allChildren = $newQuery
            ->getResult()
            ->map(static function(Entity $child) use ($joinProperty): Entity {
                $realChild = $child->get($joinProperty);
                $child->unset($joinProperty);

                $realChild->set('_joinData', $child);
                $realChild->setDirty('_joinData', false);

                return $realChild;
            })
            ->toArray();

        foreach ($entities as $entity) {
            $bindingValue = $entity->get($bindingKey);

            $children = [];
            foreach ($allChildren as $child) {
                $joinData = $child->get('_joinData');

                assert($joinData instanceof Entity);

                $foreignValue = $joinData->get($foreignKey);

                if ($bindingValue !== $foreignValue) {
                    continue;
                }

                $children[] = clone $child;
            }

            $entity->set($property, $children);
            $entity->setDirty($property, false);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Note: Each related entity may optionally contain `_joinData` (array or Entity) which
     * is used to populate junction table fields. The link keys are set as temporary values.
     */
    #[Override]
    public function saveRelated(
        Entity $entity,
        bool $saveRelated = true,
        bool $checkRules = true,
        bool $checkExists = true,
        bool $events = true,
        bool $clean = true,
        mixed ...$options
    ): bool {
        $relations = $this->getProperty() |> $entity->get(...);

        if ($relations === null) {
            return true;
        }

        $relations = array_filter(
            $relations,
            static fn(mixed $relation): bool => $relation && $relation instanceof Entity
        ) |> array_values(...);

        if ($relations === []) {
            if (
                $this->saveStrategy === 'replace' &&
                !$this->getSourceRelationship()->unlinkAll(
                    [$entity],
                    ...$options,
                    events: $events
                )
            ) {
                return false;
            }

            return true;
        }

        $target = $this->getTarget();
        $junction = $this->getJunction();
        $joinEntities = [];
        foreach ($relations as $relation) {
            $joinData = $relation->get('_joinData') ?? [];

            if ($joinData instanceof Entity) {
                $joinEntity = $joinData;
            } else if (is_array($joinData)) {
                $joinEntity = $junction->newEntity($joinData);
            } else {
                $joinEntity = $junction->newEmptyEntity();
            }

            $joinEntities[] = $joinEntity;
        }

        if (
            $this->saveStrategy === 'replace' &&
            !$this->getSourceRelationship()->unlinkAll(
                [$entity],
                ...$options,
                events: $events,
                conditions: static::excludeConditions($junction, $joinEntities)
            )
        ) {
            return false;
        }

        if (!$target->saveMany(
            $relations,
            $saveRelated,
            $checkRules,
            $checkExists,
            $events,
            $clean,
            ...$options
        )) {
            return false;
        }

        $foreignKey = $this->getForeignKey();
        $targetRelationship = $this->getTargetRelationship();
        $targetBindingKey = $targetRelationship->getBindingKey();
        $targetForeignKey = $targetRelationship->getForeignKey();
        $bindingValue = $this->getBindingKey() |> $entity->get(...);

        foreach ($relations as $i => $relation) {
            $joinEntity = $joinEntities[$i];
            $targetBindingValue = $relation->get($targetBindingKey);

            $junction->setTemporaryField($joinEntity, $foreignKey, $bindingValue);
            $junction->setTemporaryField($joinEntity, $targetForeignKey, $targetBindingValue);
            $target->setTemporaryField($relation, '_joinData', $joinEntity);

            if ($clean) {
                $target->getConnection()->afterCommit(static fn(): Entity => $relation->cleanFields(['_joinData' => $joinEntity]), 200);
            }
        }

        if (!$junction->saveMany(
            $joinEntities,
            $saveRelated,
            $checkRules,
            $checkExists,
            $events,
            $clean,
            ...$options
        )) {
            return false;
        }

        return true;
    }

    /**
     * Sets the junction Model.
     *
     * @param TJunction $junction The Model representing the junction table.
     * @return static The ManyToMany instance.
     */
    public function setJunction(Model $junction): static
    {
        $this->junction = $junction;

        return $this;
    }

    /**
     * Sets the save strategy.
     *
     * - `append`: Keep existing links and add new ones.
     * - `replace`: Remove existing links not present in the incoming relation set.
     *
     * @param string $saveStrategy The save strategy.
     * @return static The ManyToMany instance.
     *
     * @throws InvalidArgumentException If the strategy is not valid.
     */
    public function setSaveStrategy(string $saveStrategy): static
    {
        if (!in_array($saveStrategy, ['append', 'replace'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Relationship save strategy `%s` is not valid.',
                $saveStrategy
            ));
        }

        $this->saveStrategy = $saveStrategy;

        return $this;
    }

    /**
     * Sets the sort order.
     *
     * @param array<string>|string|null $sort The sort order.
     * @return static The ManyToMany instance.
     */
    public function setSort(array|string|null $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    /**
     * Sets the target foreign key.
     *
     * @param string $targetForeignKey The target foreign key.
     * @return static The ManyToMany instance.
     */
    public function setTargetForeignKey(string $targetForeignKey): static
    {
        $this->targetForeignKey = $targetForeignKey;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function unlinkAll(
        array|Traversable $entities,
        bool $cascade = true,
        bool $events = true,
        array|Closure|ConditionExpression|string|null $conditions = null,
        mixed ...$options
    ): bool {
        return $this->getSourceRelationship()->unlinkAll($entities, $cascade, $events, $conditions, ...$options);
    }
}
