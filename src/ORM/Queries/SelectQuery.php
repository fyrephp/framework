<?php
declare(strict_types=1);

namespace Fyre\ORM\Queries;

use ArrayObject;
use Closure;
use Fyre\Core\Traits\MacroTrait;
use Fyre\DB\Expressions\ConditionExpression;
use Fyre\DB\Pagination\CursorPage;
use Fyre\DB\ValueBinder;
use Fyre\ORM\Entity;
use Fyre\ORM\Exceptions\OrmException;
use Fyre\ORM\Exceptions\RecordNotFoundException;
use Fyre\ORM\Model;
use Fyre\ORM\Queries\Traits\ModelTrait;
use Fyre\ORM\Relationship;
use Fyre\ORM\Result;
use Override;

use function array_diff;
use function array_diff_key;
use function array_intersect_key;
use function array_key_first;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_merge;
use function assert;
use function explode;
use function is_numeric;
use function is_string;
use function sprintf;
use function str_replace;

/**
 * Builds ORM queries for SELECT operations.
 *
 * Note: The query is auto-prepared before SQL generation to apply auto-fields and contain
 * joins. Calling {@see self::sql()} will prepare and (by default) reset the query state.
 *
 * @template TEntity of Entity = Entity
 *
 * @extends \Fyre\DB\Queries\SelectQuery<TEntity>
 */
class SelectQuery extends \Fyre\DB\Queries\SelectQuery
{
    use MacroTrait;
    use ModelTrait;

    public const QUERY_METHODS = [
        'fields' => 'select',
        'contain' => 'contain',
        'join' => 'join',
        'conditions' => 'where',
        'orderBy' => 'orderBy',
        'groupBy' => 'groupBy',
        'having' => 'having',
        'limit' => 'limit',
        'offset' => 'offset',
        'epilog' => 'epilog',
    ];

    protected const VALID_CONTAIN_JOIN_OPTIONS = [
        'strategy',
        'type',
        'conditions',
        'fields',
        'autoFields',
        'contain',
    ];

    protected string $alias;

    protected bool $autoAlias = true;

    protected bool|null $autoFields = null;

    protected bool $beforeFindTriggered = false;

    protected bool $buffering = true;

    /**
     * @var array<string, mixed>
     */
    protected array $contain = [];

    protected int|null $count = null;

    /**
     * @var string[]
     */
    protected array $eagerLoadPaths = [];

    /**
     * @var array<string, string>
     */
    protected array $joinPaths = [];

    /**
     * @var array<string, Relationship>
     */
    protected array $matching = [];

    /**
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * @var array<mixed>|null
     */
    protected array|null $originalFields = null;

    /**
     * @var array<string, string>|null
     */
    protected array|null $originalJoinPaths = null;

    /**
     * @var array<array<string, mixed>>|null
     */
    protected array|null $originalJoins = null;

    protected bool $prepared = false;

    /**
     * @var Result<TEntity>|null
     */
    protected Result|null $result = null;

    /**
     * Constructs a SelectQuery.
     *
     * @param Model $model The Model.
     * @param array<mixed> $options The SelectQuery options.
     */
    public function __construct(
        protected Model $model,
        array $options = []
    ) {
        $options['alias'] ??= $this->model->getAlias();
        $options['autoFields'] ??= null;
        $options['subquery'] ??= false;
        $options['connectionType'] ??= Model::READ;
        $options['events'] ??= true;

        $this->alias = $options['alias'];
        $this->autoAlias = !$options['subquery'];
        $this->autoFields = $options['autoFields'];

        unset($options['alias'], $options['autoFields']);

        parent::__construct($this->model->getConnection($options['connectionType']), []);

        $this->from([
            $this->alias => $this->model->getTable(),
        ]);

        foreach (static::QUERY_METHODS as $key => $method) {
            if (!isset($options[$key])) {
                continue;
            }

            $this->$method($options[$key]);
        }

        $this->options = array_diff_key($options, static::QUERY_METHODS);
    }

    /**
     * Returns the results.
     *
     * Note: This executes the query if it has not already been executed and caches the
     * {@see Result} until the query is dirtied.
     *
     * @return Result<TEntity> The results.
     */
    public function all(): Result
    {
        return $this->getResult();
    }

