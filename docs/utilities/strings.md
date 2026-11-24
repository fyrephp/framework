# Strings

`Fyre\Utility\Str` provides static helpers for casing, identifiers, searching, slicing, replacing, padding, escaping, and random strings.

These methods operate on PHP strings. Unless noted otherwise, lengths and offsets are byte-based rather than Unicode character-based.

## Table of Contents

- [Common operations](#common-operations)
- [Constants](#constants)
- [Method guide](#method-guide)
  - [Casing and identifiers](#casing-and-identifiers)
  - [Searching and extraction](#searching-and-extraction)
  - [Replacing](#replacing)
  - [Trimming and splitting](#trimming-and-splitting)
  - [Prefix, suffix, padding, and repetition](#prefix-suffix-padding-and-repetition)
  - [Encoding, randomness, and utilities](#encoding-randomness-and-utilities)
- [Behavior notes](#behavior-notes)
- [Related](#related)

## Common operations

Import `Str` once, then call its methods statically:

```php
use Fyre\Utility\Str;

$class = Str::pascal('user_profile');
$method = Str::camel('user_profile');
$route = Str::kebab('UserProfile');

$name = 'users.show';
$group = Str::before($name, '.');
$action = Str::afterLast($name, '.');
```

The values are `UserProfile`, `userProfile`, `user-profile`, `users`, and `show`, respectively. Use [Inflection](inflection.md) for pluralization, singularization, and class/table naming conventions.

## Constants

| Group | Constants | Use |
| --- | --- | --- |
| character sets | `ALPHA`, `NUMERIC`, `ALPHANUMERIC` | input for `random()` |
| HTML flags | `ENT_COMPAT`, `ENT_DISALLOWED`, `ENT_HTML401`, `ENT_HTML5`, `ENT_IGNORE`, `ENT_NOQUOTES`, `ENT_QUOTES`, `ENT_SUBSTITUTE`, `ENT_XHTML`, `ENT_XML1` | flags for `escape()`; values mirror PHP's `ENT_*` constants |
| padding | `PAD_LEFT`, `PAD_RIGHT`, `PAD_BOTH` | mode for `pad()` |
| trimming | `WHITESPACE_MASK` | default PHP whitespace mask, including form feed (`\f`), for `trim()`, `trimStart()`, and `trimEnd()` |

## Method guide

The examples and return values below use the imported `Str` class from [Common operations](#common-operations).

### Casing and identifiers

| Method | Behavior | Example result |
| --- | --- | --- |
| `camel(string $string): string` | convert `-` and `_` boundaries to `camelCase` | `Str::camel('user_profile')` → `userProfile` |
| `pascal(string $string): string` | convert `-` and `_` boundaries to `PascalCase` | `Str::pascal('user-profile')` → `UserProfile` |
| `snake(string $string): string` | create an identifier slug with `_` | `Str::snake('UserProfile')` → `user_profile` |
| `kebab(string $string): string` | create an identifier slug with `-` | `Str::kebab('UserProfile')` → `user-profile` |
| `slug(string $string, string $delimiter = '_'): string` | split case transitions and word boundaries with a delimiter, then lowercase | `Str::slug('UserProfile', '-')` → `user-profile` |
| `lower(string $string): string` | lowercase with `strtolower()` | `Str::lower('Hello')` → `hello` |
| `upper(string $string): string` | uppercase with `strtoupper()` | `Str::upper('Hello')` → `HELLO` |
| `title(string $string): string` | lowercase, then apply `ucwords()` | `Str::title('hello WORLD')` → `Hello World` |
| `capitalize(string $string): string` | lowercase, then uppercase the first byte | `Str::capitalize('hELLO')` → `Hello` |

`slug()`, `snake()`, and `kebab()` are intended for identifiers. They do not perform transliteration or full URL-safe normalization.

### Searching and extraction

| Method | Return behavior |
| --- | --- |
| `contains(string $string, string $search): bool` | whether `$search` occurs in the string |
| `containsAll(string $string, array $searches): bool` | whether every search string occurs |
| `containsAny(string $string, array $searches): bool` | whether at least one search string occurs |
| `startsWith(string $string, string $search): bool` | whether the string begins with a truthy search string |
| `endsWith(string $string, string $search): bool` | whether the string ends with a truthy search string |
| `indexOf(string $string, string $search, int $start = 0): int` | first byte offset, or `-1` when missing |
| `lastIndexOf(string $string, string $search, int $start = 0): int` | last byte offset, or `-1` when missing |
| `after(string $string, string $search): string` | content after the first match |
| `afterLast(string $string, string $search): string` | content after the last match |
| `before(string $string, string $search): string` | content before the first match |
| `beforeLast(string $string, string $search): string` | content before the last match |
| `length(string $string): int` | byte length |
| `limit(string $string, int $limit = 100, string $append = '…'): string` | at most `$limit` bytes, followed by `$append` only when truncated |
| `slice(string $string, int $start, int\|null $length = null): string` | a byte-based substring using `substr()` |

The boundary helpers return the original string when `$search` is empty or absent:

```php
Str::afterLast('users.profile.show', '.'); // "show"
Str::beforeLast('users.profile.show', '.'); // "users.profile"
Str::before('users', '.'); // "users"
```

### Replacing

| Method | Behavior |
| --- | --- |
| `replace(string $string, string $search, string $replace): string` | replace every occurrence |
| `replaceFirst(string $string, string $search, string $replace): string` | replace the first occurrence |
| `replaceLast(string $string, string $search, string $replace): string` | replace the last occurrence |
| `replaceEach(string $string, array $replacements): string` | replace each map key with its corresponding value |
| `replaceArray(string $string, string $search, array $replacements): string` | replace successive matches with successive values |
| `replaceAt(string $string, string $replace, int $position, int $length = 0): string` | replace a byte range using `substr_replace()` |

`replaceArray()` uses an empty string after the replacement list is exhausted:

```php
Str::replaceArray('?, ?, ?', '?', ['a', 'b']); // "a, b, "

Str::replaceEach('Hello, :name!', [
    ':name' => 'Taylor',
]); // "Hello, Taylor!"
```

A falsey `$search` (`''` or `'0'`) leaves the input unchanged for `replaceFirst()`, `replaceLast()`, and `replaceArray()`.

### Trimming and splitting

| Method | Behavior |
| --- | --- |
| `trim(string $string, string $mask = Str::WHITESPACE_MASK): string` | trim both ends |
| `trimStart(string $string, string $mask = Str::WHITESPACE_MASK): string` | trim the beginning |
| `trimEnd(string $string, string $mask = Str::WHITESPACE_MASK): string` | trim the end |
| `split(string $string, string $delimiter, int $limit = PHP_INT_MAX): array` | split on a delimiter; return `[]` for an empty delimiter |
| `chunk(string $string, int $size = 1): array` | split into byte chunks of at most `$size` |

`chunk()` throws an `InvalidArgumentException` when `$size` is less than `1`.

### Prefix, suffix, padding, and repetition

| Method | Behavior |
| --- | --- |
| `start(string $string, string $search): string` | prepend `$search` unless already present |
| `end(string $string, string $search): string` | append `$search` unless already present |
| `pad(string $string, int $length, string $padding = ' ', int $padType = Str::PAD_BOTH): string` | pad to a byte length using the selected mode |
| `padStart(string $string, int $length, string $padding = ' '): string` | left-pad to a byte length |
| `padEnd(string $string, int $length, string $padding = ' '): string` | right-pad to a byte length |
| `repeat(string $string, int $count): string` | repeat a string `$count` times |

```php
Str::start('images/logo.svg', '/'); // "/images/logo.svg"
Str::end('/var/cache', '/'); // "/var/cache/"
Str::padStart('5', 3, '0'); // "005"
```

### Encoding, randomness, and utilities

| Method | Behavior |
| --- | --- |
| `escape(string $string, int $flags = Str::ENT_QUOTES \| Str::ENT_HTML5): string` | escape HTML with UTF-8 `htmlspecialchars()` |
| `transliterate(string $string): string` | attempt UTF-8-to-ASCII transliteration with `iconv()` |
| `random(int $length = 16, string $chars = Str::ALPHANUMERIC): string` | generate a cryptographically selected string from `$chars` |
| `reverse(string $string): string` | reverse bytes using `strrev()` |
| `shuffle(string $string): string` | randomly shuffle bytes using `str_shuffle()` |
| `isString(mixed $value): bool` | whether the value is a string |

`random()` uses `random_int()`. It throws an `InvalidArgumentException` when `$length` is negative or `$chars` is empty. A length of `0` returns an empty string.

## Behavior notes

- `chunk()`, `length()`, `limit()`, `slice()`, `replaceAt()`, `reverse()`, and `shuffle()` operate on bytes and can split or reorder a multibyte UTF-8 character.
- `startsWith()` and `endsWith()` return `false` for a falsey search string (`''` or `'0'`). PHP's native functions treat an empty search as a match.
- `transliterate()` temporarily changes `LC_CTYPE` to `en_US.UTF8` and restores the previous locale afterward. Output depends on the installed locale and `iconv()` implementation.
- `Str` supports static macros; a registered macro can add a helper without modifying the class.

## Related

- [Utilities](index.md)
- [Inflection](inflection.md)
