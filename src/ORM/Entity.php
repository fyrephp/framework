<?php
declare(strict_types=1);

namespace Fyre\ORM;

use ArrayAccess;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Utility\DateTime\AbstractDateTime;
use InvalidArgumentException;
use JsonSerializable;
use Override;
use Stringable;

use function array_combine;
use function array_diff;
use function array_diff_key;
use function array_fill_keys;
use function array_filter;
use function array_first;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_shift;
use function array_unique;
use function count;
use function explode;
use function is_array;
use function is_object;
use function is_scalar;
use function json_encode;
use function lcfirst;
use function method_exists;
use function sprintf;
use function str_replace;
use function strpos;
use function ucwords;

use const JSON_PRETTY_PRINT;

/**
 * Represents an ORM entity with field access and change tracking.
 *
 * Entities support field accessibility (guarding), mutation hooks, nested error trees, and
 * hidden/virtual fields for serialization.
 *
 * @implements ArrayAccess<string, mixed>
 */
class Entity implements ArrayAccess, JsonSerializable, Stringable
{
    use DebugTrait;
    use MacroTrait;

    /**
     * @var array<string, bool>
     */
    protected array $accessible = [
        '*' => true,
    ];

    /**
     * @var array<string, true>
     */
    protected array $dirty = [];

    /**
     * @var array<string, string[]>
     */
    protected array $errors = [];

    /**
     * @var array<string, mixed>
     */
    protected array $fields = [];

    /**
     * @var string[]
     */
    protected array $hidden = [];

    /**
     * @var array<string, mixed>
     */
    protected array $invalid = [];

    /**
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * @var array<string, true>
     */
    protected array $originalFields = [];

    /**
     * @var string[]
     */
    protected array $virtual = [];

    /**
     * Constructs an Entity.
     *
     * @param array<string, mixed> $data The data for populating the entity.
     * @param string|null $modelAlias The model alias.
     * @param bool $new Whether the entity is new.
     * @param bool $guard Whether to check field accessibility.
     * @param bool $mutate Whether to apply mutator methods.
     * @param bool $clean Whether to mark the entity as clean after populating.
     */
    public function __construct(
        array $data = [],
        protected string|null $modelAlias = null,
        protected bool $new = true,
        bool $guard = false,
        bool $mutate = true,
        bool $clean = true
    ) {
        if ($data !== []) {
            $this->setOriginalFields(array_keys($data), true);

            if ($clean && !$mutate && !$guard) {
                $this->fields = $data;

                return;
            }

            $this->fill($data, $guard, $mutate, true);
        }

        if ($clean) {
            $this->clean();
        }
    }

    /**
     * Checks whether an entity value is set.
     *
     * @param string $field The field name.
     * @return bool Whether the value is set.
     */
    public function __isset(string $field): bool
    {
        return $this->has($field);
    }

    /**
     * Sets an entity value.
     *
     * @param string $field The field name.
     * @param mixed $value The value.
     */
    public function __set(string $field, mixed $value): void
    {
        $this->set($field, $value);
    }

    /**
     * Converts the entity to a JSON encoded string.
     *
     * @return string The JSON encoded string.
     */
    #[Override]
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Unsets an entity value.
     *
     * @param string $field The field name.
     */
    public function __unset(string $field): void
    {
        $this->unset($field);
    }

    /**
     * Returns an entity value.
     *
     * @param string $field The field name.
     * @return mixed The value.
     */
    public function &__get(string $field): mixed
    {
        return $this->get($field);
    }

    /**
     * Returns an entity value.
     *
     * @param mixed $field The field name (must be a string).
     * @return mixed The value.
     */
    #[Override]
    public function &offsetGet(mixed $field): mixed
    {
        return $this->get($field);
    }

    /**
     * Returns a value from the entity.
     *
     * @param string $field The field name.
     * @return mixed The value.
     */
    public function &get(string $field): mixed
    {
        $value = null;

        if (array_key_exists($field, $this->fields)) {
            $value = &$this->fields[$field];
        }

        $method = static::mutateMethod($field, 'get');

        if ($method) {
            $value = $this->$method($value);
        }

        return $value;
    }