    /**
     * Clears the result.
     *
     * @return static The SelectQuery instance.
     */
    public function clearResult(): static
    {
        $this->result = null;

        return $this;
    }

    /**
     * Sets the contain relationships.
     *
     * @param array<mixed>|string $contain The contain relationships.
     * @param bool $overwrite Whether to overwrite the existing contain.
     * @return static The SelectQuery.
     */
    public function contain(array|string $contain, bool $overwrite = false): static
    {
        $contain = Model::normalizeContain($contain, $this->model);

        if ($overwrite) {
            $this->contain = $contain['contain'];
        } else {
            $this->contain = Model::mergeContain($this->contain, $contain['contain']);
        }

        $this->dirty();

        return $this;
    }

    /**
     * Returns the result count.
     *
     * Note: This counts the current query (including any applied LIMIT/OFFSET) by wrapping
     * the query as a subquery and removing ORDER BY.
     *
     * The count value is cached until the query is dirtied.
     *
     * @return int The result count.
     */
    #[Override]
    public function count(): int
    {
        if ($this->count === null) {
            $query = clone $this;

            if ($this->options['events'] && !$this->beforeFindTriggered) {
                $this->model->dispatchEvent('ORM.beforeFind', ['query' => $query, 'options' => $this->options]);
                $query->beforeFindTriggered = true;
            }

            $this->count = $query->getConnection()
                ->select([
                    'count' => $query->func()->count(),
                ])
                ->from([
                    'count_source' => $query->orderBy([], true),
                ])
                ->execute()
                ->first()['count'] ?? 0;
        }

        return $this->count;
    }

    /**
     * Disables auto fields.
     *
     * @return static The SelectQuery.
     */
    public function disableAutoFields(): static
    {
        $this->autoFields = false;

        $this->dirty();

        return $this;
    }

    /**
     * Disables result buffering.
     *
     * @return static The SelectQuery.
     */
    public function disableBuffering(): static
    {
        $this->buffering = false;

        $this->dirty();

        return $this;
    }

    /**
     * Enables auto fields.
     *
     * @return static The SelectQuery.
     */
    public function enableAutoFields(): static
    {
        $this->autoFields = true;

        $this->dirty();

        return $this;
    }

    /**
     * Enables result buffering.
     *
     * @return static The SelectQuery.
     */
    public function enableBuffering(): static
    {
        $this->buffering = true;

        $this->dirty();

        return $this;
    }

    /**
     * Returns the first result.
     *
     * Note: When the result is not already loaded, this applies `LIMIT 1` to the query.
     *
     * @return TEntity|null The first result.
     */
    public function first(): Entity|null
    {
        if ($this->result) {
            return $this->result->first();
        }

        return $this->limit(1)->getResult()->first();
    }

    /**
     * Returns the first result or throws if no matching entity exists.
     *
     * @return TEntity The first result.
     *
     * @throws RecordNotFoundException If no matching entity exists.
     */
    public function firstOrFail(): Entity
    {
        $entity = $this->first();

        if ($entity === null) {
            throw new RecordNotFoundException(sprintf(
                'No matching entity found for model `%s`.',
                $this->model->getAlias()
            ));
        }

        return $entity;
    }

    /**
     * Returns the alias.
     *
     * @return string The alias.
     */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * Returns the connection type.
     *
     * @return string The connection type.
     */
    public function getConnectionType(): string
    {
        return $this->options['connectionType'];
    }

    /**
     * Returns the contain array.
     *
     * @return array<string, mixed> The contain array.
     */
    public function getContain(): array
    {
        return $this->contain;
    }

    /**
     * Returns the eager load paths.
     *
     * @return string[] The eager load paths.
     */
    public function getEagerLoadPaths(): array
    {
        return $this->eagerLoadPaths;
    }

    /**
     * Returns the matching array.
     *
     * @return array<string, Relationship> The matching array.
     */
    public function getMatching(): array
    {
        return $this->matching;
    }

    /**
     * Returns the query options.
     *
     * @return array<string, mixed> The query options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Returns the query result.
     *
     * Note: Results are cached on the query instance until the query is dirtied.
     * When events are enabled, this will trigger the `ORM.afterFind` event.
     *
     * @return Result<TEntity> The query result.
     */
    public function getResult(): Result
    {
        if ($this->result === null) {
            $result = $this->execute();

            $this->result = new Result($result, $this, $this->buffering);

            if ($this->options['events']) {
                $this->model->dispatchEvent('ORM.afterFind', ['result' => $this->result, 'options' => $this->options]);
            }
        }

        return $this->result;
    }

