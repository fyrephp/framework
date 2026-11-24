# Collections

`Fyre\Utility\Collection` provides lazy, chainable transformations over keyed sequences. Use [Array Helpers](arrays.md) when an operation should consume and return a plain PHP array immediately.

The `collect($source)` helper is shorthand for constructing a collection; see [Helpers](../core/helpers.md).

## Table of Contents

- [Create a collection](#create-a-collection)
- [Build a pipeline](#build-a-pipeline)
- [Extract values by path](#extract-values-by-path)
- [Understand materialization](#understand-materialization)
- [Method guide](#method-guide)
  - [Creation and iteration](#creation-and-iteration)
  - [Transformation](#transformation)
  - [Selection](#selection)
  - [Extraction and grouping](#extraction-and-grouping)
  - [Searching and predicates](#searching-and-predicates)
  - [Ordering, keys, and uniqueness](#ordering-keys-and-uniqueness)
  - [Aggregates](#aggregates)
  - [Nested structures](#nested-structures)
  - [Materialization and output](#materialization-and-output)
- [Behavior notes](#behavior-notes)
- [Related](#related)

## Create a collection

The constructor accepts an array, `Traversable`, `JsonSerializable`, lazy `Closure`, or `null`:

```php
use Fyre\Utility\Collection;

$users = new Collection([
    ['id' => 1, 'active' => true],
    ['id' => 2, 'active' => false],
]);

$lazyUsers = new Collection(static function(): iterable {
    yield ['id' => 1, 'active' => true];
    yield ['id' => 2, 'active' => false];
});
```

Arrays are stored directly. A `Traversable` or `JsonSerializable` source is converted to an array during construction. A closure is invoked each time the collection is iterated and may return an array, an `Iterator`, `null`, or a single value. Return a fresh iterator when the source needs to be repeatable.

## Build a pipeline

Most sequence transformations return a new collection and preserve the original:

```php
$ids = Collection::range(1, 10)
    ->filter(static fn(int $value): bool => $value % 2 === 0)
    ->map(static fn(int $value): int => $value * $value)
    ->take(3)
    ->toList();

// [4, 16, 36]
```

Transformation callbacks receive `(item, key)`. The reducer receives `(accumulator, item, key)`.

## Extract values by path

Methods such as `extract()`, `indexBy()`, `combine()`, `groupBy()`, `countBy()`, `sortBy()`, and the aggregate methods accept a value path:

- a dot-separated string such as `profile.email`
- an array of key segments such as `['profile', 'email']`
- a closure that receives `(item, key)`
- `null`, where supported, to use the complete item

Path traversal supports nested arrays, `ArrayAccess`, and object properties. A missing segment returns `null`.

```php
$users = new Collection([
    ['id' => 10, 'profile' => ['email' => 'a@example.com']],
    ['id' => 20, 'profile' => ['email' => 'b@example.com']],
]);

$emailsById = $users
    ->combine('id', 'profile.email')
    ->toArray();

// [10 => 'a@example.com', 20 => 'b@example.com']
```

## Understand materialization

Lazy transformations do not evaluate a closure source until the result is iterated. `foreach`, `getIterator()`, `toArray()`, `toList()`, `toJson()`, serialization, terminal searches, predicates, and aggregates all consume values.

Some methods must hold the complete sequence:

| Method | When the source is fully materialized |
| --- | --- |
| `collect()` | immediately, into a new array-backed collection |
| `reverse()`, `shuffle()`, `sort()`, `sortBy()` | when the method is called |
| `take($length)` with a negative length | when the method is called |
| `median()` | while calculating the result |
| `nest()` | when the returned collection is iterated |
| `count()` | while counting a closure-backed source |

`cache()` is different from `collect()`: it returns a lazy collection that stores values progressively as they are consumed. Later iterations reuse the stored values and continue the same underlying iterator if necessary.

## Method guide

The tables below use the setup and callback conventions described above.

### Creation and iteration

| Method | Behavior |
| --- | --- |
| `new Collection($source)` | create from an array, closure, `JsonSerializable`, `Traversable`, or `null` |
| `Collection::empty(): static` | create an empty array-backed collection |
| `Collection::range(int $start, int $end): static` | lazily yield an inclusive ascending or descending integer range |
| `getIterator(): Iterator` | return a new iterator over the current source evaluation |
| `each(Closure $callback): static` | consume the collection, invoke the callback for every item, and return the same instance |

### Transformation

These methods return new collections. Except where the materialization table says otherwise, evaluation remains lazy.

| Method | Behavior |
| --- | --- |
| `map(Closure $callback): static` | transform values and preserve keys |
| `filter(Closure $callback): static` | keep matching values and preserve keys |
| `reject(Closure $callback): static` | remove matching values and preserve keys |
| `reduce(Closure $callback, mixed $initial = null): mixed` | consume the sequence into one value |
| `chunk(int $size, bool $preserveKeys = false): static` | yield fixed-size arrays; non-positive sizes return an empty collection |
| `merge(array\|Traversable ...$arrays): static` | append iterable values and discard all source keys |
| `zip(array\|Traversable ...$iterables): static` | yield aligned value lists until the shortest input ends |

### Selection

| Method | Behavior |
| --- | --- |
| `skip(int $length): static` | skip leading items; `0` skips none and a negative length consumes without yielding |
| `take(int $length): static` | take leading items; a negative length eagerly selects from the end |
| `skipUntil(Closure $callback): static` | skip until a match, including the matching item |
| `skipWhile(Closure $callback): static` | skip while the callback is true |
| `takeUntil(Closure $callback): static` | take until a match, excluding the matching item |
| `takeWhile(Closure $callback): static` | take while the callback is true |
| `only(array $keys): static` | keep the listed keys using strict key comparison |
| `except(array $keys): static` | remove the listed keys using strict key comparison |

### Extraction and grouping

| Method | Result |
| --- | --- |
| `extract(array\|Closure\|string $valuePath): static` | reindexed extracted values |
| `indexBy(array\|Closure\|string $keyPath): static` | original items keyed by extracted values |
| `combine(array\|Closure\|string $keyPath, array\|Closure\|string\|null $valuePath = null): static` | extracted keys mapped to extracted values or complete items |
| `groupBy(array\|Closure\|string $keyPath): static` | extracted keys mapped to lists of matching items |
| `countBy(array\|Closure\|string $keyPath): static` | extracted keys mapped to item counts |

`indexBy()` and `combine()` cast a `Stringable` extracted key to a string. Normal PHP array-key conversion and overwrite behavior applies when several items produce the same key.

### Searching and predicates

| Method | Return behavior |
| --- | --- |
| `find(Closure $callback): mixed` | first matching value or `null` |
| `findLast(Closure $callback): mixed` | last matching value or `null`; materializes through `reverse()` |
| `every(Closure $callback): bool` | whether every item passes; stops at the first failure |
| `some(Closure $callback): bool` | whether any item passes; stops at the first match |
| `none(Closure $callback): bool` | whether no item passes; stops at the first match |
| `includes(mixed $value): bool` | whether a value is strictly identical |
| `indexOf(mixed $value): int\|string\|null` | key of the first strictly identical value |
| `lastIndexOf(mixed $value): int\|string\|null` | key of the last strictly identical value; materializes through `reverse()` |
| `first(): mixed` | first value or `null` |
| `last(): mixed` | last value or `null`; materializes through `reverse()` |
| `isEmpty(): bool` | whether iteration yields no first item |
| `count(): int` | source count; closure sources are fully iterated |
| `randomValue(): mixed` | random value or `null`; materializes and shuffles first |

### Ordering, keys, and uniqueness

| Method | Behavior |
| --- | --- |
| `sort(Closure\|int $callback = Collection::SORT_NATURAL, SortDirection $direction = SortDirection::Ascending): static` | sort values with a comparison closure or sort flag while preserving keys |
| `sortBy(array\|Closure\|string\|null $valuePath = null, int $sort = Collection::SORT_NATURAL, SortDirection $direction = SortDirection::Ascending): static` | sort by extracted values while preserving keys |
| `reverse(): static` | reverse iteration order while preserving keys |
| `shuffle(): static` | randomize order and reindex values |
| `unique(array\|Closure\|string\|null $valuePath = null, bool $strict = false): static` | retain the first item for each extracted value and preserve its key |
| `keys(): static` | yield source keys as a reindexed sequence |
| `values(): static` | yield source values as a reindexed sequence |
| `flip(): static` | use each value as a key and its original key as the value |

Available sort flags are `SORT_NATURAL`, `SORT_NUMERIC`, `SORT_REGULAR`, and `SORT_STRING`. For `sort()`, `$direction` applies only when using a sort flag. A comparison closure controls its own direction. To sort by a field in descending order, use `$items->sortBy('name', direction: SortDirection::Descending)`. To sort strings using Swedish collation rules, use `$names->sort((new Collator('sv_SE'))->compare(...))`. `unique()` compares loosely by default. Pass `true` for strict comparison.

### Aggregates

The optional path defaults to the complete item.

| Method | Return behavior |
| --- | --- |
| `sumOf(array\|Closure\|string\|null $valuePath = null): mixed` | sum, starting at `0` |
| `avg(array\|Closure\|string\|null $valuePath = null): float\|null` | mean of non-`null` values, or `null` when none remain |
| `min(array\|Closure\|string\|null $valuePath = null): mixed` | minimum extracted value or `null` |
| `max(array\|Closure\|string\|null $valuePath = null): mixed` | maximum extracted value or `null` |
| `median(array\|Closure\|string\|null $valuePath = null): mixed` | sorted median of non-`null` values or `null` |

For an even number of values, `median()` returns the arithmetic mean of the middle pair.

### Nested structures

| Method | Behavior |
| --- | --- |
| `dot(int\|string\|null $prefix = null): static` | recursively flatten arrays and traversables to dot-separated keys |
| `flatten(int $maxDepth = PHP_INT_MAX): static` | recursively flatten arrays and traversables into a reindexed value sequence |
| `nest(array\|Closure\|string $idPath = 'id', array\|Closure\|string $parentPath = 'parent_id', string $nestingKey = 'children'): static` | build a parent/child tree, with unresolved parents left at the root |
| `listNested(string $order = 'desc', string $nestingKey = 'children'): static` | traverse an existing tree in `desc`, `asc`, or `leaves` order |
| `printNested(array\|Closure\|string $valuePath, array\|Closure\|string $keyPath = 'id', string $prefix = '--', string $nestingKey = 'children'): static` | flatten a tree to keyed labels prefixed once per depth |

`listNested('desc')` yields each parent before its children, `asc` yields children before their parent, and `leaves` yields only items below the root level. Any other order yields an empty collection.

```php
$labels = new Collection([
    ['id' => 1, 'parent_id' => null, 'name' => 'Root'],
    ['id' => 2, 'parent_id' => 1, 'name' => 'Child'],
])
    ->nest()
    ->printNested('name')
    ->toArray();

// [1 => 'Root', 2 => '--Child']
```

### Materialization and output

| Method | Result |
| --- | --- |
| `toArray(): array` | materialized items with their current keys |
| `toList(): array` | materialized values with consecutive integer keys |
| `toJson(): string` | pretty-printed JSON |
| `jsonSerialize(): array` | materialized data with `JsonSerializable` items serialized and objects with callable `toArray()` converted |
| `collect(): static` | a new eager array-backed snapshot |
| `cache(): static` | a new lazy, progressively cached collection |
| `join(string $glue, string\|null $finalGlue = null): string` | materialized string, optionally using a distinct final separator |

Casting a collection to a string calls `toJson()`. PHP serialization materializes the collection; unserialization restores an array-backed collection.

```php
new Collection(['a', 'b', 'c'])
    ->join(', ', ' and '); // "a, b and c"
```

## Behavior notes

- Collection transformations never mutate the source collection. `each()` can mutate external state through its callback but returns the same collection instance.
- Iterating an uncached closure-backed collection invokes its source again. Use `cache()` for progressive reuse or `collect()` for an immediate snapshot.
- Key-preserving transformations can still overwrite earlier values when generated keys collide.
- `includes()`, `indexOf()`, and `lastIndexOf()` use strict identity; `unique()` is loose unless `$strict` is enabled.
- `dot()` and `flatten()` recurse into arrays and `Traversable` values, unlike `Arr::dot()` and `Arr::flatten()`, which recurse only into arrays.
- `Collection` supports instance and static macros.

## Related

- [Utilities](index.md)
- [Array Helpers](arrays.md)
- [Helpers](../core/helpers.md)