    /**
     * Cleans the Entity.
     *
     * @return static The Entity instance.
     */
    public function clean(): static
    {
        $this->original = [];
        $this->setOriginalFields(array_keys($this->fields), true);
        $this->dirty = [];
        $this->errors = [];
        $this->invalid = [];

        return $this;
    }

    /**
     * Cleans saved fields without discarding later changes.
     *
     * @param array<string, mixed> $fields The saved field values.
     * @return static The Entity instance.
     */
    public function cleanFields(array $fields): static
    {
        foreach ($fields as $field => $value) {
            if (!array_key_exists($field, $this->fields)) {
                continue;
            }

            $this->originalFields[$field] = true;

            if ($this->fields[$field] === $value || static::compareValues($this->fields[$field], $value)) {
                unset($this->dirty[$field], $this->original[$field], $this->errors[$field], $this->invalid[$field]);
            } else {
                $this->original[$field] = $value;
                $this->dirty[$field] = true;
            }
        }

        return $this;
    }

    /**
     * Clears values from the Entity.
     *
     * @param string[] $fields The fields to clear.
     * @return static The Entity instance.
     */
    public function clear(array $fields): static
    {
        foreach ($fields as $field) {
            $this->unset($field);
        }

        return $this;
    }

    /**
     * Extracts values from the entity.
     *
     * @param string[] $fields The fields to extract.
     * @return array<string, mixed> The extracted values.
     */
    public function extract(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = $this->get($field);
        }

