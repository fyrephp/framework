<?php
declare(strict_types=1);

namespace Fyre\Utility;

use Fyre\Core\Traits\StaticMacroTrait;
use RoundingMode;

use function abs;
use function acos;
use function acosh;
use function array_product;
use function array_sum;
use function asin;
use function asinh;
use function atan;
use function atan2;
use function atanh;
use function base_convert;
use function bindec;
use function ceil;
use function clamp;
use function cos;
use function cosh;
use function decbin;
use function dechex;
use function decoct;
use function deg2rad;
use function exp;
use function expm1;
use function floor;
use function fmod;
use function hexdec;
use function hypot;
use function is_numeric;
use function log;
use function log10;
use function log1p;
use function max;
use function min;
use function octdec;
use function pow;
use function rad2deg;
use function random_int;
use function round;
use function sin;
use function sinh;
use function sqrt;
use function tan;
use function tanh;

use const INF;
use const M_E;
use const M_PI;
use const M_PI_2;
use const M_PI_4;
use const PHP_FLOAT_EPSILON;
use const PHP_FLOAT_MAX;
use const PHP_FLOAT_MIN;

/**
 * Provides math utilities and constants.
 *
 * Most methods are wrappers around PHP's built-in math functions with consistent naming and argument ordering.
 */
abstract class Math
{
    use StaticMacroTrait;

    public const E = M_E;

    public const EPSILON = PHP_FLOAT_EPSILON;

    public const FLOAT_MAX = PHP_FLOAT_MAX;

    public const FLOAT_MIN = PHP_FLOAT_MIN;

    public const HALF_PI = M_PI_2;

    public const INFINITY = INF;

    public const INT_MAX = PHP_INT_MAX;

    public const INT_MIN = PHP_INT_MIN;

    public const PI = M_PI;

    public const QUARTER_PI = M_PI_4;

    public const TAU = M_PI * 2;

    public const TWO_PI = M_PI * 2;

    /**
     * Returns the absolute value of a number.
     *
     * @param float|int $number The input number.
     * @return float|int The absolute value.
     */
    public static function abs(float|int $number): float|int
    {
        return abs($number);
    }

    /**
     * Returns the arc cosine of a number.
     *
     * @param float $number The input number.
     * @return float The arc cosine.
     */
    public static function acos(float $number): float
    {
        return acos($number);
    }

    /**
     * Returns the inverse hyperbolic cosine of a number.
     *
     * @param float $number The input number.
     * @return float The inverse hyperbolic cosine.
     */
    public static function acosh(float $number): float
    {
        return acosh($number);
    }

    /**
     * Returns the arc sine of a number.
     *
     * @param float $number The input number.
     * @return float The arc sine.
     */
    public static function asin(float $number): float
    {
        return asin($number);
    }

    /**
     * Returns the inverse hyperbolic sine of a number.
     *
     * @param float $number The input number.
     * @return float The inverse hyperbolic sine.
     */
    public static function asinh(float $number): float
    {
        return asinh($number);
    }

    /**
     * Returns the arc tangent of a number.
     *
     * @param float $number The input number.
     * @return float The arc tangent.
     */
    public static function atan(float $number): float
    {
        return atan($number);
    }

    /**
     * Returns the arc tangent of 2 numbers.
     *
     * @param float $y The dividend number.
     * @param float $x The divisor number.
     * @return float The arc tangent.
     */
    public static function atan2(float $y, float $x): float
    {
        return atan2($y, $x);
    }

    /**
     * Returns the inverse hyperbolic tangent of a number.
     *
     * @param float $number The input number.
     * @return float The inverse hyperbolic tangent.
     */
    public static function atanh(float $number): float
    {
        return atanh($number);
    }

    /**
     * Converts a binary number to decimal.
     *
     * Note: Returns an int when the value fits in the platform integer size,
     * otherwise a float.
     *
     * @param string $binaryString The binary string.
     * @return float|int The decimal number.
     */
    public static function binaryToDecimal(string $binaryString): float|int
    {
        return bindec($binaryString);
    }

