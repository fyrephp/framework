# Array Helpers

`Fyre\Utility\Arr` provides static helpers for plain PHP arrays, including dot paths, selection, searching, transformations, and wrappers around common native functions.

Use [Collections](collections.md) when a lazy, chainable sequence is more useful than an immediate array result.

## Table of Contents

- [Common operations](#common-operations)
- [Constants](#constants)
- [Method guide](#method-guide)
  - [Dot paths](#dot-paths)
  - [Shape and selection](#shape-and-selection)
  - [Searching and predicates](#searching-and-predicates)
  - [Transforming and comparing](#transforming-and-comparing)
  - [Slicing and mutation](#slicing-and-mutation)
- [Behavior notes](#behavior-notes)
- [Related](#related)

## Common operations

Import `Arr` once and pass the source array as the first argument:

```php
use Fyre\Utility\Arr;

$data = [
    'items' => [
        ['name' => 'First'],
        [],
    ],
];

$first = Arr::getDot($data, 'items.0.name');
$data = Arr::setDot($data, 'items.*.active', true);
$names = Arr::pluckDot($data['items'], 'name');
```

`$first` is `First`. `$names` is `['First', null]` because `pluckDot()` includes one result per input row and uses `null` for a missing path.

Most methods return a new array. Only `pop()`, `push()`, `shift()`, `unshift()`, and `splice()` mutate their array argument.

## Constants

| Group | Constants | Used by |
| --- | --- | --- |
| counting | `COUNT_NORMAL`, `COUNT_RECURSIVE` | `count()` |
| filtering | `FILTER_BOTH`, `FILTER_KEY`, `FILTER_VALUE` | `filter()` |
| sorting/comparison | `SORT_NATURAL`, `SORT_NUMERIC`, `SORT_REGULAR`, `SORT_STRING` | `sort()` and `unique()` |

The values mirror the corresponding PHP constants.

## Method guide

The methods below use the imported `Arr` class from [Common operations](#common-operations).

### Dot paths

Dot paths traverse nested array keys. A key with a `null` value still exists for `hasDot()` because lookup uses `array_key_exists()`.

| Method | Behavior |
| --- | --- |
| `getDot(array $array, string $key, mixed $default = null): mixed` | return a nested value or `$default` when any segment is missing or not an array |
| `hasDot(array $array, string $key): bool` | check that every nested key exists |
| `setDot(array $array, string $key, mixed $value, bool $overwrite = true): array` | set a nested value, creating intermediate arrays as needed |
| `forgetDot(array $array, string $key): array` | remove a nested key, leaving the input unchanged when the path is missing |
| `pluckDot(array $arrays, string $key): array` | return the value at `$key` from every row |

Only `setDot()` supports `*` segments. A wildcard applies the remaining path to every child at that level; non-array children are replaced with arrays. `$overwrite` affects only the final key:

```php
$items = Arr::setDot(
    [
        ['name' => 'First'],
        [],
    ],
    '*.name',
    'Untitled',
    false
);

// [
//     ['name' => 'First'],
//     ['name' => 'Untitled'],
// ]
```

### Shape and selection

| Method | Return behavior |
| --- | --- |
| `dot(array $array, string\|null $prefix = null, array &$result = []): array` | flatten nested arrays to dot-separated keys; objects and other values remain terminal |
| `flatten(array $array, int $maxDepth = 1, array &$result = []): array` | flatten nested arrays into a reindexed list up to `$maxDepth` |
| `divide(array $array): array` | return `[array_keys($array), array_values($array)]` |
| `wrap(mixed $value): array` | return arrays unchanged, `[]` for `null`, or `[$value]` otherwise |
| `fill(int $amount, mixed $value): array` | create a list containing `$amount` copies of `$value` |
| `range(float\|int\|string $start, float\|int\|string $end, float\|int $step = 1): array` | create an inclusive native PHP range |
| `keys(array $array): array` | return all keys |
| `values(array $array): array` | return all values as a reindexed list |
| `only(array $array, array $keys): array` | preserve entries whose keys are in `$keys`, using strict comparison |
| `except(array $array, array $keys): array` | preserve entries whose keys are not in `$keys`, using strict comparison |
| `column(array $arrays, int\|string $key): array` | return one column from a list of rows |
| `index(array $array, int\|string $key): array` | index rows by one column |
| `combine(array $keys, array $values): array` | use one array as keys and another as values |
| `join(array $array, string $separator = ','): string` | concatenate values with `$separator` |

`flatten()` throws an `InvalidArgumentException` when `$maxDepth` is below `1`. Both `dot()` and `flatten()` accept a result array by reference for recursive or advanced use; ordinary callers should omit it.

```php
Arr::dot(['user' => ['id' => 10, 'name' => 'Ada']]);
// ['user.id' => 10, 'user.name' => 'Ada']

Arr::flatten([1, [2, [3]]], 1); // [1, 2, [3]]
Arr::flatten([1, [2, [3]]], 2); // [1, 2, 3]
```

### Searching and predicates

Callbacks in this group receive `(value, key)`.

| Method | Return behavior |
| --- | --- |
| `hasKey(array $array, int\|string $key): bool` | whether the key exists, including when its value is `null` |
| `includes(array $array, mixed $value, bool $strict = false): bool` | whether the value occurs |
| `find(array $array, callable $callback, mixed $default = null): mixed` | first matching value or `$default` |
| `findLast(array $array, callable $callback, mixed $default = null): mixed` | last matching value or `$default` |
| `findKey(array $array, callable $callback): mixed` | first matching key or `null` |
| `findLastKey(array $array, callable $callback): mixed` | last matching key or `null` |
| `first(array $array): mixed` | first value or `null` for an empty array |
| `last(array $array): mixed` | last value or `null` for an empty array |
| `indexOf(array $array, mixed $value, bool $strict = false): false\|int\|string` | first matching key or `false` |
| `lastIndexOf(array $array, mixed $value, bool $strict = false): false\|int\|string` | last matching key or `false` |
| `every(array $array, callable $callback): bool` | whether every element passes |
| `some(array $array, callable $callback): bool` | whether at least one element passes |
| `none(array $array, callable $callback): bool` | whether no elements pass |
| `isArray(mixed $value): bool` | whether the value is an array |
| `isList(array $array): bool` | whether keys are consecutive integers starting at `0` |
| `count(array $array, int $mode = Arr::COUNT_NORMAL): int` | count at the selected depth |

`count()` accepts only `COUNT_NORMAL` and `COUNT_RECURSIVE`; other modes throw an `InvalidArgumentException`.

### Transforming and comparing

| Method | Behavior |
| --- | --- |
| `map(array $array, callable $callback): array` | call `(value, key)` and preserve each original key |
| `filter(array $array, callable\|null $callback = null, int $mode = Arr::FILTER_BOTH): array` | keep matching entries and preserve keys |
| `reduce(array $array, callable $callback, mixed $initial = null): mixed` | reduce with a `(carry, value)` callback |
| `merge(array ...$arrays): array` | apply native `array_merge()` semantics |
| `collapse(array $array, array ...$replacements): array` | recursively replace values with `array_replace_recursive()` |
| `reverse(array $array, bool $preserveKeys = false): array` | reverse values, optionally preserving keys |
| `shuffle(array $array): array` | shuffle and return a reindexed list |
| `sort(array $array, Closure\|int $sort = Arr::SORT_NATURAL): array` | sort and return a reindexed list using a flag or comparison closure |
| `unique(array $array, int $flags = Arr::SORT_REGULAR): array` | remove duplicate values while retaining the first occurrence's key |
| `diff(array $array, array ...$arrays): array` | preserve values absent from every comparison array |
| `intersect(array $array, array ...$arrays): array` | preserve values present in every comparison array |

To sort strings using Swedish collation rules, use `Arr::sort($names, (new Collator('sv_SE'))->compare(...))`.

With no callback, `filter()` removes falsey values using native `array_filter()` behavior. With a callback, its default mode is `FILTER_BOTH`, so the callback receives both value and key. Use `FILTER_VALUE` or `FILTER_KEY` to receive only one argument.

### Slicing and mutation

| Method | Mutation | Return behavior |
| --- | --- | --- |
| `chunk(array $array, int $size, bool $preserveKeys = false): array` | none | list of chunks; throws when `$size < 1` |
| `slice(array $array, int $offset = 0, int\|null $length = null, bool $preserveKeys = false): array` | none | selected slice |
| `pad(array $array, int $size, mixed $value): array` | none | padded copy; a negative size pads on the left |
| `randomValue(array $array): mixed` | none | random value, or `null` for an empty array |
| `pop(array &$array): mixed` | removes the last entry | removed value or `null` |
| `push(array &$array, mixed ...$values): int` | appends values | new count |
| `shift(array &$array): mixed` | removes the first entry | removed value or `null` |
| `unshift(array &$array, mixed ...$values): int` | prepends values | new count |
| `splice(array &$array, int $offset, int\|null $length = null, mixed $replacement = []): array` | removes a range and inserts replacements | removed values |

```php
$values = [1, 2, 3, 4];
$removed = Arr::splice($values, 1, 2, ['a']);

// $values === [1, 'a', 4]
// $removed === [2, 3]
```

## Behavior notes

- Methods return copies unless their signature accepts `array &$array`; assigning the result is therefore required for `setDot()`, `forgetDot()`, sorting, filtering, and other transformations.
- `sort()` and `shuffle()` reindex all values. `reverse()` with `$preserveKeys = false` reindexes only integer keys; string keys are always preserved. `filter()`, `unique()`, `diff()`, and `intersect()` preserve surviving keys.
- `dot()` and `flatten()` recurse only into arrays. Objects, collections, and other traversables remain terminal values.
- `combine()`, `fill()`, `range()`, `pad()`, and the other native wrappers retain PHP's native validation and error behavior unless this page states otherwise.
- `Arr` supports static macros.

## Related

- [Utilities](index.md)
- [Collections](collections.md)
