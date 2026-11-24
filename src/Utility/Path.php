<?php
declare(strict_types=1);

namespace Fyre\Utility;

use Fyre\Core\Traits\StaticMacroTrait;

use function array_filter;
use function array_key_last;
use function array_pop;
use function array_reverse;
use function array_unshift;
use function explode;
use function getcwd;
use function implode;
use function pathinfo;
use function preg_match;
use function str_ends_with;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_BASENAME;
use const PATHINFO_DIRNAME;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

/**
 * Provides path utilities.
 *
 * Path handling is based on the current platform `DIRECTORY_SEPARATOR`.
 */
abstract class Path
{
    use StaticMacroTrait;

    public const SEPARATOR = DIRECTORY_SEPARATOR;

    /**
     * Returns the base name from a file path.
     *
     * @param string $path The file path.
     * @return string The base name.
     */
    public static function baseName(string $path): string
    {
        return pathinfo($path, PATHINFO_BASENAME);
    }

    /**
     * Returns the directory name from a file path.
     *
     * @param string $path The file path.
     * @return string The directory name.
     */
    public static function dirName(string $path): string
    {
        return pathinfo($path, PATHINFO_DIRNAME);
    }

    /**
     * Returns the file extension from a file path.
     *
     * @param string $path The file path.
     * @return string The file extension.
     */
    public static function extension(string $path): string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    /**
     * Returns the file name from a file path.
     *
     * @param string $path The file path.
     * @return string The file name.
     */
    public static function fileName(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * Formats a path info array as a file path.
     *
     * @param array<string, mixed> $pathInfo The path info.
     * @return string The file path.
     */
    public static function format(array $pathInfo): string
    {
        return static::join($pathInfo['dirname'] ?? '', $pathInfo['basename'] ?? '');
    }

    /**
     * Checks whether a file path is absolute.
     *
     * @param string $path The file path.
     * @return bool Whether the file path is absolute.
     */
    public static function isAbsolute(string $path): bool
    {
        return $path !== '' && (
            $path[0] === DIRECTORY_SEPARATOR ||
            preg_match('/\A[A-Za-z]:[\\\\\\/]/', $path)
        );
    }

    /**
     * Joins path segments into a normalized path.
     *
     * @param string ...$paths The path segments.
     * @return string The file path.
     */
    public static function join(string ...$paths): string
    {
        return array_filter($paths, static fn(string $segment): bool => $segment !== '')
            |> implode(DIRECTORY_SEPARATOR, ?)
            |> static::normalize(...);
    }

    /**
     * Normalizes a file path.
     *
     * Collapses "." and ".." segments and duplicate separators. If the resulting
     * path is empty, "." is returned. If the original path started with a directory
     * separator and normalization removes all segments, a single directory
     * separator is returned.
     *
     * @param string $path The file path.
     * @return string The normalized path.
     */
    public static function normalize(string $path = ''): string
    {
        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR);
        $hasTrailingSeparator = str_ends_with($path, DIRECTORY_SEPARATOR);

        $newPath = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($newPath !== [] && $newPath[array_key_last($newPath)] !== '..') {
                    array_pop($newPath);
                } elseif (!$isAbsolute) {
                    $newPath[] = $segment;
                }

                continue;
            }

            $newPath[] = $segment;
        }

        $result = implode(DIRECTORY_SEPARATOR, $newPath);

        if ($isAbsolute) {
            $result = DIRECTORY_SEPARATOR.$result;
        }

        if ($result !== '' && $result !== DIRECTORY_SEPARATOR && $hasTrailingSeparator) {
            $result .= DIRECTORY_SEPARATOR;
        }

        return $result === '' ? '.' : $result;
    }

    /**
     * Parses a file path.
     *
     * @param string $path The file path.
     * @return array<string, mixed> The path info.
     */
    public static function parse(string $path): array
    {
        return pathinfo($path);
    }

    /**
     * Resolves a file path from path segments.
     *
     * If no segments are provided, the current working directory is returned (or
     * "." if it can't be determined).
     *
     * @param string ...$paths The path segments.
     * @return string The file path.
     */
    public static function resolve(string ...$paths): string
    {
        if ($paths === []) {
            return getcwd() ?: '.';
        }

        $paths = array_reverse($paths);
        $pathSegments = [];
        foreach ($paths as $path) {
            if (!$path) {
                continue;
            }

            array_unshift($pathSegments, $path);

            if (!static::isAbsolute($path)) {
                continue;
            }

            return static::join(...$pathSegments);
        }

        array_unshift($pathSegments, '.');

        return static::join(...$pathSegments);
    }
}