    /**
     * Rounds a number up.
     *
     * @param float|int $number The input number.
     * @return float The rounded number.
     */
    public static function ceil(float|int $number): float
    {
        return ceil($number);
    }

    /**
     * Clamps a number between a min and max.
     *
     * @param float|int $number The input number.
     * @param float|int $min The minimum number.
     * @param float|int $max The maximum number.
     * @return float|int The clamped number.
     */
    public static function clamp(float|int $number, float|int $min = 0, float|int $max = 1): float|int
    {
        return clamp($number, $min, $max);
    }

    /**
     * Clamps a number between 0 and 100.
     *
     * @param float|int $number The input number.
     * @return float|int The clamped number.
     */
    public static function clampPercent(float|int $number): float|int
    {
        return clamp($number, 0, 100);
    }

    /**
     * Converts a number between bases.
     *
     * Note: Bases must be between 2 and 36.
     *
     * @param int|string $number The input number.
     * @param int $fromBase The base of the number.
     * @param int $toBase The base to convert to.
     * @return string The number with converted base.
     */
    public static function convertBase(int|string $number, int $fromBase, int $toBase): string
    {
        return base_convert((string) $number, $fromBase, $toBase);
    }

    /**
     * Returns the cosine of a number.
     *
     * @param float $number The input number.
     * @return float The cosine.
     */
    public static function cos(float $number): float
    {
        return cos($number);
    }

    /**
     * Returns the hyperbolic cosine of a number.
     *
     * @param float $number The input number.
     * @return float The hyperbolic cosine.
     */
    public static function cosh(float $number): float
    {
        return cosh($number);
    }

    /**
     * Converts a decimal number to binary.
     *
     * @param int $number The input number.
     * @return string The binary number.
     */
    public static function decimalToBinary(int $number): string
    {
        return decbin($number);
    }

    /**
     * Converts a decimal number to hex.
     *
     * @param int $number The input number.
     * @return string The hex number.
     */
    public static function decimalToHex(int $number): string
    {
        return dechex($number);
    }

    /**
     * Converts a decimal number to octal.
     *
     * @param int $number The input number.
     * @return string The octal number.
     */
    public static function decimalToOctal(int $number): string
    {
        return decoct($number);
    }

    /**
     * Converts a number of degrees to radians.
     *
     * @param float $number The input number.
     * @return float The number of radians.
     */
    public static function degreesToRadians(float $number): float
    {
        return deg2rad($number);
    }

    /**
     * Calculates the distance between 2 points.
     *
     * @param float $x1 The first X coordinate.
     * @param float $y1 The first Y coordinate.
     * @param float $x2 The second X coordinate.
     * @param float $y2 The second Y coordinate.
     * @return float The distance.
     */
    public static function dist(float $x1, float $y1, float $x2, float $y2): float
    {
        return hypot($x1 - $x2, $y1 - $y2);
    }

    /**
     * Calculates the exponent of e.
     *
     * @param float $number The input number.
     * @return float The exponent of e.
     */
    public static function exp(float $number): float
    {
        return exp($number);
    }

    /**
     * Calculates the exponent of e minus 1.
     *
     * @param float $number The input number.
     * @return float The exponent of e minus 1.
     */
    public static function expMinus1(float $number): float
    {
        return expm1($number);
    }

    /**
     * Rounds a number down.
     *
     * @param float|int $number The input number.
     * @return float The rounded number.
     */
    public static function floor(float|int $number): float
    {
        return floor($number);
    }

    /**
     * Calculates the modulo of a number with a divisor.
     *
     * @param float $number The input number.
     * @param float $divisor The divisor.
     * @return float The modulo.
     */
    public static function fmod(float $number, float $divisor): float
    {
        return fmod($number, $divisor);
    }

    /**
     * Converts a hex number to decimal.
     *
     * Note: Returns an int when the value fits in the platform integer size,
     * otherwise a float.
     *
     * @param string $hexString The hex number.
     * @return float|int The decimal number.
     */
    public static function hexToDecimal(string $hexString): float|int
    {
        return hexdec($hexString);
    }

