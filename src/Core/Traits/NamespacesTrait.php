<?php
declare(strict_types=1);

namespace Fyre\Core\Traits;

use Fyre\Core\Loader;

use function array_splice;
use function in_array;

/**
 * Manages a list of namespaces for lookups.
 */
trait NamespacesTrait
{
    /**
     * @var string[]
     */
    protected array $namespaces = [];

    /**
     * Adds a namespace.
     *
     * Namespaces are normalized via {@see Loader::normalizeNamespace()} (trim `\` and ensure a
     * trailing `\`).
     *
     * @param string $namespace The namespace to add.
     * @return static The updated instance.
     */
    public function addNamespace(string $namespace): static
    {
        $namespace = Loader::normalizeNamespace($namespace);

        if (!in_array($namespace, $this->namespaces, true)) {
            $this->namespaces[] = $namespace;
        }

        return $this;
    }

    /**
     * Clears all namespaces.
     */
    public function clearNamespaces(): void
    {
        $this->namespaces = [];
    }

    /**
     * Returns all namespaces.
     *
     * @return string[] The list of namespaces.
     */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }

    /**
     * Checks whether a namespace exists.
     *
     * The namespace is normalized via {@see Loader::normalizeNamespace()} before comparison.
     *
     * @param string $namespace The namespace to check.
     * @return bool Whether the namespace exists.
     */
    public function hasNamespace(string $namespace): bool
    {
        return Loader::normalizeNamespace($namespace) |> in_array(?, $this->namespaces, true);
    }

    /**
     * Removes a namespace.
     *
     * The namespace is normalized via {@see Loader::normalizeNamespace()} before comparison.
     *
     * @param string $namespace The namespace to remove.
     * @return static The updated instance.
     */
    public function removeNamespace(string $namespace): static
    {
        $namespace = Loader::normalizeNamespace($namespace);

        foreach ($this->namespaces as $i => $otherNamespace) {
            if ($otherNamespace !== $namespace) {
                continue;
            }

            array_splice($this->namespaces, $i, 1);
            break;
        }

        return $this;
    }
}
