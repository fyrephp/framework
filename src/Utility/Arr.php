<?php
declare(strict_types=1);

namespace Fyre\Utility;

use Closure;
use Fyre\Core\Traits\StaticMacroTrait;
use InvalidArgumentException;

use function array_all;
use function array_any;
use function array_chunk;
use function array_column;
use function array_combine;
use function array_diff;
use function array_fill;
use function array_filter;
use function array_find_key;
use function array_first;
use function array_intersect;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_last;
use function array_merge;
use function array_pad;
use function array_pop;
use function array_push;
use function array_rand;
use function array_reduce;
use function array_replace_recursive;
use function array_reverse;
use function array_search;
use function array_shift;
use function array_slice;
use function array_splice;
use function array_unique;
use function array_unshift;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function range;
use function shuffle;
use function sort;
use function usort;

use const ARRAY_FILTER_USE_BOTH;
use const ARRAY_FILTER_USE_KEY;
use const COUNT_NORMAL;
use const COUNT_RECURSIVE;
use const SORT_NATURAL;
use const SORT_NUMERIC;
use const SORT_REGULAR;
use const SORT_STRING;

/**
 * Provides array utilities and convenience wrappers around common array operations.
 *
 * Most methods are thin wrappers around built-in PHP array functions with consistent argument ordering.
 */
abstract class Arr
{
    use StaticMacroTrait;

    public const COUNT_NORMAL = COUNT_NORMAL;

    public const COUNT_RECURSIVE = COUNT_RECURSIVE;

    public const FILTER_BOTH = ARRAY_FILTER_USE_BOTH;

    public const FILTER_KEY = ARRAY_FILTER_USE_KEY;

    public const FILTER_VALUE = 0;

    /** Native LC_COLLATE sort flag. PHP 8.6 deprecates the constant name. */
    public const SORT_LOCALE = 5;

    public const SORT_NATURAL = SORT_NATURAL;

    public const SORT_NUMERIC = SORT_NUMERIC;

    public const SORT_REGULAR = SORT_REGULAR;

    public const SORT_STRING = SORT_STRING;