    /**
     * INNER JOINs a relationship table.
     *
     * Note: This is a convenience wrapper around {@see self::containJoin()} with an INNER join.
     *
     * @param string $contain The contain string.
     * @param array<mixed>|Closure|ConditionExpression|string $conditions The JOIN conditions.
     * @return static The SelectQuery.
     */
    public function innerJoinWith(
        string $contain,
        array|Closure|ConditionExpression|string $conditions = []
    ): static {
        return $this->containJoin($contain, $conditions, 'INNER');
    }

    /**
     * {@inheritDoc}
     *
     * @throws OrmException If a join alias is not unique.
     */
    #[Override]
    public function join(array $joins, bool $overwrite = false): static
    {
        $joins = static::normalizeJoins($joins);

        if ($overwrite) {
            $this->joins = $joins;
            $this->joinPaths = [];
            $this->matching = [];
        } else {
            $invalidJoins = array_intersect_key($joins, $this->joinPaths);

            if ($invalidJoins !== []) {
                $joinAlias = array_key_first($invalidJoins);

                throw new OrmException(sprintf(
                    'Join table alias `%s` is already used by the query.',
                    $joinAlias
                ));
            }

            $this->joins = array_merge($this->joins, $joins);
        }

        $this->dirty();

        return $this;
    }

    /**
     * LEFT JOINs a relationship table.
     *
     * Note: This is a convenience wrapper around {@see self::containJoin()} with a LEFT join.
     *
     * @param string $contain The contain string.
     * @param array<mixed>|Closure|ConditionExpression|string $conditions The JOIN conditions.
     * @return static The SelectQuery.
     */
    public function leftJoinWith(
        string $contain,
        array|Closure|ConditionExpression|string $conditions = []
    ): static {
        return $this->containJoin($contain, $conditions);
    }

    /**
     * INNER JOINs a relationship table and loads matching data.
     *
     * Note: Matching relationships are included under `_matchingData` when hydrating
     * entities.
     *
     * @param string $contain The contain string.
     * @param array<mixed>|Closure|ConditionExpression|string $conditions The JOIN conditions.
     * @return static The SelectQuery.
     */
    public function matching(
        string $contain,
        array|Closure|ConditionExpression|string $conditions = []
    ): static {
        return $this->containJoin($contain, $conditions, 'INNER', true);
    }

