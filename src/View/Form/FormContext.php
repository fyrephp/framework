<?php
declare(strict_types=1);

namespace Fyre\View\Form;

use Fyre\Form\Form;
use Fyre\View\Form\Traits\FormSchemaTrait;
use Fyre\View\Form\Traits\ValidationTrait;
use Override;

use function max;
use function min;

/**
 * Implements a form context backed by a Form.
 *
 * @internal
 */
class FormContext extends Context
{
    use FormSchemaTrait;
    use ValidationTrait;

    /**
     * Constructs a FormContext.
     *
     * @param Form $item The form.
     */
    public function __construct(
        protected Form $item
    ) {}

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getDefaultValue(string $key): mixed
    {
        $schema = $this->item->getSchema();

        if (!$schema->hasField($key)) {
            return null;
        }

        return $schema->field($key)->getDefault();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getEnumClass(string $key): string|null
    {
        $schema = $this->item->getSchema();

        if (!$schema->hasField($key)) {
            return null;
        }

        return $schema->field($key)->getEnumClass();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getMax(string $key): float|null
    {
        $validator = $this->item->getValidator();
        $schema = $this->item->getSchema();

        $validatorMax = static::getValidatorMax($validator, $key);
        $schemaMax = static::getSchemaMax($schema, $key);

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
        $validator = $this->item->getValidator();
        $schema = $this->item->getSchema();

        $validatorMaxLength = static::getValidatorMaxLength($validator, $key);
        $schemaMaxLength = static::getSchemaMaxLength($schema, $key);

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
        $validator = $this->item->getValidator();
        $schema = $this->item->getSchema();

        $validatorMin = static::getValidatorMin($validator, $key);
        $schemaMin = static::getSchemaMin($schema, $key);

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
        return null;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getStep(string $key): float|string|null
    {
        return $this->item->getSchema() |> static::getSchemaStep(?, $key);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getType(string $key): string
    {
        return $this->item->getSchema() |> static::getSchemaType(?, $key);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getValue(string $key): mixed
    {
        return $this->item->get($key);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function isRequired(string $key): bool
    {
        return $this->item->getValidator() |> static::isValidationRequired(?, $key);
    }
}
