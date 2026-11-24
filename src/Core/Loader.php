<?php
declare(strict_types=1);

namespace Fyre\Core;

use Closure;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Utility\Path;

use function array_pop;
use function array_unique;
use function array_unshift;
use function explode;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function ltrim;
use function rtrim;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * Loads classes using class maps and namespace prefixes.
 */
class Loader
{
    use DebugTrait;
    use MacroTrait;

    /**
     * @var array<string, string>
     */
    protected array $classMap = [];

    /**
     * @var (Closure(string): void)|null
     */
    protected Closure|null $loader = null;

    /**
     * @var array<string, string[]>
     */
    protected array $namespaces = [];

    /**
     * Normalizes a class name.
     *
     * @param string $className The class name.
     * @return string The normalized class name.
     */
    public static function normalizeClass(string $className): string
    {
        return ltrim($className, '\\');
    }

    /**
     * Normalizes a namespace.
     *
     * @param string $namespace The namespace.
     * @return string The normalized namespace.
     */
    public static function normalizeNamespace(string $namespace): string
    {
        return trim($namespace, '\\').'\\';
    }

    /**
     * Adds a class map.
     *
     * @param array<string, string> $classMap The class map.
     * @return static The Loader instance.
     */
    public function addClassMap(array $classMap): static
    {
        foreach ($classMap as $className => $path) {
            $className = static::normalizeClass($className);
            $path = Path::resolve($path);

            $this->classMap[$className] = $path;
        }

        return $this;
    }

    /**
     * Adds namespaces.
     *
     * @param array<string, string|string[]> $namespaces The namespaces.
     * @return static The Loader instance.
     */
    public function addNamespaces(array $namespaces): static
    {
        foreach ($namespaces as $prefix => $paths) {
            $prefix = static::normalizeNamespace($prefix);

            $this->namespaces[$prefix] ??= [];

            $paths = (array) $paths;

            foreach ($paths as $path) {
                $path = Path::resolve($path);

                if ($path !== DIRECTORY_SEPARATOR) {
                    $path = rtrim($path, DIRECTORY_SEPARATOR);
                }

                if (in_array($path, $this->namespaces[$prefix], true)) {
                    continue;
                }

                $this->namespaces[$prefix][] = $path;
            }
        }

        return $this;
    }

    /**
     * Clears all namespaces and class mappings.
     */
    public function clear(): void
    {
        $this->namespaces = [];
        $this->classMap = [];
    }

    /**
     * Finds all namespace folders.
     *
     * @param string $namespace The namespace.
     * @return string[] The folders.
     */
    public function findFolders(string $namespace): array
    {
        $parts = explode('\\', $namespace);
        $pathParts = [];

        $folders = [];
        while ($parts !== []) {
            $currentNamespace = implode('\\', $parts);
            $paths = $this->getNamespacePaths($currentNamespace);

            foreach ($paths as $path) {
                $path = Path::join($path, ...$pathParts);

                if (!is_dir($path)) {
                    continue;
                }

                $folders[] = $path;
            }

            $lastPart = array_pop($parts);
            array_unshift($pathParts, $lastPart);
        }

        return array_unique($folders);
    }

    /**
     * Returns the class map.
     *
     * @return array<string, string> The class map.
     */
    public function getClassMap(): array
    {
        return $this->classMap;
    }

    /**
     * Returns a namespace.
     *
     * @param string $prefix The namespace prefix.
     * @return string[] The namespace paths.
     */
    public function getNamespace(string $prefix): array
    {
        $prefix = static::normalizeNamespace($prefix);

        return $this->namespaces[$prefix] ?? [];
    }

