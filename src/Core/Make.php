<?php
declare(strict_types=1);

namespace Fyre\Core;

use Fyre\Core\Traits\DebugTrait;
use Fyre\Utility\Path;

use function array_keys;
use function array_pop;
use function array_unshift;
use function array_values;
use function explode;
use function file_get_contents;
use function implode;
use function preg_replace;
use function str_replace;

use const PHP_EOL;

/**
 * Generates files from stubs and resolves namespace paths.
 */
class Make
{
    use DebugTrait;

    /**
     * Loads a stub file with replacements.
     *
     * @param string $stub The stub file.
     * @param array<string, string> $replacements The replacements.
     * @return string The loaded stub file.
     */
    public static function loadStub(string $stub, array $replacements = []): string
    {
        $stubPath = Path::join(__DIR__, '../../stubs/'.$stub.'.stub');

        $contents = (string) file_get_contents($stubPath);
        $contents = (string) preg_replace('/\R/u', PHP_EOL, $contents);

        return str_replace(array_keys($replacements), array_values($replacements), $contents);
    }

    /**
     * Normalizes a dot-notated path string.
     *
     * This replaces `.` with `/` (e.g. `foo.bar` becomes `foo/bar`).
     *
     * @param string $string The string.
     * @return string The normalized string.
     */
    public static function normalizePath(string $string): string
    {
        return str_replace('.', '/', $string);
    }

    /**
     * Parses a namespace and class name.
     *
     * @param string $namespace The namespace.
     * @param string $className The class name.
     * @return string[] The parsed namespace and class name.
     */
    public static function parseNamespaceClass(string $namespace, string $className): array
    {
        $namespace = static::normalizeSeparators($namespace) |> Loader::normalizeNamespace(...);

        $className = static::normalizeSeparators($className) |> Loader::normalizeClass(...);

        $namespacedClass = $namespace.$className;

        $namespaceSegments = explode('\\', $namespacedClass);

        $className = array_pop($namespaceSegments);

        $namespace = implode('\\', $namespaceSegments);

        return [$namespace, $className];
    }

    /**
     * Constructs a Make.
     *
     * @param Loader $loader The Loader.
     */
    public function __construct(
        protected Loader $loader
    ) {}

    /**
     * Finds the full path to a namespace.
     *
     * @param string $namespace The namespace.
     * @return string|null The full path or null if no matching namespace is found.
     */
    public function findPath(string $namespace): string|null
    {
        $namespaceSegments = explode('\\', $namespace);
        $pathSegments = [];

        while ($namespaceSegments !== []) {
            $tempNamespace = implode('\\', $namespaceSegments);
            $paths = $this->loader->getNamespace($tempNamespace);

            if ($paths === []) {
                $segment = array_pop($namespaceSegments);
                array_unshift($pathSegments, $segment);

                continue;
            }

            return Path::join($paths[0], ...$pathSegments);
        }

        return null;
    }

    /**
     * Normalizes namespace path separators.
     *
     * @param string $string The string.
     * @return string The normalized string.
     */
    protected static function normalizeSeparators(string $string): string
    {
        return str_replace(['/', '.'], '\\', $string);
    }
}
