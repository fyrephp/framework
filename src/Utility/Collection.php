<?php
declare(strict_types=1);

namespace Fyre\Utility;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Core\Traits\StaticMacroTrait;
use Generator;
use Iterator;
use IteratorAggregate;
use JsonSerializable;
use Override;
use Stringable;
use Traversable;

use function array_key_exists;
use function array_map;
use function array_pop;
use function array_reverse;
use function array_slice;
use function array_values;
use function arsort;
use function asort;
use function count;
use function explode;
use function floor;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_int;
use function is_object;
use function iterator_count;
use function iterator_to_array;
use function json_encode;
use function property_exists;
use function shuffle;
use function str_repeat;
use function uasort;

use const JSON_PRETTY_PRINT;
use const SORT_NATURAL;
use const SORT_NUMERIC;
use const SORT_REGULAR;
use const SORT_STRING;

/**
 * Provides collection utilities.
 *
 * Collections can be backed by an array or a lazy generator. Most transformation methods return a new Collection and
 * remain lazy until the collection is iterated.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @implements IteratorAggregate<TKey, TValue>
 *
 * @phpstan-consistent-constructor
 */
class Collection implements Countable, IteratorAggregate, JsonSerializable, Stringable
{
    use DebugTrait;
    use MacroTrait;
    use StaticMacroTrait;

    public const SORT_NATURAL = SORT_NATURAL;

    public const SORT_NUMERIC = SORT_NUMERIC;

    public const SORT_REGULAR = SORT_REGULAR;

    public const SORT_STRING = SORT_STRING;

    /**
     * @var array<TKey, TValue>|Closure
     */
    protected readonly array|Closure $source;

    /**
     * Creates an empty collection.
     *
     * @return static The new Collection instance with no items.
     */
    public static function empty(): static
    {
        return new static([]);
    }

    /**
     * Creates a collection for a range of numbers.
     *
     * @param int $start The first value of the sequence.
     * @param int $end The ending value in the sequence.
     * @return static<int, int> The new Collection instance with the range values.
     */
    public static function range(int $start, int $end): static
    {
        return new static(static function() use ($start, $end): Generator {
            $current = $start;

            if ($current <= $end) {
                while ($current <= $end) {
                    yield $current++;
                }
            } else {
                while ($current >= $end) {
                    yield $current--;
                }
            }
        });
    }

    /**
     * Constructs a Collection.
     *
     * @param array<TKey, TValue>|Closure|JsonSerializable|Traversable<TKey, TValue>|null $source The source.
     */
    public function __construct(array|Closure|JsonSerializable|Traversable|null $source)
    {
        if ($source === null) {
            $this->source = [];
        } else if ($source instanceof Traversable) {
            $this->source = iterator_to_array($source);
        } else if ($source instanceof JsonSerializable) {
            $this->source = (array) $source->jsonSerialize();
        } else {
            $this->source = $source;
        }
    }

    /**
     * Serializes the object.
     *
     * @return array<TKey, TValue> The serialized data.
     */
    public function __serialize(): array
    {
        return $this->toArray();
    }

    /**
     * Converts the collection to a JSON encoded string.
     *
     * @return string The JSON encoded string.
     */
    #[Override]
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Unserializes the object.
     *
     * @param array<TKey, TValue> $data The serialized data.
     */
    public function __unserialize(array $data): void
    {
        $this->__construct($data);
    }

    /**
     * Returns the average value of a key.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string|null $valuePath The key path of the value.
     * @return float|null The average value.
     */
    public function avg(array|Closure|string|null $valuePath = null): float|null
    {
        $valueCallback = static::valueExtractor($valuePath);

        [$sum, $count] = $this->reduce(static function(array $result, mixed $item, int|string $key) use ($valueCallback): array {
            $value = $valueCallback($item, $key);

            if ($value !== null) {
                $result[0] += $value;
                $result[1]++;
            }

            return $result;
        }, [0, 0]);

        return $count ? ($sum / $count) : null;
    }

