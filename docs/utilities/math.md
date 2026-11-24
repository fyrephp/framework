# Math

`Fyre\Utility\Math` provides a consistent static API over PHP's numeric functions, plus helpers for interpolation, mapping, clamping, distances, and step rounding.

## Table of Contents

- [Common operations](#common-operations)
- [Constants](#constants)
- [Method guide](#method-guide)
  - [Values and rounding](#values-and-rounding)
  - [Ranges and interpolation](#ranges-and-interpolation)
  - [Geometry and angles](#geometry-and-angles)
  - [Trigonometry](#trigonometry)
  - [Random values](#random-values)
  - [Bases, powers, and logarithms](#bases-powers-and-logarithms)
- [Behavior notes](#behavior-notes)
- [Related](#related)

## Common operations

Import `Math` once and call its methods statically:

```php
use Fyre\Utility\Math;

$percentage = Math::clampPercent(125);
$progress = Math::map(75, 0, 100, 0, 1);
$rounded = Math::toStep(12.37, 0.25);
$distance = Math::dist(0, 0, 3, 4);
```

The results are `100`, `0.75`, `12.25`, and `5.0`.

## Constants

| Constant | Value represented |
| --- | --- |
| `E` | Euler's number |
| `EPSILON` | PHP's floating-point machine epsilon |
| `FLOAT_MAX` | largest finite platform float |
| `FLOAT_MIN` | smallest positive normalized platform float |
| `HALF_PI` | π / 2 |
| `QUARTER_PI` | π / 4 |
| `PI` | π |
| `TAU`, `TWO_PI` | 2π |
| `INFINITY` | positive infinity |
| `INT_MAX`, `INT_MIN` | platform integer bounds |

## Method guide

The methods below use the imported `Math` class from [Common operations](#common-operations).

### Values and rounding

| Method | Behavior |
| --- | --- |
| `abs(float\|int $number): float\|int` | absolute value |
| `min(float\|int ...$numbers): float\|int` | lowest supplied value |
| `max(float\|int ...$numbers): float\|int` | highest supplied value |
| `sum(float\|int ...$numbers): float\|int` | sum; `0` with no arguments |
| `product(float\|int ...$numbers): float\|int` | product; `1` with no arguments |
| `isNumeric(mixed $value): bool` | native `is_numeric()` result, including numeric strings |
| `ceil(float\|int $number): float` | round toward positive infinity |
| `floor(float\|int $number): float` | round toward negative infinity |
| `round(float\|int $number, int $precision = 0, RoundingMode $mode = RoundingMode::HalfAwayFromZero): float` | round at a decimal precision with a PHP rounding mode |
| `toStep(float\|int $number, float\|int $step = 0.01): float` | round to the nearest multiple of the absolute step |

```php
use RoundingMode;

Math::round(12.5, mode: RoundingMode::HalfEven); // 12.0
Math::toStep(12.37, 0.25); // 12.25
```

`toStep()` returns the input cast to `float` when the step is zero. `min()` and `max()` retain PHP's requirement for at least one value.

### Ranges and interpolation

| Method | Behavior |
| --- | --- |
| `clamp(float\|int $number, float\|int $min = 0, float\|int $max = 1): float\|int` | constrain a value between the supplied bounds |
| `clampPercent(float\|int $number): float\|int` | constrain a value to `0..100` |
| `lerp(float $v1, float $v2, float $amount): float` | interpolate between endpoints |
| `inverseLerp(float $v1, float $v2, float $value): float` | calculate the interpolation amount for a value |
| `map(float $number, float $fromMin, float $fromMax, float $toMin, float $toMax): float` | map a value from one range to another |

Interpolation and mapping do not clamp, so amounts and values outside the source range extrapolate. `inverseLerp()` returns `0.0` when both endpoints are equal; `map()` returns `$toMin` when the source range has no width.

### Geometry and angles

| Method | Behavior |
| --- | --- |
| `dist(float $x1, float $y1, float $x2, float $y2): float` | Euclidean distance between two 2D points |
| `hypot(float $x, float $y): float` | Euclidean length of a 2D vector |
| `degreesToRadians(float $number): float` | degrees to radians |
| `radiansToDegrees(float $number): float` | radians to degrees |

```php
Math::dist(0, 0, 3, 4); // 5.0
Math::degreesToRadians(180); // approximately 3.1415926535898
```

### Trigonometry

Angles use radians.

| Family | Methods |
| --- | --- |
| circular | `sin(float $number)`, `cos(float $number)`, `tan(float $number)` |
| inverse circular | `asin(float $number)`, `acos(float $number)`, `atan(float $number)` |
| two-coordinate angle | `atan2(float $y, float $x)` |
| hyperbolic | `sinh(float $number)`, `cosh(float $number)`, `tanh(float $number)` |
| inverse hyperbolic | `asinh(float $number)`, `acosh(float $number)`, `atanh(float $number)` |

Every method in this group returns `float` and follows the domain and special-value behavior of the corresponding PHP function.

### Random values

#### **Generate a random float** (`random()`)

```php
random(float|null $a = null, float|null $b = null): float
```

- With no bounds, the range is `0..1`.
- With one bound, the range is between that value and `0`.
- With two bounds, their order does not matter.

The method derives a fraction from `random_int(0, PHP_INT_MAX)`, so either endpoint can be returned.

#### **Generate a random integer** (`randomInt()`)

```php
randomInt(int $a, int|null $b = null): int
```

With one bound, the range is between it and `0`. With two bounds, the values are passed directly to `random_int()`: `$a` must not exceed `$b`. Both endpoints are inclusive.

### Bases, powers, and logarithms

| Method | Behavior |
| --- | --- |
| `convertBase(int\|string $number, int $fromBase, int $toBase): string` | convert between bases `2..36` |
| `binaryToDecimal(string $binaryString): float\|int` | binary to decimal |
| `hexToDecimal(string $hexString): float\|int` | hexadecimal to decimal |
| `octalToDecimal(string $octalString): float\|int` | octal to decimal |
| `decimalToBinary(int $number): string` | decimal to binary |
| `decimalToHex(int $number): string` | decimal to lowercase hexadecimal |
| `decimalToOctal(int $number): string` | decimal to octal |
| `pow(float\|int $number, float\|int $exponent): float\|int` | raise a number to a power |
| `sqrt(float $number): float` | square root |
| `exp(float $number): float` | e raised to a power |
| `expMinus1(float $number): float` | e raised to a power, minus one, with improved precision near zero |
| `log(float $number, float $base = Math::E): float` | logarithm at the selected base |
| `log10(float $number): float` | base-10 logarithm |
| `logPlus1(float $number): float` | natural logarithm of one plus the input, with improved precision near zero |
| `fmod(float $number, float $divisor): float` | floating-point remainder |

The decimal result helpers return `float` when a value does not fit in the platform integer range.

## Behavior notes

- Most methods are direct wrappers and retain PHP's validation, domain, overflow, `NAN`, and infinity behavior.
- `clamp()` throws `ValueError` if the minimum exceeds the maximum or either bound is `NAN`. Both clamping helpers preserve the type of an in-range value, including at either bound.
- Trigonometric inputs and inverse trigonometric results use radians unless the method name explicitly converts units.
- `random()` and `randomInt()` use `random_int()` rather than a deterministic pseudo-random generator.
- `Math` supports static macros.

## Related

- [Utilities](index.md)
- [Collections](collections.md)
- [Formatter](formatter.md)
