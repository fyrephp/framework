<?php
declare(strict_types=1);

namespace Fyre\TestSuite\PhpStan\Extensions;

use Fyre\ORM\Model;
use Fyre\ORM\ModelRegistry;
use Override;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Resolves ModelRegistry::use() calls to concrete model classes.
 */
class ModelRegistryUseReturnTypeExtension extends ModelReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Gets the class supported by this extension.
     *
     * @return class-string<ModelRegistry> The supported class.
     */
    #[Override]
    public function getClass(): string
    {
        return ModelRegistry::class;
    }

    /**
     * Gets the return type for a method call.
     *
     * @param MethodReflection $methodReflection The method reflection.
     * @param MethodCall $methodCall The method call.
     * @param Scope $scope The scope.
     * @return Type The return type.
     */
    #[Override]
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): Type {
        $args = $methodCall->getArgs();

        if ($args === []) {
            return new ObjectType(Model::class);
        }

        $aliasType = $scope->getType($args[0]->value);
        $classAliases = isset($args[1]) ?
            $scope->getType($args[1]->value)->getConstantStrings() :
            $aliasType->getConstantStrings();

        foreach ($classAliases as $classAlias) {
            if ($modelClass = $this->resolveModelClass($classAlias->getValue(), $scope)) {
                return new ObjectType($modelClass);
            }
        }

        return new ObjectType(Model::class);
    }

    /**
     * Checks whether the method is supported.
     *
     * @param MethodReflection $methodReflection The method reflection.
     * @return bool Whether the method is supported.
     */
    #[Override]
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'use';
    }
}
