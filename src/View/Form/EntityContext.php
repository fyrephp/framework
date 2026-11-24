<?php
declare(strict_types=1);

namespace Fyre\View\Form;

use Closure;
use Fyre\ORM\Entity;
use Fyre\ORM\Model;
use Fyre\ORM\ModelRegistry;
use Fyre\ORM\Relationship;
use Fyre\View\Form\Traits\DbSchemaTrait;
use Fyre\View\Form\Traits\ValidationTrait;
use Override;

use function array_pop;
use function array_shift;
use function explode;
use function in_array;
use function is_array;
use function max;
use function min;

/**
 * Implements a form context backed by an ORM Entity.
 *
 * Supports dot notation for traversing related entities/arrays when resolving values and
 * deriving field metadata from models.
 *
 * @internal
 */
class EntityContext extends Context
{
    use DbSchemaTrait;
    use ValidationTrait;

    protected Model $model;

    /**
     * @var array<string, array{Model|null, string}>
     */
    protected array $models = [];

    /**
     * Constructs an EntityContext.
     *
     * Note: The root model is resolved from the entity model alias.
     *
     * @param ModelRegistry $modelRegistry The ModelRegistry.
     * @param Entity $item The entity.
     */
    public function __construct(
        ModelRegistry $modelRegistry,
        protected Entity $item
    ) {
        $this->model = ((string) $item->getModelAlias()) |> $modelRegistry->use(...);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getDefaultValue(string $key): mixed
    {
        if (!$this->item->isNew()) {
            return null;
        }

        [$model, $field] = $this->getModelField($key);

        if (!$model) {
            return null;
        }

        return $model->getSchema() |> $this->getSchemaDefaultValue(?, $field);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getEnumClass(string $key): string|null
    {
        [$model, $field] = $this->getModelField($key);

        if (!$model) {
            return null;
        }

        $schema = $model->getSchema();

        if (!$schema->hasColumn($field)) {
            return null;
        }

        return $schema->column($field)->getEnumClass();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getMax(string $key): float|null
    {
        [$model, $field] = $this->getModelField($key);

        if (!$model) {
            return null;
        }

        $validator = $model->getValidator();
        $schema = $model->getSchema();

        $validatorMax = static::getValidatorMax($validator, $field);
        $schemaMax = static::getSchemaMax($schema, $field);

        if ($validatorMax !== null && $schemaMax !== null) {
            return min($validatorMax, $schemaMax);
        }

        return $validatorMax ?? $schemaMax;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getMaxLength(string $key): int|null
    {
        [$model, $field] = $this->getModelField($key);

        if (!$model) {
            return null;
        }

        $validator = $model->getValidator();
        $schema = $model->getSchema();

        $validatorMaxLength = static::getValidatorMaxLength($validator, $field);
        $schemaMaxLength = static::getSchemaMaxLength($schema, $field);

        if ($validatorMaxLength !== null && $schemaMaxLength !== null) {
            return min($validatorMaxLength, $schemaMaxLength);
        }

        return $validatorMaxLength ?? $schemaMaxLength;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getMin(string $key): float|null
    {
        [$model, $field] = $this->getModelField($key);

        if (!$model) {
            return null;
        }

        $validator = $model->getValidator();
        $schema = $model->getSchema();

        $validatorMin = static::getValidatorMin($validator, $field);
        $schemaMin = static::getSchemaMin($schema, $field);

        if ($validatorMin !== null && $schemaMin !== null) {
            return max($validatorMin, $schemaMin);
        }

        return $validatorMin ?? $schemaMin;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getOptionValues(string $key): array|null
    {
        [$model, $field] = $this->getModelField($key);

        if (!$model) {
            return null;
        }

        return $model->getSchema() |> static::getSchemaOptionValues(?, $field);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getStep(string $key): float|string|null
    {
        [$model, $field] = $this->getModelField($key);

        if (!$model) {
            return null;
        }

        return $model->getSchema() |> static::getSchemaStep(?, $field);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getType(string $key): string
    {
        [$model, $field] = $this->getModelField($key);

        if (!$model) {
            return parent::getType($key);
        }

        $relationship = static::findRelationship(
            $model,
            static fn(Relationship $relationship): bool => !$relationship->isOwningSide() && $relationship->getForeignKey() === $field
        );

        if ($relationship) {
            return 'select';
        }

        $schema = $model->getSchema();

        if (in_array($field, $schema->primaryKey() ?? [], true)) {
            return 'hidden';
        }

        return static::getSchemaType($schema, $field);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getValue(string $key): mixed
    {
        $parts = explode('.', $key);

        $value = $this->item;

        foreach ($parts as $part) {
            if ($value instanceof Entity || is_array($value)) {
                $value = $value[$part] ?? null;
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function isRequired(string $key): bool
    {
        [$model, $field] = $this->getModelField($key);

        if (!$model) {
            return parent::isRequired($key);
        }

        return $model->getValidator() |> static::isValidationRequired(?, $field);
    }

    /**
     * Returns the Model/field for a field.
     *
     * Note: Supports dot notation. When traversing a "multiple" relationship, the next
     * path segment is treated as the list index and is ignored for schema/validation
     * purposes.
     *
     * @param string $key The field key.
     * @return array{Model|null, string} The Model and field.
     */
    protected function getModelField(string $key): array
    {
        if (isset($this->models[$key])) {
            return $this->models[$key];
        }

        $parts = explode('.', $key);

        $field = array_pop($parts);

        $model = $this->model;

        while ($parts !== []) {
            $part = array_shift($parts);

            $relationship = static::findRelationship(
                $model,
                static fn(Relationship $relationship): bool => $relationship->getProperty() === $part
            );

            if (!$relationship) {
                $model = null;
                break;
            }

            $model = $relationship->getTarget();

            if ($relationship->hasMultiple()) {
                array_shift($parts);
            }
        }

        return $this->models[$key] = [$model, $field];
    }

    /**
     * Finds a relationship that passes a callback.
     *
     * @param Model $model The Model.
     * @param Closure(Relationship): bool $callback The callback test.
     * @return Relationship|null The Relationship.
     */
    protected static function findRelationship(Model $model, Closure $callback): Relationship|null
    {
        $relationships = $model->getRelationships();

        foreach ($relationships as $relationship) {
            if (!$callback($relationship)) {
                continue;
            }

            return $relationship;
        }

        return null;
    }
}