    /**
     * Caches the computed values via a new collection.
     *
     * @return static The new Collection instance with cached computed values.
     */
    public function cache(): static
    {
        $iterator = $this->getIterator();

        $iteratorIndex = 0;

        $cache = [];

        return new static(static function() use ($iterator, &$iteratorIndex, &$cache): Generator {
            $index = 0;
            while (true) {
                if (isset($cache[$index])) {
                    [$key, $value] = $cache[$index];
                } else {
                    while ($iteratorIndex < $index) {
                        $iterator->next();
                        $iteratorIndex++;
                    }

                    if (!$iterator->valid()) {
                        break;
                    }

                    $key = $iterator->key();
                    $value = $iterator->current();

                    $cache[$index] = [$key, $value];
                }

                yield $key => $value;
                $index++;
            }
        });
    }

    /**
     * Splits the collection into chunks.
     *
     * @param int $size The size of each chunk.
     * @param bool $preserveKeys Whether to preserve the array keys.
     * @return static The new Collection instance with chunked items.
     */
    public function chunk(int $size, bool $preserveKeys = false): static
    {
        if ($size <= 0) {
            return static::empty();
        }

        return new static(function() use ($size, $preserveKeys): Generator {
            $results = [];

            foreach ($this as $key => $item) {
                if ($preserveKeys) {
                    $results[$key] = $item;
                } else {
                    $results[] = $item;
                }

                if (count($results) === $size) {
                    yield $results;
                    $results = [];
                }
            }

            if ($results !== []) {
                yield $results;
            }
        });
    }

    /**
     * Collects the computed values into a new collection.
     *
     * @return static The new Collection instance with computed values.
     */
    public function collect(): static
    {
        return new static($this->toArray());
    }

    /**
     * Re-indexes the items in the collection by a given key, using a given value.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string $keyPath The key path.
     * @param array-key[]|Closure(TValue, TKey): mixed|string|null $valuePath The key path of the value.
     * @return static The new Collection instance keyed by the extracted key path.
     */
    public function combine(array|Closure|string $keyPath, array|Closure|string|null $valuePath = null): static
    {
        $keyCallback = static::valueExtractor($keyPath);
        $valueCallback = static::valueExtractor($valuePath);

        return new static(function() use ($keyCallback, $valueCallback): Generator {
            foreach ($this as $key => $item) {
                $value = $valueCallback($item, $key);
                $key = $keyCallback($item, $key);

                if (is_object($key) && $key instanceof Stringable) {
                    $key = (string) $key;
                }

                yield $key => $value;
            }
        });
    }

    /**
     * Counts all items in the collection.
     *
     * @return int The number of items in the collection.
     */
    #[Override]
    public function count(): int
    {
        if ($this->source instanceof Closure) {
            return $this->getIterator() |> iterator_count(...);
        }

        return count($this->source);
    }

    /**
     * Groups the items in the collection by a given key, and counts the number of items in each.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string $keyPath The key path.
     * @return static The new Collection instance with grouped item counts.
     */
    public function countBy(array|Closure|string $keyPath): static
    {
        $keyCallback = static::valueExtractor($keyPath);

        return new static(function() use ($keyCallback): Generator {
            $results = [];

            foreach ($this as $key => $item) {
                $key = $keyCallback($item, $key);

                $results[$key] ??= 0;
                $results[$key]++;
            }

            yield from $results;
        });
    }

    /**
     * Flattens a multi-dimensional collection using "dot" notation.
     *
     * @param int|string|null $prefix The key prefix.
     * @return static The new Collection instance with dot-notated keys.
     */
    public function dot(int|string|null $prefix = null): static
    {
        return new static(function() use ($prefix): Generator {
            foreach ($this as $key => $item) {
                if ($prefix !== null) {
                    $key = $prefix.'.'.$key;
                }

                if (!is_array($item) && !($item instanceof Traversable)) {
                    yield $key => $item;
                } else {
                    yield from new static($item)->dot($key);
                }
            }
        });
    }

    /**
     * Executes a callback on each item in the collection.
     *
     * @param Closure(TValue, TKey): mixed $callback The callback.
     * @return static The Collection instance.
     */
    public function each(Closure $callback): static
    {
        foreach ($this as $key => $item) {
            $callback($item, $key);
        }

        return $this;
    }