    /**
     * Returns all paths for a namespace.
     *
     * This includes any explicit namespace registrations plus any inferred base paths for
     * matching classes found in the class map (when the class map path matches a PSR-4 style
     * suffix for the namespace prefix).
     *
     * @param string $prefix The namespace prefix.
     * @return string[] The namespace paths.
     */
    public function getNamespacePaths(string $prefix): array
    {
        $prefix = static::normalizeNamespace($prefix);
        $prefixLength = strlen($prefix);

        $paths = $this->namespaces[$prefix] ?? [];

        foreach ($this->classMap as $className => $filePath) {
            if (!str_starts_with($className, $prefix)) {
                continue;
            }

            $testPath = substr($className, $prefixLength - 1) |> str_replace('\\', DIRECTORY_SEPARATOR, ?);
            $testPath .= '.php';

            if (!str_ends_with($filePath, $testPath)) {
                continue;
            }

            $testPathLength = strlen($testPath);
            $path = substr($filePath, 0, -$testPathLength) ?: DIRECTORY_SEPARATOR;

            if (in_array($path, $paths, true)) {
                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Returns the namespaces.
     *
     * @return array<string, string[]> The namespaces.
     */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }

    /**
     * Checks whether a namespace exists.
     *
     * @param string $prefix The namespace prefix.
     * @return bool Whether the namespace exists.
     */
    public function hasNamespace(string $prefix): bool
    {
        $prefix = static::normalizeNamespace($prefix);

        return isset($this->namespaces[$prefix]);
    }

    /**
     * Loads Composer autoload data.
     *
     * If the file does not exist, this method is a no-op.
     *
     * The file is expected to return a Composer autoloader instance (e.g. the value returned by
     * requiring `vendor/autoload.php`), supporting `getClassMap()` and `getPrefixesPsr4()`.
     *
     * @param string $composerPath The Composer autoload.php path.
     * @return static The Loader instance.
     */
    public function loadComposer(string $composerPath): static
    {
        if (!is_file($composerPath)) {
            return $this;
        }

        $composer = require $composerPath;

        $composer->getClassMap() |> $this->addClassMap(...);
        $composer->getPrefixesPsr4() |> $this->addNamespaces(...);

        return $this;
    }

    /**
     * Registers the autoloader.
     *
     * This method is idempotent. The autoloader is prepended so it runs before other loaders.
     *
     * @return static The Loader instance.
     */
    public function register(): static
    {
        if (!$this->loader) {
            $this->loader = function(string $className): void {
                /** @var class-string $className */
                $this->loadClass($className);
            };

            spl_autoload_register($this->loader, true, true);
        }

        return $this;
    }

    /**
     * Removes a class name.
     *
     * @param string $className The class name.
     * @return static The Loader instance.
     */
    public function removeClass(string $className): static
    {
        $className = static::normalizeClass($className);

        unset($this->classMap[$className]);

        return $this;
    }

    /**
     * Removes a namespace.
     *
     * @param string $prefix The namespace prefix.
     * @return static The Loader instance.
     */
    public function removeNamespace(string $prefix): static
    {
        $prefix = static::normalizeNamespace($prefix);

        unset($this->namespaces[$prefix]);

        return $this;
    }

    /**
     * Unregisters the autoloader.
     *
     * This method is idempotent.
     *
     * @return static The Loader instance.
     */
    public function unregister(): static
    {
        if ($this->loader) {
            spl_autoload_unregister($this->loader);

            $this->loader = null;
        }

        return $this;
    }

    /**
     * Attempts to load a class.
     *
     * @param string $className The class name.
     * @return bool Whether the class was loaded.
     */
    protected function loadClass(string $className): bool
    {
        if ($this->loadClassFromMap($className)) {
            return true;
        }

        $className = static::normalizeClass($className);

        foreach ($this->namespaces as $prefix => $paths) {
            if (!str_starts_with($className, $prefix)) {
                continue;
            }

            $length = strlen($prefix);
            $fileName = substr($className, $length) |> str_replace('\\', DIRECTORY_SEPARATOR, ?);
            $fileName .= '.php';

            foreach ($paths as $path) {
                $filePath = Path::join($path, $fileName);

                if (static::loadFile($filePath)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Attempts to load a class from the class map.
     *
     * @param string $className The class name.
     * @return bool Whether the class was loaded.
     */
    protected function loadClassFromMap(string $className): bool
    {
        $className = static::normalizeClass($className);

        if (!isset($this->classMap[$className])) {
            return false;
        }

        return static::loadFile($this->classMap[$className]);
    }

    /**
     * Attempts to load a file.
     *
     * @param string $filePath The file path.
     * @return bool Whether the file was loaded.
     */
    protected static function loadFile(string $filePath): bool
    {
        if (!is_file($filePath)) {
            return false;
        }

        include_once $filePath;

        return true;
    }
}
