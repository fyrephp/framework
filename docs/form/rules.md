# Validation Rules

Each `Fyre\Form\Rule::*()` factory creates a reusable validation rule. Attach the returned object to a [Validator](validators.md), or use it anywhere a `Rule` is accepted.

## Table of Contents

- [Rule factories and skip behavior](#rule-factories-and-skip-behavior)
- [Common patterns](#common-patterns)
  - [Optional format check](#optional-format-check)
  - [Required + format](#required--format)
  - [Require presence vs required](#require-presence-vs-required)
  - [Cross-field matching](#cross-field-matching)
- [Text rules](#text-rules)
- [Numeric rules](#numeric-rules)
- [Length rules](#length-rules)
- [Comparison rules](#comparison-rules)
- [Membership and format rules](#membership-and-format-rules)
- [Cross-field rules](#cross-field-rules)
- [Presence and emptiness rules](#presence-and-emptiness-rules)
- [Date and time rules](#date-and-time-rules)
- [Related](#related)

## Rule factories and skip behavior

Most `Rule::*()` factories default to:

- `skipEmpty = true`
- `skipNotSet = true`

Each factory also assigns its own rule name for language fallback, so you do not need to pass the same name to `Validator::add()`.

A rule is typically evaluated only when a field is present and non-empty. Empty means `null`, `''`, or `[]`. The most important exceptions are:

- `Rule::notEmpty()` — does not skip empty values.
- `Rule::required()` — does not skip empty values and does not skip when the field is not set.
- `Rule::requirePresence()` — does not skip empty values and does not skip when the field is not set.

## Common patterns

Most examples below assume you already have a `$validator` instance.

### Optional format check

If a field is optional, you can usually attach only the format rule. Most format rules skip empty values by default, so missing/empty values won’t fail:

```php
$validator->add('website', Rule::url());
```

### Required + format

If a field must be present and non-empty, combine `required()` with another rule:

```php
$validator->add('email', Rule::required());
$validator->add('email', Rule::email());
```

### Require presence vs required

Use `requirePresence()` when the key must exist (even if the value is `null`). Use `required()` when you need a non-empty value:

```php
$validator->add('middle_name', Rule::requirePresence());
$validator->add('first_name', Rule::required());
```

### Cross-field matching

Use `matches()` when one field must match another (for example, password confirmation). Add `required()` when the confirmation itself is mandatory because `matches()` skips missing and empty values by default:

```php
$validator->add('password_confirm', Rule::required());
$validator->add('password_confirm', Rule::matches('password'));
```

## Text rules

- `Rule::alpha()` — value is scalar and consists of letters only (`ctype_alpha`).
- `Rule::alphaNumeric()` — value is scalar and consists of letters/digits only (`ctype_alnum`).
- `Rule::ascii()` — value is scalar and consists of printable characters only (`ctype_print`).

## Numeric rules

- `Rule::integer()` — value validates as an integer (`FILTER_VALIDATE_INT`).
- `Rule::decimal()` — value validates as a float (`FILTER_VALIDATE_FLOAT`).
- `Rule::naturalNumber()` — value is scalar and consists of digits only (`ctype_digit`).
- `Rule::boolean()` — value validates as a boolean (`FILTER_VALIDATE_BOOLEAN` with `FILTER_NULL_ON_FAILURE`).

## Length rules

Length rules use `strlen((string) $value)` (byte length):

- `Rule::exactLength(int $length)`
- `Rule::minLength(int $length)`
- `Rule::maxLength(int $length)`

## Comparison rules

Comparison rules compare `$value` directly:

- `Rule::between(int $min, int $max)` — `$value >= $min && $value <= $max`
- `Rule::greaterThan(int $min)` — `$value > $min`
- `Rule::greaterThanOrEquals(int $min)` — `$value >= $min`
- `Rule::lessThan(int $max)` — `$value < $max`
- `Rule::lessThanOrEquals(int $max)` — `$value <= $max`

## Membership and format rules

- `Rule::in(array<bool|float|int|string> $values)` — strict membership (`in_array(..., true)`).
- `Rule::equals(mixed $other)` — loose equality (`==`).
- `Rule::regex(string $regex)` — regex match (`preg_match(...) === 1`).
- `Rule::email()` — email validation (`FILTER_VALIDATE_EMAIL` + unicode flag).
- `Rule::url()` — URL validation (`FILTER_VALIDATE_URL`).
- `Rule::ip()` — IP validation (`FILTER_VALIDATE_IP`).
- `Rule::ipv4()` — IPv4 validation (`FILTER_VALIDATE_IP` + IPv4 flag).
- `Rule::ipv6()` — IPv6 validation (`FILTER_VALIDATE_IP` + IPv6 flag).

## Cross-field rules

These rules compare the current field value against another field in the same input data array:

- `Rule::matches(string $field)` — strict match (`===`) against `$data[$field]`.
- `Rule::differs(string $field)` — strict difference (`!==`) against `$data[$field]`.

## Presence and emptiness rules

- `Rule::notEmpty()` — fails when the value is `null`, `''`, or `[]` (and does not skip empty values).
- `Rule::required()` — requires the field to be present and not `''`/`[]`; `null` is treated as missing (uses `isset()`).
- `Rule::requirePresence()` — requires the field key to exist in the data (uses `array_key_exists()`), so `null` counts as present.
- `Rule::empty()` — always fails when evaluated; with default skip behavior this effectively enforces “must be empty or not set”.

## Date and time rules

These rules validate through the DB type parser:

| Rule | DB type | Framework value |
| --- | --- | --- |
| `Rule::date()` | `date` | `Date` |
| `Rule::dateTime()` | `datetime` | `DateTime` |
| `Rule::time()` | `time` | `Time` |

They skip missing and empty values by default; other values must parse successfully. Existing framework date/time objects must have the matching concrete class: each rule rejects instances of the other two classes.

## Related

- [Validators](validators.md)
- [Forms](forms.md)
- [Language (`Lang`)](../core/lang.md)