    /**
     * Calculates the length (Euclidean norm) of a 2D vector.
     *
     * @param float $x The X coordinate.
     * @param float $y The Y coordinate.
     * @return float The length.
     */
    public static function hypot(float $x, float $y): float
    {
        return hypot($x, $y);
    }

    /**
     * Calculates inverse linear interpolation from one value to another.
     *
     * If $v1 and $v2 are equal, 0.0 is returned.
     *
     * @param float $v1 The minimum of the range.
     * @param float $v2 The maximum of the range.
     * @param float $value The value to inverse interpolate.
     * @return float The inverse interpolation.
     */
    public static function inverseLerp(float $v1, float $v2, float $value): float
    {
        if ($v1 === $v2) {
            return 0.0;
        }

        return ($value - $v1) / ($v2 - $v1);
    }

    /**
     * Checks whether the value is numeric.
     *
     * @param mixed $value The value to test.
     * @return bool Whether the value is numeric.
     */
    public static function isNumeric(mixed $value): bool
    {
        return is_numeric($value);
    }

    /**
     * Calculates linear interpolation from one value to another.
     *
     * @param float $v1 The minimum of the range.
     * @param float $v2 The maximum of the range.
     * @param float $amount The amount to interpolate.
     * @return float The interpolated value.
     */
    public static function lerp(float $v1, float $v2, float $amount): float
    {
        return $v1
            * (1 - $amount)
            + $v2
            * $amount;
    }

    /**
     * Calculates the logarithm of a number with a given base.
     *
     * @param float $number The input number.
     * @param float $base The logarithmic base.
     * @return float The logarithm of the number with the specified base.
     */
    public static function log(float $number, float $base = self::E): float
    {
        return log($number, $base);
    }

    /**
     * Calculates the base-10 logarithm.
     *
     * @param float $number The input number.
     * @return float The base-10 logarithm.
     */
    public static function log10(float $number): float
    {
        return log10($number);
    }

    /**
     * Calculates the natural logarithm of (number + 1).
     *
     * @param float $number The input number.
     * @return float The natural log of (number + 1).
     */
    public static function logPlus1(float $number): float
    {
        return log1p($number);
    }

    /**
     * Maps a value from one range to another.
     *
     * @param float $number The input number.
     * @param float $fromMin The minimum value of the current range.
     * @param float $fromMax The maximum value of the current range.
     * @param float $toMin The minimum value of the target range.
     * @param float $toMax The maximum value of the target range.
     * @return float The mapped value.
     */
    public static function map(float $number, float $fromMin, float $fromMax, float $toMin, float $toMax): float
    {
        if ($fromMin === $fromMax) {
            return $toMin;
        }

        return ($number - $fromMin)
            * ($toMax - $toMin)
            / ($fromMax - $fromMin)
            + $toMin;
    }

    /**
     * Finds the highest value.
     *
     * @param float|int ...$numbers The values to compare.
     * @return float|int The highest value.
     */
    public static function max(float|int ...$numbers): float|int
    {
        return max(...$numbers);
    }

    /**
     * Finds the lowest value.
     *
     * @param float|int ...$numbers The values to compare.
     * @return float|int The lowest value.
     */
    public static function min(float|int ...$numbers): float|int
    {
        return min(...$numbers);
    }

    /**
     * Converts an octal number to decimal.
     *
     * Note: Returns an int when the value fits in the platform integer size,
     * otherwise a float.
     *
     * @param string $octalString The octal number.
     * @return float|int The decimal number.
     */
    public static function octalToDecimal(string $octalString): float|int
    {
        return octdec($octalString);
    }

    /**
     * Raises a number to the power of exponent.
     *
     * @param float|int $number The input number.
     * @param float|int $exponent The exponent.
     * @return float|int The number raised to the power of exponent.
     */
    public static function pow(float|int $number, float|int $exponent): float|int
    {
        return pow($number, $exponent);
    }

