<?php
declare(strict_types=1);

namespace Fyre\DB\Types;

use Fyre\DB\Type;
use Override;

use function is_finite;
use function is_float;
use function is_numeric;

/**
 * Represents a decimal/numeric value type.
 */
class DecimalType extends Type
{
    /**
     * {@inheritDoc}
     *
     * @return numeric-string|null The decimal string value.
     */
    #[Override]
    public function parse(mixed $value): string|null
    {
        if ($value === null || !is_numeric($value) || (is_float($value) && !is_finite($value))) {
            return null;
        }

        return (string) $value;
    }
}