        return $result;
    }

    /**
     * Extracts dirty values from the entity.
     *
     * @param string[]|null $fields The fields to extract.
     * @return array<string, mixed> The extracted values.
     */
    public function extractDirty(array|null $fields = null): array
    {
        $fields ??= $this->getDirty();

        $result = [];
        foreach ($fields as $field) {
            if (!$this->isDirty($field)) {
                continue;
            }

            $result[$field] = $this->get($field);
        }

        return $result;
    }

    /**
     * Extracts original values from the entity.
     *
     * @param string[] $fields The fields to extract.
     * @return array<string, mixed> The extracted values.
     */
    public function extractOriginal(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $this->original) && !isset($this->originalFields[$field])) {
                continue;
            }

            $result[$field] = $this->getOriginal($field);
        }

        return $result;
    }

    /**
     * Extracts original changed values from the entity.
     *
     * @param string[] $fields The fields to extract.
     * @return array<string, mixed> The extracted values.
     */
    public function extractOriginalChanged(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $this->original)) {
                continue;
            }

            $original = $this->getOriginal($field);
            if ($original !== $this->get($field)) {
                $result[$field] = $original;
            }
        }

        return $result;
    }

    /**
     * Fills the Entity with values.
     *
     * @param array<string, mixed> $data The data to fill.
     * @param bool $guard Whether to check field accessibility.
     * @param bool $mutate Whether to apply mutator methods.
     * @param bool $original Whether the value is the original.
     * @return static The Entity instance.
     */
    public function fill(
        array $data,
        bool $guard = true,
        bool $mutate = true,
        bool $original = false
    ): static {
        foreach ($data as $field => $value) {
            $this->set(
                $field,
                $value,
                $guard,
                $mutate,
                $original
            );
        }

        return $this;
    }

    /**
     * Fills the Entity with invalid values.
     *
     * @param array<string, mixed> $data The data to fill.
     * @param bool $overwrite Whether to overwrite existing values.
     * @return static The Entity instance.
     */
    public function fillInvalid(array $data, bool $overwrite = false): static
    {
        foreach ($data as $field => $value) {
            $this->setInvalid($field, $value, $overwrite);
        }

        return $this;
    }

    /**
     * Returns the accessible fields from the entity.
     *
     * @return array<string, bool> The accessible fields.
     */
    public function getAccessible(): array
    {
        return $this->accessible;
    }

    /**
     * Returns the dirty fields from the entity.
     *
     * @return string[] The dirty fields.
     */
    public function getDirty(): array
    {
        return array_keys($this->dirty);
    }

    /**
     * Returns the errors for an entity field.
     *
     * Note: When `$field` uses dot notation and traverses related entities/arrays, the
     * returned value may contain nested error arrays.
     *
     * @param string $field The field name.
     * @return array<mixed> The errors.
     */
    public function getError(string $field): array
    {
        if (isset($this->errors[$field])) {
            return $this->errors[$field];
        }

        if (strpos($field, '.') === false) {
            return $this->get($field) |> static::readError(...);
        }

        return static::readNestedErrors($this, $field);
    }

    /**
     * Returns all errors for the entity.
     *
     * @return array<mixed> The errors.
     */
    public function getErrors(): array
    {
        return array_diff_key($this->fields, $this->errors)
            |> array_map(static::readError(...), ?)
            |> array_filter(?, static fn(array $errors): bool => $errors !== [])
            |> array_merge($this->errors, ?);
    }

    /**
     * Returns the hidden fields from the entity.
     *
     * @return string[] The hidden fields.
     */
    public function getHidden(): array
    {
        return $this->hidden;
    }

    /**
     * Returns invalid values from the entity.
     *
     * @param string|null $field The field name.
     * @return mixed The value.
     */
    public function getInvalid(string|null $field = null): mixed
    {
        if (!$field) {
            return $this->invalid;
        }

        return $this->invalid[$field] ?? null;
    }

    /**
     * Returns the model alias.
     *
     * @return string|null The model alias.
     */
    public function getModelAlias(): string|null
    {
        return $this->modelAlias;
    }

    /**
     * Returns an original value from the entity.
     *
     * @param string $field The field name.
     * @param bool $fallback Whether to allow fallback to the current value.
     * @return mixed The value.
     *
     * @throws InvalidArgumentException If no original value exists and fallback cannot be used.
     */
    public function getOriginal(string|null $field = null, bool $fallback = true): mixed
    {
        if (!$field) {
            return array_merge($this->fields, $this->original);
        }

        if (array_key_exists($field, $this->original)) {
            return $this->original[$field];
        }

        if (!$fallback) {
            throw new InvalidArgumentException(sprintf(
                'No original value exists for the `%s` field.',
                $field
            ));
        }

        return $this->fields[$field] ?? null;
    }

    /**
     * Returns the original fields from the entity.
     *
     * @return string[] The original fields.
     */
    public function getOriginalFields(): array
    {
        return array_keys($this->originalFields);
    }

    /**
     * Returns the original values from the entity.
     *
     * @return array<string, mixed> The original values.
     */
    public function getOriginalValues(): array
    {
        $original = [];
        foreach ($this->fields as $key => $value) {
            if (array_key_exists($key, $this->original)) {
                $original[$key] = $this->original[$key];
            } else if (isset($this->originalFields[$key])) {
                $original[$key] = $value;
            }
        }

        return $original;
    }

    /**
     * Returns the virtual fields from the entity.
     *
     * @return string[] The virtual fields.
     */
    public function getVirtual(): array
    {
        return $this->virtual;
    }

    /**
     * Returns the visible fields from the entity.
     *
     * @return string[] The visible fields.
     */
    public function getVisible(): array
    {
        return array_keys($this->fields)
            |> array_merge(?, $this->virtual)
            |> array_diff(?, $this->hidden);
    }

    /**
     * Checks whether an entity value is set.
     *
     * @param string $field The field name.
     * @return bool Whether the value is set.
     */
    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    /**
     * Checks whether the entity has errors.
     *
     * @param bool $includeNested Whether to include nested entity errors.
     * @return bool Whether the entity has errors.
     */
    public function hasErrors(bool $includeNested = true): bool
    {
        if ($this->errors !== []) {
            return true;
        }

        if (!$includeNested) {
            return false;
        }

        foreach ($this->fields as $value) {
            if (static::checkError($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether an entity field has an original value.
     *
     * @param string $field The field name.
     * @return bool Whether the field has an original value.
     */
    public function hasOriginal(string $field): bool
    {
        return array_key_exists($field, $this->original);
    }

    /**
     * Checks whether an entity value is not empty.
     *
     * @param string $field The field name.
     * @return bool Whether the value is not empty.
     */
    public function hasValue(string $field): bool
    {
        return isset($this->fields[$field]) && $this->fields[$field] !== '' && $this->fields[$field] !== [];
    }

    /**
     * Checks whether an entity field is accessible.
     *
     * @param string $field The field name.
     * @return bool Whether the field is accessible.
     */
    public function isAccessible(string $field): bool
    {
        return $this->accessible[$field] ?? $this->accessible['*'] ?? false;
    }

    /**
     * Checks whether an entity field is dirty.
     *
     * @param string|null $field The field name.
     * @return bool Whether the entity field is dirty.
     */
    public function isDirty(string|null $field = null): bool
    {
        if (!$field) {
            return $this->dirty !== [];
        }

        return $this->dirty[$field] ?? false;
    }

    /**
     * Checks whether an entity is empty.
     *
     * @return bool Whether the entity is empty.
     */
    public function isEmpty(): bool
    {
        $fields = array_keys($this->fields);

        foreach ($fields as $field) {
            if ($this->hasValue($field)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks whether the entity is new.
     *
     * @return bool Whether the entity is new.
     */
    public function isNew(): bool
    {
        return $this->new;
    }

    /**
     * Checks whether an entity field is original.
     *
     * @param string $field The field name.
     * @return bool Whether the field is original.
     */
    public function isOriginalField(string $field): bool
    {
        return isset($this->originalFields[$field]);
    }

    /**
     * Converts the Entity to an array for JSON serializing.
     *
     * @return array<string, mixed> The array for serializing.
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray(true);
    }

    /**
     * Checks whether an entity value is set.
     *
     * @param mixed $field The field name (must be a string).
     * @return bool Whether the value is set.
     */
    #[Override]
    public function offsetExists(mixed $field): bool
    {
        return $this->has($field);
    }

    /**
     * Sets an entity value.
     *
     * @param mixed $field The field name (must be a string).
     * @param mixed $value The value.
     */
    #[Override]
    public function offsetSet(mixed $field, mixed $value): void
    {
        $this->set($field, $value);
    }

    /**
     * Unsets an entity value.
     *
     * @param mixed $field The field name (must be a string).
     */
    #[Override]
    public function offsetUnset(mixed $field): void
    {
        $this->unset($field);
    }

    /**
     * Sets an Entity value.
     *
     * @param string $field The field name.
     * @param mixed $value The value.
     * @param bool $guard Whether to check field accessibility.
     * @param bool $mutate Whether to apply mutator methods.
     * @param bool $original Whether the value is original.
     * @return static The Entity instance.
     */
    public function set(
        string $field,
        mixed $value,
        bool $guard = false,
        bool $mutate = true,
        bool $original = false
    ): static {
        if ($guard && !$this->isAccessible($field)) {
            return $this;
        }

        if ($mutate) {
            $method = static::mutateMethod($field, 'set');

            if ($method) {
                $value = $this->$method($value);
            }
        }

        $hasField = array_key_exists($field, $this->fields);

        if ($hasField && !$original && static::compareValues($value, $this->fields[$field])) {
            return $this;
        }

        $this->setDirty($field, true);

        if (
            $hasField &&
            !array_key_exists($field, $this->original) &&
            isset($this->originalFields[$field]) &&
            $value !== $this->fields[$field]
        ) {
            $this->original[$field] = $this->fields[$field];
        }

        if ($original) {
            $this->originalFields[$field] = true;
        }

        $this->fields[$field] = $value;

        return $this;
    }

    /**
     * Sets whether a field is accessible.
     *
     * @param string $field The field name.
     * @param bool $accessible Whether the field is accessible.
     * @return static The Entity instance.
     */
    public function setAccess(string $field, bool $accessible): static
    {
        if ($field === '*') {
            $this->accessible = [];
        }

        $this->accessible['*'] ??= true;

        if ($accessible !== $this->accessible['*']) {
            $this->accessible[$field] = $accessible;
        } else if ($field !== '*') {
            unset($this->accessible[$field]);
        }

        return $this;
    }

    /**
     * Sets whether a field is dirty.
     *
     * @param string $field The field name.
     * @param bool $dirty Whether the field is dirty.
     * @return static The Entity instance.
     */
    public function setDirty(string $field, bool $dirty = true): static
    {
        if ($dirty === false) {
            $this->originalFields[$field] = true;

            unset($this->dirty[$field], $this->original[$field]);
        } else {
            $this->dirty[$field] = true;

            unset($this->errors[$field], $this->invalid[$field]);
        }

        return $this;
    }

    /**
     * Sets errors for an Entity field.
     *
     * @param string $field The field name.
     * @param string|string[] $error The error(s).
     * @param bool $overwrite Whether to overwrite existing errors.
     * @return static The Entity instance.
     */
    public function setError(string $field, array|string $error, bool $overwrite = false): static
    {
        return $this->setErrors([$field => $error], $overwrite);
    }

    /**
     * Sets all errors for the Entity.
     *
     * @param array<string, string|string[]> $errors The errors.
     * @param bool $overwrite Whether to overwrite existing errors.
     * @return static The Entity instance.
     */
    public function setErrors(array $errors, bool $overwrite = false): static
    {
        foreach ($errors as $field => $error) {
            $error = (array) $error;

            if ($overwrite) {
                $this->errors[$field] = $error;
            } else {
                $this->errors[$field] ??= [];
                $this->errors[$field] = array_merge($this->errors[$field], $error);
            }
        }

        return $this;
    }

    /**
     * Sets hidden fields.
     *
     * @param string[] $fields The fields.
     * @param bool $merge Whether to merge with existing fields.
     * @return static The Entity instance.
     */
    public function setHidden(array $fields, bool $merge = false): static
    {
        if ($merge) {
            $fields = array_merge($this->hidden, $fields);
        }

        $this->hidden = array_unique($fields);

        return $this;
    }

    /**
     * Sets an invalid value.
     *
     * @param string $field The field name.
     * @param mixed $value The value.
     * @param bool $overwrite Whether to overwrite an existing value.
     * @return static The Entity instance.
     */
    public function setInvalid(string $field, mixed $value, bool $overwrite = true): static
    {
        if ($overwrite || !array_key_exists($field, $this->invalid)) {
            $this->invalid[$field] = $value;
        }

        return $this;
    }

    /**
     * Sets the model alias.
     *
     * @param string $modelAlias The model alias.
     * @return static The Entity instance.
     */
    public function setModelAlias(string $modelAlias): static
    {
        $this->modelAlias = $modelAlias;

        return $this;
    }

    /**
     * Sets whether the Entity is new.
     *
     * @param bool $new Whether the entity is new.
     * @return static The Entity instance.
     */
    public function setNew(bool $new = true): static
    {
        $this->new = $new;

        return $this;
    }

    /**
     * Sets original fields.
     *
     * @param string[] $fields The fields.
     * @param bool $overwrite Whether to overwrite existing fields.
     * @return static The Entity instance.
     */
    public function setOriginalFields(array $fields, bool $overwrite = false): static
    {
        if ($overwrite) {
            $this->originalFields = array_fill_keys($fields, true);
        } else {
            $this->originalFields += array_fill_keys($fields, true);
        }

        return $this;
    }

    /**
     * Sets virtual fields.
     *
     * @param string[] $fields The fields.
     * @param bool $merge Whether to merge with existing fields.
     * @return static The Entity instance.
     */
    public function setVirtual(array $fields, bool $merge = false): static
    {
        if ($merge) {
            $fields = array_merge($this->virtual, $fields);
        }

        $this->virtual = array_unique($fields);

        return $this;
    }

    /**
     * Converts the Entity to an array.
     *
     * @param bool $convertObjects Whether to convert objects to strings where possible.
     * @return array<string, mixed> The array.
     */
    public function toArray(bool $convertObjects = false): array
    {
        $fields = $this->getVisible();

        $values = array_map(
            function(string $field) use ($convertObjects): mixed {
                $value = $this->get($field);

                if ($value instanceof Entity) {
                    return $value->toArray($convertObjects);
                }

                if ($convertObjects) {
                    if ($value instanceof JsonSerializable) {
                        return $value->jsonSerialize();
                    }

                    if ($value instanceof Stringable) {
                        return (string) $value;
                    }
                }

                if (is_array($value)) {
                    return array_map(
                        static function(mixed $val) use ($convertObjects): mixed {
                            if ($val instanceof Entity) {
                                return $val->toArray($convertObjects);
                            }

                            return $val;
                        },
                        $value
                    );
                }

                return $value;
            },
            $fields
        );

        return array_combine($fields, $values);
    }

    /**
     * Converts the Entity to a JSON encoded string.
     *
     * @return string The JSON encoded string.
     */
    public function toJson(): string
    {
        return (string) json_encode($this, JSON_PRETTY_PRINT);
    }

    /**
     * Unsets an Entity value.
     *
     * @param string $field The field name.
     * @return static The Entity instance.
     */
    public function unset(string $field): static
    {
        unset($this->fields[$field], $this->original[$field], $this->dirty[$field]);

        return $this;
    }

    /**
     * Checks a value for errors.
     *
     * @param mixed $value The value.
     * @return bool Whether the value has errors.
     */
    protected static function checkError(mixed $value): bool
    {
        if ($value instanceof Entity) {
            return $value->hasErrors();
        }

        if (is_array($value)) {
            foreach ($value as $val) {
                if (static::checkError($val)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Compares two values.
     *
     * @param mixed $a The first value.
     * @param mixed $b The second value.
     * @return bool Whether the values are equal.
     */
    protected static function compareValues(mixed $a, mixed $b): bool
    {
        if (($a === null || is_scalar($a)) && $a === $b) {
            return true;
        }

        if (
            is_object($a) &&
            !($a instanceof Entity) &&
            !($a instanceof AbstractDateTime) &&
            $a == $b
        ) {
            return true;
        }

        if ($a instanceof AbstractDateTime && $b instanceof AbstractDateTime) {
            return $a::class === $b::class && $a->getTime() === $b->getTime();
        }

        return false;
    }

    /**
     * Returns the mutation method for a field.
     *
     * @param string $field The field name.
     * @param string $prefix The method prefix.
     * @return string|null The mutation method.
     */
    protected static function mutateMethod(string $field, string $prefix): string|null
    {
        if (static::class === Entity::class) {
            return null;
        }

        $method = ucwords($prefix.'_'.$field, '_');
        $method = str_replace('_', '', $method);
        $method = '_'.lcfirst($method);

        if (!method_exists(static::class, $method)) {
            return null;
        }

        return $method;
    }

    /**
     * Read errors from a value.
     *
     * @param mixed $value The value.
     * @param string|null $field The field name.
     * @return array<mixed> The errors.
     */
    protected static function readError(mixed $value, string|null $field = null): array
    {
        if ($value instanceof Entity) {
            return $field ?
                $value->getError($field) :
                $value->getErrors();
        }

        if (is_array($value)) {
            $fields = array_map(
                static function(mixed $val) use ($field): array {
                    if ($val instanceof Entity) {
                        return $field ?
                            $val->getError($field) :
                            $val->getErrors();
                    }

                    return [];
                },
                $value
            );

            return array_filter($fields, static fn(array $errors): bool => $errors !== []);
        }

        return [];
    }

    /**
     * Read deeply nested errors using dot notation.
     *
     * @param mixed $value The value.
     * @param string $field The field name.
     * @return array<mixed> The errors.
     */
    protected static function readNestedErrors(mixed $value, string $field): array
    {
        $path = explode('.', $field);

        while (count($path) > 1) {
            $segment = array_shift($path);

            if ($value instanceof Entity) {
                $value = $value->get($segment);
            } else {
                $value = $value[$segment] ?? null;
            }

            if (!$value) {
                return [];
            }
        }

        $field = array_first($path);

        return static::readError($value, $field);
    }
}