    /**
     * LEFT JOINs a relationship table and excludes matching rows.
     *
     * Note: This uses a `NOT EXISTS (...)` subquery to exclude matching rows.
     *
     * @param string $contain The contain string.
     * @param array<mixed>|Closure|ConditionExpression|string $conditions The JOIN conditions.
     * @return static The SelectQuery instance.
     *
     * @throws OrmException If a relationship is not valid.
     */
    public function notMatching(
        string $contain,
        array|Closure|ConditionExpression|string $conditions = []
    ): static {
        $contain = explode('.', $contain);
        $lastContain = array_key_last($contain);

        $model = $this->model;
        $sourceAlias = $this->alias;

        $query = null;

        foreach ($contain as $i => $alias) {
            $isLastContain = $i === $lastContain;

            $relationship = $model->getRelationship($alias);

            if (!$relationship) {
                throw new OrmException(sprintf(
                    'Model `%s` does not have a relationship to `%s`.',
                    $model->getAlias(),
                    $alias
                ));
            }

            $model = $relationship->getTarget();

            $joins = $relationship->buildJoins([
                'alias' => $alias,
                'sourceAlias' => $sourceAlias,
                'conditions' => $isLastContain ?
                    $conditions :
                    [],
                'type' => 'INNER',
            ]);

            foreach ($joins as $joinAlias => $join) {
                if (!$query) {
                    $query = $this->getConnection()
                        ->select()
                        ->from([
                            $joinAlias => $join['table'],
                        ])
                        ->where($join['conditions'] ?? []);
                } else {
                    $query->join([$joinAlias => $join]);
                }
            }

            $sourceAlias = $alias;
        }

        if ($query) {
            $this->expr()->notExists($query) |> $this->where(...);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function paginateByCursor(string|null $cursor = null, int $perPage = 20): CursorPage
    {
        $query = clone $this;
        $query->prepare();

        return new CursorPage($query, $cursor, $perPage);
    }

    /**
     * Prepares the query.
     *
     * Note: This triggers `ORM.beforeFind` once (when events are enabled), applies
     * auto-fields, and expands contain/join information.
     *
     * @return static The SelectQuery.
     */
    public function prepare(): static
    {
        if ($this->prepared) {
            return $this;
        }

        if ($this->options['events'] && !$this->beforeFindTriggered) {
            $this->model->dispatchEvent('ORM.beforeFind', ['query' => $this, 'options' => $this->options]);
            $this->beforeFindTriggered = true;
        }

        $this->originalFields = $this->fields;
        $this->originalJoins = $this->joins;
        $this->originalJoinPaths = $this->joinPaths;

        $this->fields = [];

        if ($this->autoFields !== false) {
            $this->autoFields($this->model, $this->alias);
        } else if (!$this->options['subquery']) {
            $this->addFields($this->model->getPrimaryKey(), $this->model, $this->alias);
        }

        $this->fields += $this->originalFields;

        foreach ($this->matching as $name => $relationship) {
            $target = $relationship->getTarget();

            if ($this->autoFields !== false) {
                $this->autoFields($target, $name);
            } else {
                $this->addFields($target->getPrimaryKey(), $target, $name);
            }
        }

        $this->containAll($this->contain, $this->model, $this->alias, $this->alias);

        $this->prepared = true;

        return $this;
    }

    /**
     * Resets the query.
     *
     * Note: This restores the original fields/joins captured by {@see self::prepare()}.
     *
     * @return static The SelectQuery.
     */
    public function reset(): static
    {
        if ($this->prepared) {
            $this->fields = $this->originalFields ?? [];
            $this->joins = $this->originalJoins ?? [];
            $this->joinPaths = $this->originalJoinPaths ?? [];

            $this->originalFields = null;
            $this->originalJoins = null;
            $this->originalJoinPaths = null;
            $this->prepared = false;
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function select(array|string $fields = '*', bool $overwrite = false): static
    {
        if ($overwrite) {
            $this->fields = [];
        }

        $this->addFields((array) $fields, $this->model, $this->alias);

        if ($this->fields !== []) {
            $this->autoFields ??= false;
        }

        $this->dirty();

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * Note: This prepares the query before compilation. When `$reset` is true, the query
     * is reset back to its pre-prepared state after SQL generation.
     *
     * @param bool $reset Whether to reset the prepared query.
     */
    #[Override]
    public function sql(ValueBinder|null $binder = null, bool $reset = true): string
    {
        $this->prepare();

        $sql = parent::sql($binder);

        if ($reset) {
            $this->reset();
        }

        return $sql;
    }

    /**
     * Returns the results as an array.
     *
     * Note: This executes the query if it has not already been executed.
     *
     * @return array<TEntity> The results.
     */
    #[Override]
    public function toArray(): array
    {
        return $this->getResult()->toArray();
    }

    /**
     * Adds SELECT fields.
     *
     * @param array<mixed> $fields The fields to add.
     * @param Model $model The Model.
     * @param string $alias The table alias.
     * @param bool $overwrite Whether to overwrite existing fields.
     * @param bool $prefixAlias Whether to force prefix the alias.
     */
    protected function addFields(array $fields, Model $model, string $alias, bool $overwrite = true, bool $prefixAlias = false): void
    {
        foreach ($fields as $name => $field) {
            if ($field === '*') {
                $this->autoFields($model, $alias);

                continue;
            }

            if (is_string($field)) {
                $field = $model->aliasField($field, $alias);
            }

            if (!$this->autoAlias && is_numeric($name)) {
                $this->fields[] = $field;

                continue;
            }

            if (is_numeric($name)) {
                $name = str_replace('.', '__', $field);
            } else if ($prefixAlias) {
                $name = $alias.'__'.$name;
            }

            if (!$overwrite && isset($this->fields[$name])) {
                continue;
            }

            $this->fields[$name] = $field;
        }
    }

    /**
     * Automatically adds SELECT fields from a Model schema.
     *
     * @param Model $model The Model.
     * @param string $alias The table alias.
     */
    protected function autoFields(Model $model, string $alias): void
    {
        $columns = $model->getSchema(Model::READ)->columnNames();

        $this->addFields($columns, $model, $alias, false);
    }

    /**
     * Adds contain relationships to query.
     *
     * @param array<string, mixed> $contain The contain relationships.
     * @param Model $model The Model.
     * @param string $alias The table alias.
     * @param string $pathPrefix The path prefix.
     *
     * @throws OrmException If an invalid contain join option is used or a join alias is not unique.
     */
    protected function containAll(array $contain, Model $model, string $alias, string $pathPrefix): void
    {
        foreach ($contain as $name => $data) {
            $relationship = $model->getRelationship($name);

            assert($relationship instanceof Relationship);

            $target = $relationship->getTarget();

            $data['strategy'] ??= $relationship->getStrategy();

            if ($data['strategy'] !== 'join') {
                if ($relationship->isOwningSide()) {
                    $bindingKey = $relationship->getBindingKey();
                    $this->addFields([$bindingKey], $model, $alias);
                } else {
                    $foreignKey = $relationship->getForeignKey();
                    $this->addFields([$foreignKey], $model, $alias);
                }

                $this->eagerLoadPaths[] = $pathPrefix.'.'.$name;

                continue;
            }

            $dataKeys = array_keys($data);
            $invalidKeys = array_diff($dataKeys, static::VALID_CONTAIN_JOIN_OPTIONS);

            foreach ($invalidKeys as $invalidKey) {
                throw new OrmException(sprintf(
                    'Contain option `%s` cannot be used with the join strategy.',
                    (string) $invalidKey
                ));
            }

            $path = $pathPrefix.'.'.$name;
            $joinType = $data['type'] ?? $relationship->getJoinType();
            $joinConditions = static::normalizeJoinConditions($data['conditions'] ?? []);

            $joins = $relationship->buildJoins([
                'alias' => $name,
                'sourceAlias' => $alias,
                'type' => $joinType,
                'conditions' => $joinConditions,
            ]);

            $joinPath = $pathPrefix;
            $joinExists = true;

            foreach ($joins as $joinAlias => $join) {
                $joinPath .= '.'.$joinAlias;

                if (($this->joinPaths[$joinAlias] ?? null) !== $joinPath) {
                    $joinExists = false;

                    break;
                }
            }

            if ($this->options['events'] && !$joinExists) {
                $join = new ArrayObject([
                    'type' => $joinType,
                    'conditions' => $joinConditions,
                ]);

                $target->dispatchEvent('ORM.buildJoin', [
                    'query' => $this,
                    'relationship' => $relationship,
                    'join' => $join,
                    'mode' => 'contain',
                    'path' => $path,
                    'alias' => $name,
                    'sourceAlias' => $alias,
                    'options' => $this->options,
                ]);

                $joinType = $join['type'] ?? $joinType;
                $joinConditions = static::normalizeJoinConditions($join['conditions'] ?? []);

                $joins = $relationship->buildJoins([
                    'alias' => $name,
                    'sourceAlias' => $alias,
                    'type' => $joinType,
                    'conditions' => $joinConditions,
                ]);
            }

            $data['type'] = $joinType;
            $data['conditions'] = $joinConditions;

            $lastJoin = array_key_last($joins);
            $path = $pathPrefix;
            foreach ($joins as $joinAlias => $join) {
                $path .= '.'.$joinAlias;

                if (
                    isset($this->joins[$joinAlias]) &&
                    (
                        !isset($this->joinPaths[$joinAlias]) ||
                        $this->joinPaths[$joinAlias] !== $path
                    )
                ) {
                    throw new OrmException(sprintf(
                        'Join table alias `%s` is already used by the query.',
                        $joinAlias
                    ));
                }

                if (!isset($this->joins[$joinAlias])) {
                    $this->joins[$joinAlias] = $join;
                } else {
                    if ($join['type'] === 'INNER') {
                        $this->joins[$joinAlias]['type'] = $join['type'];
                    }

                    if ($joinAlias === $lastJoin) {
                        $this->joins[$joinAlias]['conditions'] = array_merge($this->joins[$joinAlias]['conditions'], $data['conditions']);
                    }
                }

                $this->joinPaths[$joinAlias] ??= $path;
            }

            if (isset($data['fields'])) {
                $this->addFields($data['fields'], $target, $name, prefixAlias: true);
            }

            $data['autoFields'] ??= $this->autoFields;

            if ($data['autoFields'] !== false) {
                $this->autoFields($target, $name);
            } else {
                $this->addFields($target->getPrimaryKey(), $target, $name);
            }

            $this->containAll($data['contain'], $target, $name, $path);
        }
    }

    /**
     * Adds a relationship JOIN.
     *
     * @param string $contain The contain string.
     * @param array<mixed>|Closure|ConditionExpression|string $conditions The JOIN conditions.
     * @param string $type The JOIN type.
     * @param bool|null $matching Whether this is a matching/noMatching join.
     * @return static The SelectQuery instance.
     *
     * @throws OrmException If a relationship is not valid or a join alias is not unique.
     */
    protected function containJoin(
        string $contain,
        array|Closure|ConditionExpression|string $conditions,
        string $type = 'LEFT',
        bool|null $matching = null
    ): static {
        $contain = explode('.', $contain);
        $lastContain = array_key_last($contain);

        $mode = match (true) {
            $matching === true => 'matching',
            $matching === false => 'notMatching',
            $type === 'INNER' => 'innerJoinWith',
            default => 'leftJoinWith',
        };

        $model = $this->model;
        $sourceAlias = $this->alias;
        $path = $this->alias;

        foreach ($contain as $i => $alias) {
            $isLastContain = $i === $lastContain;

            $relationship = $model->getRelationship($alias);

            if (!$relationship) {
                throw new OrmException(sprintf(
                    'Model `%s` does not have a relationship to `%s`.',
                    $model->getAlias(),
                    $alias
                ));
            }

            $model = $relationship->getTarget();
            $relationshipPath = $path.'.'.$alias;
            $joinType = $type;
            $joinConditions = $isLastContain ?
                static::normalizeJoinConditions($conditions) :
                [];

            if ($this->options['events']) {
                $join = new ArrayObject([
                    'type' => $joinType,
                    'conditions' => $joinConditions,
                ]);

                $model->dispatchEvent('ORM.buildJoin', [
                    'query' => $this,
                    'relationship' => $relationship,
                    'join' => $join,
                    'mode' => $mode,
                    'path' => $relationshipPath,
                    'alias' => $alias,
                    'sourceAlias' => $sourceAlias,
                    'options' => $this->options,
                ]);

                $joinType = $join['type'] ?? $joinType;
                $joinConditions = static::normalizeJoinConditions($join['conditions'] ?? []);
            }

            $joins = $relationship->buildJoins([
                'alias' => $alias,
                'sourceAlias' => $sourceAlias,
                'conditions' => $joinConditions,
                'type' => $joinType,
            ]);

            foreach ($joins as $joinAlias => $join) {
                $path .= '.'.$joinAlias;

                if (
                    isset($this->joins[$joinAlias]) &&
                    (
                        !isset($this->joinPaths[$joinAlias]) ||
                        $this->joinPaths[$joinAlias] !== $path
                    )
                ) {
                    throw new OrmException(sprintf(
                        'Join table alias `%s` is already used by the query.',
                        $joinAlias
                    ));
                }

                if ($isLastContain || !isset($this->joins[$joinAlias])) {
                    $this->joins[$joinAlias] = $join;
                } else if ($join['type'] === 'INNER') {
                    $this->joins[$joinAlias]['type'] = $join['type'];
                }

                $this->joinPaths[$joinAlias] ??= $path;
            }

            if ($isLastContain) {
                if ($matching === true) {
                    $this->matching[$alias] = $relationship;
                } else if ($matching === false) {
                    $primaryKeys = array_map(
                        static fn(string $primaryKey): string => $model->aliasField($primaryKey, $alias),
                        $model->getPrimaryKey()
                    );

                    $conditions = $this->expr();

                    foreach ($primaryKeys as $primaryKey) {
                        $conditions->isNull($primaryKey);
                    }

                    $this->where($conditions);
                }
            }

            $sourceAlias = $alias;
        }

        $this->dirty();

        return $this;
    }

    /**
     * Marks the query as dirty.
     */
    #[Override]
    protected function dirty(): void
    {
        parent::dirty();

        $this->count = null;
        $this->result = null;
    }
}