    /**
     * Splits an array into chunks.
     *
     * @template TValue
     *
     * @param array<TValue> $array The input array.
     * @param int $size The chunk size.
     * @param bool $preserveKeys Whether array keys are preserved.
     * @return array<TValue>[] The chunks.
     *
     * @throws InvalidArgumentException If the chunk size is invalid.
     */
    public static function chunk(array $array, int $size, bool $preserveKeys = false): array
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Chunk size must be greater than 0.');
        }

        return array_chunk($array, $size, $preserveKeys);
    }

    /**
     * Replaces values from later arrays into the first array recursively.
     *
     * @param array<mixed> $array The input array.
     * @param array<mixed> ...$replacements The replacement arrays.
     * @return array<mixed> The result array.
     */
    public static function collapse(array $array, array ...$replacements): array
    {
        return array_replace_recursive($array, ...$replacements);
    }

    /**
     * Extracts values from a single column.
     *
     * @param array<mixed>[] $arrays The rows.
     * @param array-key $key The column key.
     * @return mixed[] The column values.
     */
    public static function column(array $arrays, int|string $key): array
    {
        return array_column($arrays, $key);
    }

    /**
     * Creates an array by using one array for keys and another for its values.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey> $keys The keys array.
     * @param array<TValue> $values The values array.
     * @return array<TKey, TValue> The combined array.
     */
    public static function combine(array $keys, array $values): array
    {
        return array_combine($keys, $values);
    }

    /**
     * Counts all elements in an array.
     *
     * @param array<mixed> $array The input array.
     * @param int $mode The counting mode.
     * @return int The number of elements in the array.
     *
     * @throws InvalidArgumentException If the counting mode is invalid.
     */
    public static function count(array $array, int $mode = self::COUNT_NORMAL): int
    {
        if ($mode < self::COUNT_NORMAL || $mode > self::COUNT_RECURSIVE) {
            throw new InvalidArgumentException('Count mode must be COUNT_NORMAL or COUNT_RECURSIVE.');
        }

        return count($array, $mode);
    }

    /**
     * Finds values in the first array not present in any of the other arrays.
     *
     * @param array<mixed> $array The input array.
     * @param array<mixed> ...$arrays The arrays to use for comparison.
     * @return array<mixed> The array containing values not present in any of the other arrays.
     */
    public static function diff(array $array, array ...$arrays): array
    {
        return array_diff($array, ...$arrays);
    }

    /**
     * Splits an array into keys and values.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array The input array.
     * @return array{TKey[], TValue[]} The array keys and values.
     */
    public static function divide(array $array): array
    {
        return [
            array_keys($array),
            array_values($array),
        ];
    }

    /**
     * Flattens a multi-dimensional array using "dot" notation.
     *
     * Note: Only nested arrays are flattened; non-array values (including objects) are treated as terminal values.
     * The `$result` parameter is passed by reference to avoid copying on deep recursion.
     *
     * @param array<mixed> $array The input array.
     * @param string|null $prefix The key prefix.
     * @param array<mixed> $result The result array.
     * @return array<mixed> The flattened array.
     */
    public static function dot(array $array, string|null $prefix = null, array &$result = []): array
    {
        foreach ($array as $key => $value) {
            if ($prefix !== null) {
                $key = $prefix.'.'.$key;
            }

            if (is_array($value)) {
                static::dot($value, $key, $result);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Checks whether every element in an array passes a callback.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array The input array.
     * @param callable(TValue, TKey): bool $callback The callback function to use.
     * @return bool Whether every element in the array passes the callback.
     */
    public static function every(array $array, callable $callback): bool
    {
        return array_all($array, $callback);
    }

    /**
     * Returns an array excluding the specified keys.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array The input array.
     * @param array<TKey> $keys The keys to remove.
     * @return array<TKey, TValue> The array excluding the specified keys.
     */
    public static function except(array $array, array $keys): array
    {
        return array_filter(
            $array,
            static fn(mixed $key): bool => !in_array($key, $keys, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Fills an array with values.
     *
     * @template TValue
     *
     * @param int $amount The number of elements to insert.
     * @param TValue $value The value to insert.
     * @return TValue[] The array filled with values.
     */
    public static function fill(int $amount, mixed $value): array
    {
        return array_fill(0, $amount, $value);
    }

    /**
     * Filters elements of an array using a callback function.
     *
     * When a callback is provided, it will be called with both value and key
     * by default: fn(mixed $value, mixed $key): bool.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array The input array.
     * @param callable|null $callback The callback function to use.
     * @param int $mode The flag determining arguments sent to the callback.
     * @return array<TKey, TValue> The filtered array.
     */
    public static function filter(array $array, callable|null $callback = null, int $mode = self::FILTER_BOTH): array
    {
        return array_filter($array, $callback, $mode);
    }

    /**
     * Finds the first value in an array that satisfies a callback.
     *
     * @template TKey of array-key
     * @template TValue
     * @template TDefault
     *
     * @param array<TKey, TValue> $array The input array.
     * @param callable(TValue, TKey): bool $callback The callback function to use.
     * @param TDefault $default The default value to return.
     * @return TDefault|TValue The first value satisfying the callback, or the default value.
     */
    public static function find(array $array, callable $callback, mixed $default = null): mixed
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Finds the first key in an array that satisfies a callback.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array The input array.
     * @param callable(TValue, TKey): bool $callback The callback function to use.
     * @return TKey|null The first key satisfying the callback, or null.
     */
    public static function findKey(array $array, callable $callback): mixed
    {
        return array_find_key($array, $callback);
    }

    /**
     * Finds the last value in an array that satisfies a callback.
     *
     * @template TKey of array-key
     * @template TValue
     * @template TDefault
     *
     * @param array<TKey, TValue> $array The input array.
     * @param callable(TValue, TKey): bool $callback The callback function to use.
     * @param TDefault $default The default value to return.
     * @return TDefault|TValue The last value satisfying the callback, or the default value.
     */
    public static function findLast(array $array, callable $callback, mixed $default = null): mixed
    {
        return static::find(
            array_reverse($array, true),
            $callback,
            $default
        );
    }

    /**
     * Finds the last key in an array that satisfies a callback.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array The input array.
     * @param callable(TValue, TKey): bool $callback The callback function to use.
     * @return TKey|null The last key satisfying the callback, or null.
     */
    public static function findLastKey(array $array, callable $callback): mixed
    {
        return array_find_key(
            array_reverse($array, true),
            $callback
        );
    }

    /**
     * Returns the first element of an array.
     *
     * @template TValue
     *
     * @param array<TValue> $array The input array.
     * @return TValue|null The first element, or null for an empty array.
     */
    public static function first(array $array): mixed
    {
        return array_first($array);
    }

    /**
     * Flattens a multi-dimensional array into a single level.
     *
     * @param array<mixed> $array The input array.
     * @param int $maxDepth The maximum depth to flatten.
     * @param mixed[] $result The result array.
     * @return mixed[] The flattened array.
     *
     * @throws InvalidArgumentException If the maximum depth is invalid.
     */
    public static function flatten(array $array, int $maxDepth = 1, array &$result = []): array
    {
        if ($maxDepth < 1) {
            throw new InvalidArgumentException('Maximum depth must be greater than 0.');
        }

        foreach ($array as $value) {
            if (is_array($value)) {
                if ($maxDepth > 1) {
                    static::flatten($value, $maxDepth - 1, $result);
                } else {
                    array_push($result, ...$value);
                }
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Removes a key/value pair using "dot" notation.
     *
     * @param array<mixed> $array The input array.
     * @param string $key The key.
     * @return array<mixed> The filtered array.
     */
    public static function forgetDot(array $array, string $key): array
    {
        $keys = explode('.', $key);
        $pointer = &$array;

        while (count($keys) > 1) {
            $segment = array_shift($keys);

            if (!is_array($pointer) || !array_key_exists($segment, $pointer)) {
                return $array;
            }

            $pointer = &$pointer[$segment];
        }

        $lastKey = (string) array_shift($keys);

        if (is_array($pointer) && array_key_exists($lastKey, $pointer)) {
            unset($pointer[$lastKey]);
        }

        return $array;
    }

    /**
     * Retrieves a value using "dot" notation.
     *
     * @param array<mixed> $array The input array.
     * @param string $key The key.
     * @param mixed $default The default value to return.
     * @return mixed The value.
     */
    public static function getDot(array $array, string $key, mixed $default = null): mixed
    {
        $result = $array;

        foreach (explode('.', $key) as $key) {
            if (!is_array($result) || !array_key_exists($key, $result)) {
                return $default;
            }

            $result = $result[$key];
        }

        return $result;
    }

    /**
     * Checks whether a given element exists in an array using "dot" notation.
     *
     * @param array<mixed> $array The input array.
     * @param string $key The key to check for.
     * @return bool Whether the given element exists in the array.
     */
    public static function hasDot(array $array, string $key): bool
    {
        foreach (explode('.', $key) as $key) {
            if (!is_array($array) || !array_key_exists($key, $array)) {
                return false;
            }

            $array = & $array[$key];
        }

        return true;
    }

    /**
     * Checks whether a given key exists in an array.
     *
     * @param array<mixed> $array The input array.
     * @param int|string $key The key to check for.
     * @return bool Whether the given key exists in the array.
     */
    public static function hasKey(array $array, int|string $key): bool
    {
        return array_key_exists($key, $array);
    }

    /**
     * Checks whether a given value exists in an array.
     *
     * @param array<mixed> $array The input array.
     * @param mixed $value The value to check for.
     * @param bool $strict Whether to perform a strict comparison.
     * @return bool Whether the given value exists in the array.
     */
    public static function includes(array $array, mixed $value, bool $strict = false): bool
    {
        return in_array($value, $array, $strict);
    }

    /**
     * Indexes a multi-dimensional array using a given key value.
     *
     * @param array<mixed>[] $array The input array.
     * @param int|string $key The column to pull key values from.
     * @return array<array<mixed>> The indexed array.
     */
    public static function index(array $array, int|string $key): array
    {
        return array_column($array, null, $key);
    }

    /**
     * Searches an array for a given value and returns the first key.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $array The input array.
     * @param mixed $value The value to search for.
     * @param bool $strict Whether to perform a strict search.
     * @return false|TKey The first key for a matching value, otherwise false.
     */
    public static function indexOf(array $array, mixed $value, bool $strict = false): false|int|string
    {
        return array_search($value, $array, $strict);
    }

    /**
     * Finds values in the first array present in all of the other arrays.
     *
     * @param array<mixed> $array The input array.
     * @param array<mixed> ...$arrays The arrays to use for comparison.
     * @return array<mixed> The array containing values present in all of the other arrays.
     */
    public static function intersect(array $array, array ...$arrays): array
    {
        return array_intersect($array, ...$arrays);
    }

    /**
     * Checks whether the value is an array.
     *
     * @param mixed $value The value to test.
     * @return bool Whether the value is an array.
     */
    public static function isArray(mixed $value): bool
    {
        return is_array($value);
    }

    /**
     * Checks whether an array has consecutive keys starting from 0.
     *
     * @param array<mixed> $array The array to test.
     * @return bool Whether the array has consecutive keys starting from 0.
     */
    public static function isList(array $array): bool
    {
        return array_is_list($array);
    }

    /**
     * Joins array elements using a specified separator.
     *
     * @param array<string> $array The input array.
     * @param string $separator The separator to join with.
     * @return string The joined string.
     */
    public static function join(array $array, string $separator = ','): string
    {
        return implode($separator, $array);
    }

    /**
     * Gets all keys of an array.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $array The input array.
     * @return TKey[] The array keys.
     */
    public static function keys(array $array): array
    {
        return array_keys($array);
    }

    /**
     * Returns the last element of an array.
     *
     * @template TValue
     *
     * @param array<TValue> $array The input array.
     * @return TValue|null The last element, or null for an empty array.
     */
    public static function last(array $array): mixed
    {
        return array_last($array);
    }

    /**
     * Searches an array for a given value and returns the last key.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $array The input array.
     * @param mixed $value The value to search for.
     * @param bool $strict Whether to perform a strict search.
     * @return false|TKey The last key for a matching value, otherwise false.
     */
    public static function lastIndexOf(array $array, mixed $value, bool $strict = false): false|int|string
    {
        return array_search(
            $value,
            array_reverse($array, true),
            $strict
        );
    }

    /**
     * Applies a callback to the elements of an array.
     *
     * @template TKey of array-key
     * @template TValue
     * @template TResult
     *
     * @param array<TKey, TValue> $array The input array.
     * @param callable(TValue, TKey): TResult $callback The callback function to use.
     * @return array<TKey, TResult> The modified array.
     */
    public static function map(array $array, callable $callback): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $result[$key] = $callback($value, $key);
        }

        return $result;
    }

    /**
     * Merges one or more arrays.
     *
     * @param array<mixed> ...$arrays The arrays to merge.
     * @return array<mixed> The merged array.
     */
    public static function merge(array ...$arrays): array
    {
        return array_merge(...$arrays);
    }

    /**
     * Checks whether no elements in an array pass a callback.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array The input array.
     * @param callable(TValue, TKey): bool $callback The callback function to use.
     * @return bool Whether no elements in the array pass the callback.
     */
    public static function none(array $array, callable $callback): bool
    {
        return !array_any($array, $callback);
    }

    /**
     * Returns an array containing only the specified keys.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array The input array.
     * @param array<TKey> $keys The keys to include.
     * @return array<TKey, TValue> The array containing only the specified keys.
     */
    public static function only(array $array, array $keys): array
    {
        return array_filter(
            $array,
            static fn(mixed $key): bool => in_array($key, $keys, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Pads an array to a specified length with a value.
     *
     * @param array<mixed> $array The input array.
     * @param int $size The new size of the array.
     * @param mixed $value The value to pad with.
     * @return array<mixed> The padded array.
     */
    public static function pad(array $array, int $size, mixed $value): array
    {
        return array_pad($array, $size, $value);
    }

    /**
     * Plucks a list of values using "dot" notation.
     *
     * @param array<mixed>[] $arrays The input arrays.
     * @param string $key The key to look up.
     * @return mixed[] The array of values.
     */
    public static function pluckDot(array $arrays, string $key): array
    {
        $result = [];

        foreach ($arrays as $array) {
            $result[] = static::getDot($array, $key);
        }

        return $result;
    }

    /**
     * Pops the element off the end of the array.
     *
     * @template TValue
     *
     * @param array<TValue> $array The input array.
     * @return TValue|null The last value of the array.
     */
    public static function pop(array &$array): mixed
    {
        return array_pop($array);
    }

    /**
     * Pushes one or more elements onto the end of array.
     *
     * @param array<mixed> $array The input array.
     * @param mixed ...$values The values to push.
     * @return int The new number of elements in the array.
     */
    public static function push(array &$array, mixed ...$values): int
    {
        return array_push($array, ...$values);
    }

    /**
     * Gets a random value from an array.
     *
     * @template TValue
     *
     * @param array<TValue> $array The input array.
     * @return TValue|null The random value from the array, or null if the array is empty.
     */
    public static function randomValue(array $array): mixed
    {
        if ($array === []) {
            return null;
        }

        $key = array_rand($array, 1);

        return $array[$key];
    }

    /**
     * Creates an array containing a range of elements.
     *
     * @param float|int|string $start The first value of the sequence.
     * @param float|int|string $end The ending value in the sequence.
     * @param float|int $step The increment between values in the sequence.
     * @return (float|int|string)[] The array of values from start to end, inclusive.
     */
    public static function range(float|int|string $start, float|int|string $end, float|int $step = 1): array
    {
        return range($start, $end, $step);
    }

    /**
     * Iteratively reduces an array to a single value using a callback function.
     *
     * @template TValue
     * @template TReturn
     *
     * @param array<TValue> $array The input array.
     * @param callable(TReturn, TValue): TReturn $callback The callback function to use.
     * @param TReturn $initial The initial value.
     * @return TReturn The final value.
     */
    public static function reduce(array $array, callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($array, $callback, $initial);
    }

    /**
     * Reverses the order of elements in an array.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array The input array.
     * @param bool $preserveKeys Whether to preserve the array keys.
     * @return array<TKey, TValue> The reversed array.
     */
    public static function reverse(array $array, bool $preserveKeys = false): array
    {
        return array_reverse($array, $preserveKeys);
    }

    /**
     * Sets a value using "dot" notation.
     *
     * The '*' segment acts as a wildcard, applying the remaining path to all
     * children at that level (e.g. 'items.*.name').
     *
     * @param array<mixed> $array The input array.
     * @param string $key The key.
     * @param mixed $value The value to set.
     * @param bool $overwrite Whether to overwrite previous values.
     * @return array<mixed> The modified array.
     */
    public static function setDot(array $array, string $key, mixed $value, bool $overwrite = true): array
    {
        $keys = explode('.', $key);
        $pointer = &$array;

        while (count($keys) > 1) {
            $segment = array_shift($keys);

            if ($segment !== '*') {
                if (!isset($pointer[$segment]) || !is_array($pointer[$segment])) {
                    $pointer[$segment] = [];
                }

                $pointer = &$pointer[$segment];

                continue;
            }

            $remaining = implode('.', $keys);

            foreach ($pointer as &$child) {
                if (!is_array($child)) {
                    $child = [];
                }

                $child = static::setDot($child, $remaining, $value, $overwrite);
            }

            return $array;
        }

        $lastKey = (string) array_shift($keys);

        if ($overwrite || !array_key_exists($lastKey, $pointer)) {
            $pointer[$lastKey] = $value;
        }

        return $array;
    }

    /**
     * Shifts an element off the beginning of the array.
     *
     * @template TValue
     *
     * @param array<TValue> $array The input array.
     * @return TValue|null The first value of the array.
     */
    public static function shift(array &$array): mixed
    {
        return array_shift($array);
    }

    /**
     * Shuffles an array.
     *
     * @template TValue
     *
     * @param array<TValue> $array The input array.
     * @return TValue[] The shuffled array.
     */
    public static function shuffle(array $array): array
    {
        shuffle($array);

        return $array;
    }

    /**
     * Extracts a slice of the array.
     *
     * @param array<mixed> $array The input array.
     * @param int $offset The starting offset.
     * @param int|null $length The length of the slice.
     * @param bool $preserveKeys Whether to preserve the array keys.
     * @return array<mixed> The sliced array.
     */
    public static function slice(array $array, int $offset = 0, int|null $length = null, bool $preserveKeys = false): array
    {
        return array_slice($array, $offset, $length, $preserveKeys);
    }

    /**
     * Checks whether some elements in an array pass a callback.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array The input array.
     * @param callable(TValue, TKey): bool $callback The callback function to use.
     * @return bool Whether some elements in the array pass the callback.
     */
    public static function some(array $array, callable $callback): bool
    {
        return array_any($array, $callback);
    }

    /**
     * Sorts an array.
     *
     * @template TValue
     *
     * @param array<TValue> $array The input array.
     * @param (Closure(TValue, TValue): int)|int $sort The sorting flag, or a comparison Closure.
     * @return TValue[] The sorted array.
     */
    public static function sort(array $array, Closure|int $sort = self::SORT_NATURAL): array
    {
        if ($sort instanceof Closure) {
            usort($array, $sort);
        } else {
            sort($array, $sort);
        }

        return $array;
    }

    /**
     * Removes a portion of the array and replaces it with something else.
     *
     * @param array<mixed> $array The input array.
     * @param int $offset The starting offset.
     * @param int|null $length The length to remove.
     * @param mixed $replacement The element(s) to insert in the array.
     * @return mixed[] The spliced elements.
     */
    public static function splice(array &$array, int $offset, int|null $length = null, mixed $replacement = []): array
    {
        return array_splice($array, $offset, $length, $replacement);
    }

    /**
     * Removes duplicate values from an array.
     *
     * @param array<mixed> $array The input array.
     * @param int $flags The comparison flag.
     * @return array<mixed> The filtered array.
     */
    public static function unique(array $array, int $flags = self::SORT_REGULAR): array
    {
        return array_unique($array, $flags);
    }

    /**
     * Prepends one or more elements to the beginning of an array.
     *
     * @param array<mixed> $array The input array.
     * @param mixed ...$values The values to prepend.
     * @return int The new number of elements in the array.
     */
    public static function unshift(array &$array, mixed ...$values): int
    {
        return array_unshift($array, ...$values);
    }

    /**
     * Returns all values.
     *
     * @template TValue
     *
     * @param array<TValue> $array The input array.
     * @return TValue[] The array values.
     */
    public static function values(array $array): array
    {
        return array_values($array);
    }

    /**
     * Wraps a value in an array.
     *
     * @param mixed $value The value to wrap.
     * @return array<mixed> The wrapped value.
     */
    public static function wrap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return $value === null ?
            [] :
            [$value];
    }
}
