<?php
declare(strict_types=1);

namespace Fyre\Utility;

use Fyre\Core\Traits\StaticMacroTrait;
use InvalidArgumentException;

use function array_keys;
use function array_map;
use function array_shift;
use function array_values;
use function ctype_lower;
use function explode;
use function htmlspecialchars;
use function iconv;
use function implode;
use function is_string;
use function lcfirst;
use function ltrim;
use function preg_replace;
use function random_int;
use function rtrim;
use function setlocale;
use function str_contains;
use function str_ends_with;
use function str_pad;
use function str_repeat;
use function str_replace;
use function str_shuffle;
use function str_split;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrev;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;
use function substr_replace;
use function trim;
use function ucfirst;
use function ucwords;

use const ENT_COMPAT;
use const ENT_DISALLOWED;
use const ENT_HTML401;
use const ENT_HTML5;
use const ENT_IGNORE;
use const ENT_NOQUOTES;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const ENT_XHTML;
use const ENT_XML1;
use const LC_CTYPE;
use const STR_PAD_BOTH;
use const STR_PAD_LEFT;
use const STR_PAD_RIGHT;

/**
 * Provides string utilities and common string transformations.
 *
 * Note: Some operations (e.g. transliteration) depend on the system locale and iconv implementation.
 */
abstract class Str
{
    use StaticMacroTrait;

    public const ALPHA = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public const ALPHANUMERIC = self::ALPHA.self::NUMERIC;

    public const ENT_COMPAT = ENT_COMPAT;

    public const ENT_DISALLOWED = ENT_DISALLOWED;

    public const ENT_HTML401 = ENT_HTML401;

    public const ENT_HTML5 = ENT_HTML5;

    public const ENT_IGNORE = ENT_IGNORE;

    public const ENT_NOQUOTES = ENT_NOQUOTES;

    public const ENT_QUOTES = ENT_QUOTES;

    public const ENT_SUBSTITUTE = ENT_SUBSTITUTE;

    public const ENT_XHTML = ENT_XHTML;

    public const ENT_XML1 = ENT_XML1;

    public const NUMERIC = '0123456789';

    public const PAD_BOTH = STR_PAD_BOTH;

    public const PAD_LEFT = STR_PAD_LEFT;

    public const PAD_RIGHT = STR_PAD_RIGHT;

    public const WHITESPACE_MASK = " \t\n\r\0\x0B";

    /**
     * Returns the contents of a string after the first occurrence of a substring.
     *
     * @param string $string The input string.
     * @param string $search The search string.
     * @return string The after substring.
     */
    public static function after(string $string, string $search): string
    {
        if ($search === '') {
            return $string;
        }

        $position = strpos($string, $search);

        return $position !== false ?
            substr($string, $position + strlen($search)) :
            $string;
    }

    /**
     * Returns the contents of a string after the last occurrence of a substring.
     *
     * @param string $string The input string.
     * @param string $search The search string.
     * @return string The after substring.
     */
    public static function afterLast(string $string, string $search): string
    {
        if ($search === '') {
            return $string;
        }

        $position = strrpos($string, $search);

        return $position !== false ?
            substr($string, $position + strlen($search)) :
            $string;
    }

    /**
     * Returns the contents of a string before the first occurrence of a substring.
     *
     * @param string $string The input string.
     * @param string $search The search string.
     * @return string The before substring.
     */
    public static function before(string $string, string $search): string
    {
        if ($search === '') {
            return $string;
        }

        $position = strpos($string, $search);

        return $position !== false ?
            substr($string, 0, $position) :
            $string;
    }

    /**
     * Returns the contents of a string before the last occurrence of a substring.
     *
     * @param string $string The input string.
     * @param string $search The search string.
     * @return string The before substring.
     */
    public static function beforeLast(string $string, string $search): string
    {
        if ($search === '') {
            return $string;
        }

        $position = strrpos($string, $search);

        return $position !== false ?
            substr($string, 0, $position) :
            $string;
    }

    /**
     * Converts a string into camelCase.
     *
     * @param string $string The input string.
     * @return string The camelCase string.
     */
    public static function camel(string $string): string
    {
        return static::pascal($string) |> lcfirst(...);
    }

    /**
     * Capitalizes the first character of a string.
     *
     * @param string $string The input string.
     * @return string The capitalized string.
     */
    public static function capitalize(string $string): string
    {
        return strtolower($string) |> ucfirst(...);
    }