    /**
     * Checks whether every item in the collection passes a callback.
     *
     * @param Closure(TValue, TKey): bool $callback The callback.
     * @return bool Whether every item in the collection passes a callback.
     */
    public function every(Closure $callback): bool
    {
        foreach ($this as $key => $item) {
            if (!$callback($item, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns a collection without the specified keys.
     *
     * @param array<array-key, TKey> $keys The keys to exclude.
     * @return static The new Collection instance without the excluded keys.
     */
    public function except(array $keys): static
    {
        return new static(function() use ($keys): Generator {
            foreach ($this as $key => $item) {
                if (in_array($key, $keys, true)) {
                    continue;
                }

                yield $key => $item;
            }
        });
    }

    /**
     * Extracts values from the collection using "dot" notation.
     *
     * @template TResult
     *
     * @param array-key[]|(Closure(TValue, TKey): TResult)|string $valuePath The key path of the value.
     * @return static<int, mixed> The new Collection instance with extracted values.
     *
     * @phpstan-return ($valuePath is Closure ? static<int, TResult> : static<int, mixed>)
     */
    public function extract(array|Closure|string $valuePath): static
    {
        $valueCallback = static::valueExtractor($valuePath);

        return new static(function() use ($valueCallback): Generator {
            foreach ($this as $key => $item) {
                yield $valueCallback($item, $key);
            }
        });
    }

    /**
     * Filters items in the collection using a callback function.
     *
     * @param Closure(TValue, TKey): bool $callback The callback.
     * @return static<TKey, TValue> The new Collection instance with filtered items.
     */
    public function filter(Closure $callback): static
    {
        return new static(function() use ($callback): Generator {
            foreach ($this as $key => $item) {
                if (!$callback($item, $key)) {
                    continue;
                }

                yield $key => $item;
            }
        });
    }

    /**
     * Finds the first value in the collection that passes a callback.
     *
     * @param Closure(TValue, TKey): bool $callback The callback.
     * @return TValue|null The first matching value, or null.
     */
    public function find(Closure $callback): mixed
    {
        foreach ($this as $key => $item) {
            if ($callback($item, $key)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Finds the last value in the collection that passes a callback.
     *
     * @param Closure(TValue, TKey): bool $callback The callback.
     * @return TValue|null The last matching value, or null.
     */
    public function findLast(Closure $callback): mixed
    {
        return $this->reverse()->find($callback);
    }

    /**
     * Returns the first value in the collection.
     *
     * @return TValue|null The first value in the collection, or null if empty.
     */
    public function first(): mixed
    {
        foreach ($this as $item) {
            return $item;
        }

        return null;
    }

    /**
     * Flattens a multi-dimensional collection into a single level.
     *
     * @param int $maxDepth The maximum depth to flatten.
     * @return static The new Collection instance with flattened items.
     */
    public function flatten(int $maxDepth = PHP_INT_MAX): static
    {
        return new static(function() use ($maxDepth): Generator {
            foreach ($this as $item) {
                if (!is_array($item) && !($item instanceof Traversable)) {
                    yield $item;
                } else if ($maxDepth === 1) {
                    yield from $item;
                } else {
                    yield from new static($item)->flatten($maxDepth - 1);
                }
            }
        })->values();
    }

    /**
     * Swaps the keys and values of a collection.
     *
     * @return static The new Collection instance with swapped keys and values.
     */
    public function flip(): static
    {
        return new static(function(): Generator {
            foreach ($this as $key => $item) {
                yield $item => $key;
            }
        });
    }

    /**
     * Returns the collection Iterator.
     *
     * @return Iterator The collection Iterator.
     */
    #[Override]
    public function getIterator(): Iterator
    {
        if (!($this->source instanceof Closure)) {
            return new ArrayIterator($this->source);
        }

        $data = ($this->source)();

        if (is_array($data) || $data === null) {
            return new ArrayIterator($data ?? []);
        }

        if ($data instanceof Iterator) {
            return $data;
        }

        return new ArrayIterator([$data]);
    }

    /**
     * Groups the items in the collection by a given key.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string $keyPath The key path.
     * @return static The new Collection instance grouped by the extracted key.
     */
    public function groupBy(array|Closure|string $keyPath): static
    {
        $keyCallback = static::valueExtractor($keyPath);

        return new static(function() use ($keyCallback): Generator {
            $results = [];

            foreach ($this as $key => $item) {
                $key = $keyCallback($item, $key);

                $results[$key] ??= [];
                $results[$key][] = $item;
            }

            yield from $results;
        });
    }

    /**
     * Checks whether a given value exists in the collection.
     *
     * @param mixed $value The value to check for.
     * @return bool Whether a given value exists in the collection.
     */
    public function includes(mixed $value): bool
    {
        return $this->some(static fn(mixed $item): bool => $item === $value);
    }

    /**
     * Re-indexes the items in the collection by a given key.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string $keyPath The key path.
     * @return static The new Collection instance keyed by the extracted key.
     */
    public function indexBy(array|Closure|string $keyPath): static
    {
        $keyCallback = static::valueExtractor($keyPath);

        return new static(function() use ($keyCallback): Generator {
            foreach ($this as $key => $item) {
                $key = $keyCallback($item, $key);

                if (is_object($key) && $key instanceof Stringable) {
                    $key = (string) $key;
                }

                yield $key => $item;
            }
        });
    }

    /**
     * Searches the collection for a given value and returns the first key.
     *
     * @param mixed $value The value to search for.
     * @return TKey|null The first key for the matching value, otherwise null.
     */
    public function indexOf(mixed $value): int|string|null
    {
        foreach ($this as $key => $item) {
            if ($item === $value) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Checks whether the collection is empty.
     *
     * @return bool Whether the collection is empty.
     */
    public function isEmpty(): bool
    {
        foreach ($this as $_) {
            return false;
        }

        return true;
    }

    /**
     * Joins the items in the collection using a specified separator.
     *
     * @param string $glue The separator to join with.
     * @param string|null $finalGlue The conjunction for the last value.
     * @return string The joined string.
     */
    public function join(string $glue, string|null $finalGlue = null): string
    {
        $values = $this->toList();

        if ($finalGlue === null) {
            return implode($glue, $values);
        }

        $count = count($values);

        if ($count === 0) {
            return '';
        }

        $finalValue = array_pop($values);

        if ($count === 1) {
            return $finalValue;
        }

        return implode($glue, $values).$finalGlue.$finalValue;
    }

    /**
     * Converts the collection to an array for JSON serializing.
     *
     * @return array<TKey, mixed> The array for serializing.
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return array_map(static function(mixed $item): mixed {
            if ($item instanceof JsonSerializable) {
                return $item->jsonSerialize();
            }

            if (!is_object($item)) {
                return $item;
            }

            $toArray = [$item, 'toArray'];

            if (is_callable($toArray)) {
                return $toArray();
            }

            return $item;
        }, $this->toArray());
    }

    /**
     * Returns the keys in the collection.
     *
     * @return static<int, TKey> The new Collection instance with collection keys.
     */
    public function keys(): static
    {
        return new static(function(): Generator {
            foreach ($this as $key => $item) {
                yield $key;
            }
        });
    }

    /**
     * Returns the last value in the collection.
     *
     * @return TValue|null The last value in the collection, or null if empty.
     */
    public function last(): mixed
    {
        return $this->reverse()->first();
    }

    /**
     * Searches the collection for a given value and returns the last key.
     *
     * @param mixed $value The value to search for.
     * @return TKey|null The last key for the matching value, otherwise null.
     */
    public function lastIndexOf(mixed $value): int|string|null
    {
        return $this->reverse()->indexOf($value);
    }

    /**
     * Flattens a nested tree of items into a linear list.
     *
     * The $order argument controls how parents and children are yielded:
     *  - 'desc'   Parent before its children (pre-order, default).
     *  - 'asc'    Children before their parent (post-order).
     *  - 'leaves' Only non-root items (depth > 0), in depth-first order.
     *
     * Any other value for $order will result in an empty collection.
     *
     * Each item is expected to contain its children under $nestingKey
     * (typically an array or Traversable).
     *
     * @param string $order The traversal order: 'desc', 'asc', or 'leaves'.
     * @param string $nestingKey The key used for nesting children.
     * @return static The new Collection instance with the flattened items.
     */
    public function listNested(string $order = 'desc', string $nestingKey = 'children'): static
    {
        return new static(function() use ($order, $nestingKey): Generator {
            $getResults = static function(array|Traversable $items, int $depth = 0) use ($order, $nestingKey, &$getResults): Generator {
                foreach ($items as $item) {
                    if ($order === 'desc' || ($order === 'leaves' && $depth > 0)) {
                        yield $item;
                    }

                    $children = $item[$nestingKey] ?? null;

                    if (is_array($children) || $children instanceof Traversable) {
                        $nestedItems = $getResults($children, $depth + 1);
                        foreach ($nestedItems as $nestedItem) {
                            yield $nestedItem;
                        }
                    }

                    if ($order === 'asc') {
                        yield $item;
                    }
                }
            };

            yield from $getResults($this);
        });
    }

    /**
     * Applies a callback to the items in the collection.
     *
     * @template TResult
     *
     * @param Closure(TValue, TKey): TResult $callback The callback.
     * @return static<TKey, TResult> The new Collection instance with mapped items.
     */
    public function map(Closure $callback): static
    {
        return new static(function() use ($callback): Generator {
            foreach ($this as $key => $item) {
                yield $key => $callback($item, $key);
            }
        });
    }

    /**
     * Returns the maximum value of a key.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string|null $valuePath The key path of the value.
     * @return mixed The maximum value.
     */
    public function max(array|Closure|string|null $valuePath = null): mixed
    {
        $valueCallback = static::valueExtractor($valuePath);

        return $this->reduce(
            static function(mixed $acc, mixed $item, int|string $key) use ($valueCallback): mixed {
                $value = $valueCallback($item, $key);

                return $acc === null || $value > $acc ? $value : $acc;
            }
        );
    }

    /**
     * Returns the median value of a key.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string|null $valuePath The key path of the value.
     * @return mixed The median value.
     */
    public function median(array|Closure|string|null $valuePath = null): mixed
    {
        if ($valuePath === null) {
            $values = $this;
        } else {
            $valueCallback = static::valueExtractor($valuePath);

            $values = $this->map($valueCallback);
        }

        $values = $values->filter(static fn(mixed $value): bool => $value !== null)
            ->sort()
            ->toList();

        $count = count($values);

        if ($count === 0) {
            return null;
        }

        $middle = (int) floor($count / 2);

        if ($count % 2 !== 0) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Merges one or more iterables into the collection.
     *
     * @param iterable<mixed> ...$arrays The iterables to merge.
     * @return static The new Collection instance with merged items.
     */
    public function merge(array|Traversable ...$arrays): static
    {
        return new static(function() use ($arrays): Generator {
            foreach ($this as $item) {
                yield $item;
            }

            foreach ($arrays as $iterable) {
                foreach ($iterable as $item) {
                    yield $item;
                }
            }
        });
    }

    /**
     * Returns the minimum value of a key.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string|null $valuePath The key path of the value.
     * @return mixed The minimum value.
     */
    public function min(array|Closure|string|null $valuePath = null): mixed
    {
        $valueCallback = static::valueExtractor($valuePath);

        return $this->reduce(
            static function(mixed $acc, mixed $item, int|string $key) use ($valueCallback): mixed {
                $value = $valueCallback($item, $key);

                return $acc === null || $value < $acc ? $value : $acc;
            }
        );
    }

    /**
     * Nests child items inside parent items.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string $idPath The key path of the ID.
     * @param array-key[]|Closure(TValue, TKey): mixed|string $parentPath The key path of the parent ID.
     * @param string $nestingKey The key used for nesting children.
     * @return static The new Collection instance with nested items.
     */
    public function nest(array|Closure|string $idPath = 'id', array|Closure|string $parentPath = 'parent_id', string $nestingKey = 'children'): static
    {
        $idCallback = static::valueExtractor($idPath);
        $parentCallback = static::valueExtractor($parentPath);

        return new static(function() use ($idCallback, $parentCallback, $nestingKey): Generator {
            $items = $this->toArray();

            $parents = [];
            foreach ($items as $key => &$item) {
                $id = $idCallback($item, $key);

                $item[$nestingKey] = [];
                $parents[$id] = &$item;
            }

            $results = [];
            foreach ($items as $key => &$item) {
                $parentId = $parentCallback($item, $key);

                if ($parentId !== null && $parentId !== '' && isset($parents[$parentId])) {
                    $parents[$parentId][$nestingKey][] = &$item;
                } else {
                    $results[] = &$item;
                }
            }

            yield from $results;
        });
    }

    /**
     * Checks whether no items in the collection pass a callback.
     *
     * @param Closure(TValue, TKey): bool $callback The callback.
     * @return bool Whether no items in the collection pass a callback.
     */
    public function none(Closure $callback): bool
    {
        return static::negate($callback) |> $this->every(...);
    }

    /**
     * Returns a Collection with only the specified keys.
     *
     * @param array<array-key, TKey> $keys The keys to include.
     * @return static The new Collection instance with only the specified keys.
     */
    public function only(array $keys): static
    {
        return new static(function() use ($keys): Generator {
            foreach ($this as $key => $item) {
                if (!in_array($key, $keys, true)) {
                    continue;
                }

                yield $key => $item;
            }
        });
    }

    /**
     * Formats nested list items based on depth.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string $valuePath The key path of the name.
     * @param array-key[]|Closure(TValue, TKey): mixed|string $keyPath The key path.
     * @param string $prefix The prefix used to indicate depth.
     * @param string $nestingKey The key used for nesting children.
     * @return static The new Collection instance with formatted nested values.
     */
    public function printNested(array|Closure|string $valuePath, array|Closure|string $keyPath = 'id', string $prefix = '--', string $nestingKey = 'children'): static
    {
        $valueCallback = static::valueExtractor($valuePath);
        $keyCallback = static::valueExtractor($keyPath);

        return new static(function() use ($valueCallback, $keyCallback, $prefix, $nestingKey): Generator {
            $getResults = function(array|Traversable $items, int $depth = 0) use ($valueCallback, $keyCallback, $prefix, $nestingKey, &$getResults): Generator {
                foreach ($items as $key => $item) {
                    $value = $valueCallback($item, $key);
                    $key = $keyCallback($item, $key);

                    $value = (string) $value;

                    if ($depth > 0) {
                        $value = str_repeat($prefix, $depth).$value;
                    }

                    yield $key => $value;

                    $children = $item[$nestingKey] ?? null;

                    if (is_array($children) || $children instanceof Traversable) {
                        $nestedItems = $getResults($children, $depth + 1);
                        foreach ($nestedItems as $nestedKey => $nestedItem) {
                            yield $nestedKey => $nestedItem;
                        }
                    }
                }
            };

            yield from $getResults($this);
        });
    }

    /**
     * Pulls a random item out of the collection.
     *
     * @return TValue|null The random item, or null if the collection is empty.
     */
    public function randomValue(): mixed
    {
        return $this->shuffle()->first();
    }

    /**
     * Iteratively reduces the collection to a single value using a callback function.
     *
     * @template TAcc
     *
     * @param Closure(TAcc, TValue, TKey): TAcc $callback The callback function to use.
     * @param TAcc $initial The initial value.
     * @return TAcc The final value.
     */
    public function reduce(Closure $callback, mixed $initial = null): mixed
    {
        $acc = $initial;
        foreach ($this as $key => $item) {
            $acc = $callback($acc, $item, $key);
        }

        return $acc;
    }

    /**
     * Excludes items in the collection using a callback function.
     *
     * @param Closure(TValue, TKey): bool $callback The callback.
     * @return static<TKey, TValue> The new Collection instance with rejected items removed.
     */
    public function reject(Closure $callback): static
    {
        return static::negate($callback) |> $this->filter(...);
    }

    /**
     * Reverses the order of items in the collection.
     *
     * @return static The new Collection instance with reversed items.
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->toArray(), true));
    }

    /**
     * Randomizes the order of items in the collection.
     *
     * @return static The new Collection instance with randomized items.
     */
    public function shuffle(): static
    {
        $data = $this->toArray();
        shuffle($data);

        return new static($data);
    }

    /**
     * Skips a number of items in the collection.
     *
     * @param int $length The number of items to skip.
     * @return static The new Collection instance with skipped leading items removed.
     */
    public function skip(int $length): static
    {
        return new static(function() use ($length): Generator {
            $iterator = $this->getIterator();
            $iterator->rewind();

            while ($iterator->valid() && $length--) {
                $iterator->next();
            }

            while ($iterator->valid()) {
                yield $iterator->key() => $iterator->current();
                $iterator->next();
            }
        });
    }

    /**
     * Skips items in the collection until a callback returns TRUE.
     *
     * @param Closure $callback The callback.
     * @return static The new Collection instance with leading items skipped.
     */
    public function skipUntil(Closure $callback): static
    {
        return new static(function() use ($callback): Generator {
            $started = false;

            foreach ($this as $key => $item) {
                if (!$started && $callback($item, $key)) {
                    $started = true;
                }

                if (!$started) {
                    continue;
                }

                yield $key => $item;
            }
        });
    }

    /**
     * Skips items in the collection until a callback returns FALSE.
     *
     * @param Closure $callback The callback.
     * @return static The new Collection instance with leading items skipped.
     */
    public function skipWhile(Closure $callback): static
    {
        return static::negate($callback) |> $this->skipUntil(...);
    }

    /**
     * Checks whether some items in the collection pass a callback.
     *
     * @param Closure $callback The callback.
     * @return bool Whether some items in the collection pass a callback.
     */
    public function some(Closure $callback): bool
    {
        return !$this->none($callback);
    }

    /**
     * Sorts the collection using a callback.
     *
     * @param Closure|int $callback The callback or sort method.
     * @param bool $descending Whether to sort in descending order.
     * @return static The new Collection instance with sorted items.
     */
    public function sort(Closure|int $callback = self::SORT_NATURAL, bool $descending = false): static
    {
        if (is_int($callback)) {
            return $this->sortBy(
                sort: $callback,
                descending: $descending
            );
        }

        $items = $this->toArray();

        uasort($items, $callback);

        return new static($items);
    }

    /**
     * Sorts the collection by a given key.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string|null $valuePath The key path of the value.
     * @param int $sort The sort method.
     * @param bool $descending Whether to sort in descending order.
     * @return static The new Collection instance with sorted items.
     */
    public function sortBy(array|Closure|string|null $valuePath = null, int $sort = self::SORT_NATURAL, bool $descending = false): static
    {
        $valueCallback = static::valueExtractor($valuePath);

        $results = [];
        $items = $this->toArray();

        foreach ($items as $key => $item) {
            $results[$key] = $valueCallback($item, $key);
        }

        if ($descending) {
            arsort($results, $sort);
        } else {
            asort($results, $sort);
        }

        foreach ($results as $key => $value) {
            $results[$key] = $items[$key];
        }

        return new static($results);
    }

    /**
     * Returns the total sum of a key.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string|null $valuePath The key path of the value.
     * @return mixed The total sum.
     */
    public function sumOf(array|Closure|string|null $valuePath = null): mixed
    {
        $valueCallback = static::valueExtractor($valuePath);

        return $this->reduce(
            static fn(mixed $acc, mixed $item, int|string $key): mixed => $acc + $valueCallback($item, $key),
            0
        );
    }

    /**
     * Takes a number of items in the collection.
     *
     * @param int $length The number of items.
     * @return static The new Collection instance with the taken items.
     */
    public function take(int $length): static
    {
        if ($length < 0) {
            return new static(array_slice($this->toArray(), $length, null, true));
        }

        return new static(function() use ($length): Generator {
            $iterator = $this->getIterator();
            $iterator->rewind();

            while ($iterator->valid() && $length--) {
                yield $iterator->key() => $iterator->current();
                $iterator->next();
            }
        });
    }

    /**
     * Takes items in the collection until a callback returns TRUE.
     *
     * @param Closure $callback The callback.
     * @return static The new Collection instance with leading items taken.
     */
    public function takeUntil(Closure $callback): static
    {
        return new static(function() use ($callback): Generator {
            foreach ($this as $key => $item) {
                if ($callback($item, $key)) {
                    break;
                }

                yield $key => $item;
            }
        });
    }

    /**
     * Takes items in the collection until a callback returns FALSE.
     *
     * @param Closure $callback The callback.
     * @return static The new Collection instance with leading items taken.
     */
    public function takeWhile(Closure $callback): static
    {
        return static::negate($callback) |> $this->takeUntil(...);
    }

    /**
     * Returns the items in the collection as an array.
     *
     * @return array<TKey, TValue> The collection items.
     */
    public function toArray(): array
    {
        if (!($this->source instanceof Closure)) {
            return $this->source;
        }

        return $this->getIterator() |> iterator_to_array(...);
    }

    /**
     * Converts the collection to a JSON encoded string.
     *
     * @return string The JSON encoded string.
     */
    public function toJson(): string
    {
        return (string) json_encode($this, JSON_PRETTY_PRINT);
    }

    /**
     * Returns the values in the collection as an array.
     *
     * @return TValue[] The collection values.
     */
    public function toList(): array
    {
        return $this->values()->toArray() |> array_values(...);
    }

    /**
     * Returns the unique items in the collection based on a given key.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string|null $valuePath The key path of the value.
     * @param bool $strict Whether to compare values strictly.
     * @return static The new Collection instance with unique items.
     */
    public function unique(array|Closure|string|null $valuePath = null, bool $strict = false): static
    {
        $valueCallback = static::valueExtractor($valuePath);

        return new static(function() use ($valueCallback, $strict): Generator {
            $exists = [];

            foreach ($this as $key => $item) {
                $value = $valueCallback($item, $key);

                if (in_array($value, $exists, $strict)) {
                    continue;
                }

                yield $key => $item;
                $exists[] = $value;
            }
        });
    }

    /**
     * Returns the values in the collection.
     *
     * @return static<int, TValue> The new Collection instance with collection values.
     */
    public function values(): static
    {
        return new static(function(): Generator {
            foreach ($this as $item) {
                yield $item;
            }
        });
    }

    /**
     * Zips one or more iterables with the collection.
     *
     * @param iterable<mixed> ...$iterables The iterables to merge.
     * @return static The new Collection instance with zipped items.
     */
    public function zip(array|Traversable ...$iterables): static
    {
        $collections = [
            $this,
            ...array_map(
                static fn(array|Traversable $iterable): Collection => new static($iterable),
                $iterables
            ),
        ];

        return new static(static function() use ($collections): Generator {
            $iterators = array_map(
                static fn(Collection $item): Iterator => $item->getIterator(),
                $collections
            );

            while (true) {
                $values = [];
                foreach ($iterators as $iterator) {
                    if (!$iterator->valid()) {
                        break 2;
                    }

                    $values[] = $iterator->current();
                    $iterator->next();
                }

                yield $values;
            }
        });
    }

    /**
     * Negates the result of a callback.
     *
     * @param Closure $callback The callback.
     * @return Closure The new callback.
     */
    protected static function negate(Closure $callback): Closure
    {
        return static fn(...$args): bool => !$callback(...$args);
    }

    /**
     * Builds a callback to extract a value from an item.
     *
     * @param array-key[]|Closure(TValue, TKey): mixed|string|null $path The path of the value.
     * @return Closure(TValue, TKey): mixed The closure to extract the value.
     */
    protected static function valueExtractor(array|Closure|string|null $path): Closure
    {
        if ($path === null) {
            return static fn(mixed $value, int|string|null $key = null): mixed => $value;
        }

        if ($path instanceof Closure) {
            return $path;
        }

        return static function(mixed $value, int|string|null $key = null) use ($path): mixed {
            $paths = is_array($path) ? $path : explode('.', $path);
            foreach ($paths as $path) {
                if ($path === null) {
                    return $value;
                }

                if (is_array($value) && array_key_exists($path, $value)) {
                    $value = $value[$path];
                } else if ($value instanceof ArrayAccess && $value->offsetExists($path)) {
                    $value = $value->offsetGet($path);
                } else if (is_object($value) && property_exists($value, $path)) {
                    $value = $value->$path;
                } else {
                    return null;
                }
            }

            return $value;
        };
    }
}
