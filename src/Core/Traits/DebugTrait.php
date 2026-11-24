<?php
declare(strict_types=1);

namespace Fyre\Core\Traits;

use Fyre\Core\Attributes\SensitiveProperty;
use Fyre\Core\Attributes\SensitivePropertyArray;
use ReflectionAttribute;
use ReflectionClass;

use function array_merge;
use function array_replace_recursive;
use function get_debug_type;
use function get_object_vars;
use function is_array;
use function is_scalar;
use function ksort;

/**
 * Provides debug info with sensitive values masked.
 */
trait DebugTrait
{
    protected const DEBUG_MAX_DEPTH = 3;

    /**
     * Returns debug info for the object with sensitive values masked.
     *
     * Masking is driven by {@see SensitiveProperty} attributes on properties. Nested array keys
     * can be masked via {@see SensitivePropertyArray}.
     *
     * For masked keys, values are replaced with `[*****]` unless the original value is `null` or
     * an empty string.
     *
     * Nested arrays are expanded up to {@see self::DEBUG_MAX_DEPTH}; deeper arrays are replaced with
     * `[...]`. Non-scalar non-array values are replaced with their debug type (e.g. `[Foo\Bar]`).
     *
     * Note: Only properties accessible from the current scope are included (as per
     * {@see get_object_vars()}).
     *
     * @return array<string, mixed> The debug info data.
     */
    public function __debugInfo(): array
    {
        $secretKeys = [];

        $reflection = new ReflectionClass(static::class);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $attributes = $property->getAttributes(SensitiveProperty::class, ReflectionAttribute::IS_INSTANCEOF);

            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();
                $name = $property->getName();

                $secretKeys = array_replace_recursive($secretKeys, $instance->getSecretKeys($name));
            }
        }

        $debug = static function(array $data, array $secretKeys = [], int $depth = 1) use (&$debug): array {
            foreach ($data as $key => $value) {
                $secret = $secretKeys[$key] ?? false;

                if ($secret === true && $value !== null && $value !== '') {
                    $data[$key] = '[*****]';
                } else if ($value === null || is_scalar($value)) {
                    $data[$key] = $value;
                } else if (is_array($value)) {
                    $nestedSecretKeys = is_array($secret) ? $secret : [];

                    $data[$key] = $depth < static::DEBUG_MAX_DEPTH ?
                        $debug($value, $nestedSecretKeys, $depth + 1) :
                        '[...]';
                } else {
                    $data[$key] = '['.get_debug_type($value).']';
                }
            }

            return $data;
        };

        $data = get_object_vars($this) |> $debug(?, $secretKeys);

        ksort($data);

        return array_merge(['[class]' => static::class], $data);
    }
}