    /**
     * Splits a string into smaller chunks.
     *
     * @param string $string The input string.
     * @param int $size The maximum length of a chunk.
     * @return string[] The split substrings.
     *
     * @throws InvalidArgumentException If the chunk size is invalid.
     */
    public static function chunk(string $string, int $size = 1): array
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Chunk size must be greater than 0.');
        }

        return str_split($string, $size);
    }

    /**
     * Checks whether a string contains a substring.
     *
     * @param string $string The input string.
     * @param string $search The search string.
     * @return bool Whether the string contains the substring.
     */
    public static function contains(string $string, string $search): bool
    {
        return str_contains($string, $search);
    }

    /**
     * Checks whether a string contains all substrings.
     *
     * @param string $string The input string.
     * @param string[] $searches The search strings.
     * @return bool Whether the string contains all of the substrings.
     */
    public static function containsAll(string $string, array $searches): bool
    {
        foreach ($searches as $search) {
            if (!str_contains($string, $search)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks whether a string contains any substring.
     *
     * @param string $string The input string.
     * @param string[] $searches The search strings.
     * @return bool Whether the string contains any of the substrings.
     */
    public static function containsAny(string $string, array $searches): bool
    {
        foreach ($searches as $search) {
            if (str_contains($string, $search)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Appends a substring to a string (if it does not already end with the substring).
     *
     * @param string $string The input string.
     * @param string $search The search string.
     * @return string The appended string.
     */
    public static function end(string $string, string $search): string
    {
        return str_ends_with($string, $search) ?
            $string :
            $string.$search;
    }

    /**
     * Checks whether a string ends with a substring.
     *
     * @param string $string The input string.
     * @param string $search The search string.
     * @return bool Whether the string ends with the substring.
     */
    public static function endsWith(string $string, string $search): bool
    {
        if (!$search) {
            return false;
        }

        return str_ends_with($string, $search);
    }

    /**
     * Escapes characters in a string for use in HTML.
     *
     * @param string $string The input string.
     * @param int $flags The flags to use when escaping.
     * @return string The escaped string.
     */
    public static function escape(string $string, int $flags = self::ENT_QUOTES | self::ENT_HTML5): string
    {
        return htmlspecialchars($string, $flags, 'UTF-8');
    }

    /**
     * Returns the position of the first occurrence of a substring within a string.
     *
     * @param string $string The input string.
     * @param string $search The search string.
     * @param int $start The starting offset.
     * @return int The position of the first occurrence of the substring, or -1 if it is not found.
     */
    public static function indexOf(string $string, string $search, int $start = 0): int
    {
        $position = strpos($string, $search, $start);

        return $position !== false ?
            $position :
            -1;
    }

    /**
     * Checks whether the value is a string.
     *
     * @param mixed $value The value to test.
     * @return bool Whether the value is a string.
     */
    public static function isString(mixed $value): bool
    {
        return is_string($value);
    }

    /**
     * Converts a string into kebab-case.
     *
     * @param string $string The input string.
     * @return string The kebab-case string.
     */
    public static function kebab(string $string): string
    {
        return static::slug($string, '-');
    }

    /**
     * Returns the position of the last occurrence of a substring within a string.
     *
     * @param string $string The input string.
     * @param string $search The search string.
     * @param int $start The starting offset.
     * @return int The position of the last occurrence of the substring, or -1 if it is not found.
     */
    public static function lastIndexOf(string $string, string $search, int $start = 0): int
    {
        $position = strrpos($string, $search, $start);

        return $position !== false ?
            $position :
            -1;
    }

    /**
     * Returns the length of a string (in bytes).
     *
     * @param string $string The input string.
     * @return int The number of bytes contained in the string.
     */
    public static function length(string $string): int
    {
        return strlen($string);
    }

    /**
     * Limits a string to a specified number of bytes.
     *
     * @param string $string The input string.
     * @param int $limit The number of bytes to limit the string at.
     * @param string $append The substring to append if the string is limited.
     * @return string The limited string.
     */
    public static function limit(string $string, int $limit = 100, string $append = '…'): string
    {
        return strlen($string) > $limit ?
            substr($string, 0, $limit).$append :
            $string;
    }

    /**
     * Converts a string into lowercase.
     *
     * @param string $string The input string.
     * @return string The lowercase string.
     */
    public static function lower(string $string): string
    {
        return strtolower($string);
    }

    /**
     * Pads a string to a specified length.
     *
     * @param string $string The input string.
     * @param int $length The desired length.
     * @param string $padding The padding to use.
     * @param int $padType The type of padding to perform.
     * @return string The padded string.
     */
    public static function pad(string $string, int $length, string $padding = ' ', int $padType = self::PAD_BOTH): string
    {
        return str_pad($string, $length, $padding, $padType);
    }

    /**
     * Pads the end of a string to a specified length.
     *
     * @param string $string The input string.
     * @param int $length The desired length.
     * @param string $padding The padding to use.
     * @return string The padded string.
     */
    public static function padEnd(string $string, int $length, string $padding = ' '): string
    {
        return str_pad($string, $length, $padding, STR_PAD_RIGHT);
    }

    /**
     * Pads the start of a string to a specified length.
     *
     * @param string $string The input string.
     * @param int $length The desired length.
     * @param string $padding The padding to use.
     * @return string The padded string.
     */
    public static function padStart(string $string, int $length, string $padding = ' '): string
    {
        return str_pad($string, $length, $padding, STR_PAD_LEFT);
    }

    /**
     * Converts a string into PascalCase.
     *
     * @param string $string The input string.
     * @return string The PascalCase string.
     */
    public static function pascal(string $string): string
    {
        return str_replace(['-', '_'], ' ', $string)
            |> explode(' ', ?)
            |> array_map(ucfirst(...), ?)
            |> implode('', ?);
    }

    /**
     * Generates a random string.
     *
     * @param int $length The length of the string to generate.
     * @param string $chars The characters to use when generating the string.
     * @return string The random string.
     */
    public static function random(int $length = 16, string $chars = self::ALPHANUMERIC): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Random string length must be greater than or equal to 0.');
        }

        if ($chars === '') {
            throw new InvalidArgumentException('Random string characters must not be empty.');
        }

        $max = strlen($chars) - 1;

        $output = '';
        while ($length-- > 0) {
            $output .= $chars[random_int(0, $max)];
        }

        return $output;
    }

    /**
     * Repeats a string a specified number of times.
     *
     * @param string $string The input string.
     * @param int $count The number of times to repeat.
     * @return string The repeated string.
     */
    public static function repeat(string $string, int $count): string
    {
        return str_repeat($string, $count);
    }

    /**
     * Searches and replaces a value within a string.
     *
     * @param string $string The input string.
     * @param string $search The value to replace.
     * @param string $replace The replacement string.
     * @return string The string with replaced values.
     */
    public static function replace(string $string, string $search, string $replace): string
    {
        return str_replace($search, $replace, $string);
    }

    /**
     * Searches and replaces a value sequentially within a string.
     *
     * @param string $string The input string.
     * @param string $search The value to replace.
     * @param string[] $replacements The replacement strings.
     * @return string The string with replaced values.
     */
    public static function replaceArray(string $string, string $search, array $replacements): string
    {
        if (!$search) {
            return $string;
        }

        $offset = 0;
        $length = strlen($search);

        while (($position = strpos($string, $search, $offset)) !== false) {
            $replace = array_shift($replacements) ?? '';
            $string = substr_replace($string, $replace, $position, $length);
            $offset = $position + strlen($replace);
        }

        return $string;
    }

    /**
     * Replaces text within a portion of a string.
     *
     * @param string $string The input string.
     * @param string $replace The replacement string.
     * @param int $position The position to replace from.
     * @param int $length The length to replace.
     * @return string The string with replaced text.
     */
    public static function replaceAt(string $string, string $replace, int $position, int $length = 0): string
    {
        return substr_replace($string, $replace, $position, $length);
    }

    /**
     * Searches and replaces key/value pairs within a string.
     *
     * @param string $string The input string.
     * @param array<string, string> $replacements The replacements.
     * @return string The string with replaced values.
     */
    public static function replaceEach(string $string, array $replacements): string
    {
        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $string
        );
    }

    /**
     * Searches and replaces the first occurrence of a value within a string.
     *
     * @param string $string The input string.
     * @param string $search The value to replace.
     * @param string $replace The replacement string.
     * @return string The string with replaced values.
     */
    public static function replaceFirst(string $string, string $search, string $replace): string
    {
        if (!$search) {
            return $string;
        }

        $position = strpos($string, $search);

        return $position !== false ?
            substr_replace($string, $replace, $position, strlen($search)) :
            $string;
    }

    /**
     * Searches and replaces the last occurrence of a value within a string.
     *
     * @param string $string The input string.
     * @param string $search The value to replace.
     * @param string $replace The replacement string.
     * @return string The string with replaced values.
     */
    public static function replaceLast(string $string, string $search, string $replace): string
    {
        if (!$search) {
            return $string;
        }

        $position = strrpos($string, $search);

        return $position !== false ?
            substr_replace($string, $replace, $position, strlen($search)) :
            $string;
    }

    /**
     * Reverses the contents of a string.
     *
     * @param string $string The input string.
     * @return string The reversed string.
     */
    public static function reverse(string $string): string
    {
        return strrev($string);
    }

    /**
     * Shuffles the contents of a string.
     *
     * @param string $string The input string.
     * @return string The shuffled string.
     */
    public static function shuffle(string $string): string
    {
        return str_shuffle($string);
    }

    /**
     * Returns a specified portion of a string.
     *
     * @param string $string The input string.
     * @param int $start The starting offset.
     * @param int|null $length The maximum length to return.
     * @return string The sliced string.
     */
    public static function slice(string $string, int $start, int|null $length = null): string
    {
        return substr($string, $start, $length);
    }

    /**
     * Converts a string into a simple slug using a delimiter.
     *
     * If the string is already all lowercase alphabetic characters, it is
     * returned unchanged. Otherwise, word boundaries and camel/pascal-case
     * transitions are converted to the given delimiter and the result is
     * lowercased.
     *
     * Note: this is intended for simple identifier-style slugs and does not
     * perform full URL-safe normalization.
     *
     * @param string $string The input string.
     * @param string $delimiter The delimiter to insert between word boundaries.
     * @return string The slug string.
     */
    public static function slug(string $string, string $delimiter = '_'): string
    {
        if (ctype_lower($string)) {
            return $string;
        }

        $string = (string) preg_replace('/\s+/u', '', ucwords($string));

        return ((string) preg_replace('/(.)(?=[A-Z])/u', '$1'.$delimiter, $string))
            |> strtolower(...);
    }

    /**
     * Converts a string into snake_case.
     *
     * @param string $string The input string.
     * @return string The snake_case string.
     */
    public static function snake(string $string): string
    {
        return static::slug($string);
    }

    /**
     * Splits a string by a specified delimiter.
     *
     * If $delimiter is an empty string, an empty array is returned.
     *
     * @param string $string The input string.
     * @param string $delimiter The delimiter to split by.
     * @param int $limit The maximum number of substrings to return.
     * @return string[] The split substrings.
     */
    public static function split(string $string, string $delimiter, int $limit = PHP_INT_MAX): array
    {
        if ($delimiter === '') {
            return [];
        }

        return explode($delimiter, $string, $limit);
    }

    /**
     * Prepends a substring to a string (if it does not already begin with the substring).
     *
     * @param string $string The input string.
     * @param string $search The search string.
     * @return string The prepended string.
     */
    public static function start(string $string, string $search): string
    {
        return str_starts_with($string, $search) ?
            $string :
            $search.$string;
    }

    /**
     * Checks whether a string begins with a substring.
     *
     * @param string $string The input string.
     * @param string $search The search string.
     * @return bool Whether the string begins with the substring.
     */
    public static function startsWith(string $string, string $search): bool
    {
        if (!$search) {
            return false;
        }

        return str_starts_with($string, $search);
    }

    /**
     * Capitalizes the first character of each word in a string.
     *
     * @param string $string The input string.
     * @return string The capitalized string.
     */
    public static function title(string $string): string
    {
        return strtolower($string) |> ucwords(...);
    }

    /**
     * Transliterates the characters of a string into ASCII.
     *
     * @param string $string The input string.
     * @return string The transliterated string.
     *
     * Note: This temporarily sets `LC_CTYPE` to `en_US.UTF8` and uses `iconv()` with `TRANSLIT`; results may vary by
     * platform and installed locales.
     */
    public static function transliterate(string $string): string
    {
        $locale = setlocale(LC_CTYPE, '0');
        setlocale(LC_CTYPE, 'en_US.UTF8');

        $result = (string) iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $string
        );

        if ($locale !== false) {
            setlocale(LC_CTYPE, $locale);
        }

        return $result;
    }

    /**
     * Trims whitespace (or other characters) from the start and end of a string.
     *
     * @param string $string The input string.
     * @param string $mask The characters to trim.
     * @return string The trimmed string.
     */
    public static function trim(string $string, string $mask = self::WHITESPACE_MASK): string
    {
        return trim($string, $mask);
    }

    /**
     * Trims whitespace (or other characters) from the end of a string.
     *
     * @param string $string The input string.
     * @param string $mask The characters to trim.
     * @return string The trimmed string.
     */
    public static function trimEnd(string $string, string $mask = self::WHITESPACE_MASK): string
    {
        return rtrim($string, $mask);
    }

    /**
     * Trims whitespace (or other characters) from the start of a string.
     *
     * @param string $string The input string.
     * @param string $mask The characters to trim.
     * @return string The trimmed string.
     */
    public static function trimStart(string $string, string $mask = self::WHITESPACE_MASK): string
    {
        return ltrim($string, $mask);
    }

    /**
     * Converts a string into UPPERCASE.
     *
     * @param string $string The input string.
     * @return string The UPPERCASE string.
     */
    public static function upper(string $string): string
    {
        return strtoupper($string);
    }
}