    /**
     * Calculates the product of values.
     *
     * Note: If no numbers are provided, 1 is returned.
     *
     * @param float|int ...$numbers The input numbers.
     * @return float|int The product of values.
     */
    public static function product(float|int ...$numbers): float|int
    {
        return array_product($numbers);
    }

    /**
     * Converts a number of radians to degrees.
     *
     * @param float $number The input number.
     * @return float The number of degrees.
     */
    public static function radiansToDegrees(float $number): float
    {
        return rad2deg($number);
    }

    /**
     * Returns a random floating-point number.
     *
     * - random()           => [0, 1]
     * - random($a)         => between min(0, $a) and max(0, $a) (inclusive)
     * - random($a, $b)     => between min($a, $b) and max($a, $b) (inclusive)
     *
     * @param float|null $a The boundary of the range, or the maximum if $b is null.
     * @param float|null $b The other boundary of the range, or null to use 0.
     * @return float The random floating-point number.
     */
    public static function random(float|null $a = null, float|null $b = null): float
    {
        $r = random_int(0, PHP_INT_MAX) / PHP_INT_MAX;

        if ($a === null && $b === null) {
            return $r;
        }

        if ($b === null) {
            $min = min(0.0, $a);
            $max = max(0.0, $a);
        } else {
            $min = min($a, $b);
            $max = max($a, $b);
        }

        return $min + ($max - $min) * $r;
    }

    /**
     * Returns a random integer.
     *
     * If $b is null, the range is between 0 and $a (inclusive),
     * or between $a and 0 if $a is negative.
     *
     * @param int $a The boundary of the range, or the maximum if $b is null and $a > 0.
     * @param int|null $b The other boundary of the range, or null to use 0.
     * @return int The random integer.
     */
    public static function randomInt(int $a, int|null $b = null): int
    {
        if ($b === null) {
            [$a, $b] = $a > 0 ? [0, $a] : [$a, 0];
        }

        return random_int($a, $b);
    }

    /**
     * Rounds a number.
     *
     * @param float|int $number The input number.
     * @param int $precision The number of decimal digits to use.
     * @param RoundingMode $mode The rounding mode.
     * @return float The rounded number.
     */
    public static function round(float|int $number, int $precision = 0, RoundingMode $mode = RoundingMode::HalfAwayFromZero): float
    {
        return round($number, $precision, $mode);
    }

    /**
     * Returns the sine of a number.
     *
     * @param float $number The input number.
     * @return float The sine.
     */
    public static function sin(float $number): float
    {
        return sin($number);
    }

    /**
     * Returns the hyperbolic sine of a number.
     *
     * @param float $number The input number.
     * @return float The hyperbolic sine.
     */
    public static function sinh(float $number): float
    {
        return sinh($number);
    }

    /**
     * Returns the square root of a number.
     *
     * @param float $number The input number.
     * @return float The square root.
     */
    public static function sqrt(float $number): float
    {
        return sqrt($number);
    }

    /**
     * Calculates the sum of values.
     *
     * Note: If no numbers are provided, 0 is returned.
     *
     * @param float|int ...$numbers The input numbers.
     * @return float|int The sum of values.
     */
    public static function sum(float|int ...$numbers): float|int
    {
        return array_sum($numbers);
    }

    /**
     * Returns the tangent of a number.
     *
     * @param float $number The input number.
     * @return float The tangent.
     */
    public static function tan(float $number): float
    {
        return tan($number);
    }

    /**
     * Returns the hyperbolic tangent of a number.
     *
     * @param float $number The input number.
     * @return float The hyperbolic tangent.
     */
    public static function tanh(float $number): float
    {
        return tanh($number);
    }

    /**
     * Rounds a number to a specified step-size.
     *
     * @param float|int $number The input number.
     * @param float|int $step The minimum step-size.
     * @return float The rounded number.
     */
    public static function toStep(float|int $number, float|int $step = 0.01): float
    {
        if ($step === 0.0 || $step === 0) {
            return (float) $number;
        }

        $step = abs($step);

        return static::round($number / $step) * $step;
    }
}
