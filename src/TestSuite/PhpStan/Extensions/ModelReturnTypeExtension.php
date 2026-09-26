<?php
declare(strict_types=1);

namespace Fyre\TestSuite\PhpStan\Extensions;

use Fyre\ORM\Model;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;

use function array_values;
use function in_array;
use function trim;

/**
 * Resolves model aliases using configured namespaces.
 */
abstract class ModelReturnTypeExtension
{
    /**
     * @var string[]
     */
    protected array $modelNamespaces;

    /**
     * @var array<array{classes: string[], modelNamespaces: string[]}>
     */
    protected array $modelNamespacesOverrides;

    /**
     * @param ReflectionProvider $reflectionProvider The reflection provider.
     * @param string[] $modelNamespaces The model namespaces.
     * @param array<array{classes: string[], modelNamespaces: string[]}> $modelNamespacesOverrides The model namespace overrides.
     */
    public function __construct(
        protected ReflectionProvider $reflectionProvider,
        array $modelNamespaces = ['App\\Models'],
        array $modelNamespacesOverrides = []
    ) {
        $this->modelNamespaces = $modelNamespaces;
        $this->modelNamespacesOverrides = $modelNamespacesOverrides;
    }

    /**
     * Gets the configured model namespaces for a scope.
     *
     * @param Scope $scope The scope.
     * @return string[] The model namespaces.
     */
    protected function modelNamespaces(Scope $scope): array
    {
        $className = $scope->getClassReflection()?->getName();

        if ($className !== null) {
            foreach ($this->modelNamespacesOverrides as $override) {
                if (in_array($className, $override['classes'], true)) {
                    return array_values($override['modelNamespaces']);
                }
            }
        }

        return array_values($this->modelNamespaces);
    }

    /**
     * Resolves the model class for a class alias.
     *
     * @param string $classAlias The model class alias.
     * @param Scope $scope The scope.
     * @return class-string<Model>|null The model class.
     */
    protected function resolveModelClass(string $classAlias, Scope $scope): string|null
    {
        foreach ($this->modelNamespaces($scope) as $namespace) {
            $className = trim($namespace, '\\').'\\'.trim($classAlias, '\\').'Model';

            if (!$this->reflectionProvider->hasClass($className)) {
                continue;
            }

            $classReflection = $this->reflectionProvider->getClass($className);

            if (!$classReflection->isSubclassOf(Model::class)) {
                continue;
            }

            /** @var class-string<Model> $modelClass */
            $modelClass = $classReflection->getName();

            return $modelClass;
        }

        return null;
    }
}
