<?php
declare(strict_types=1);

namespace Fyre\TestSuite\PhpStan\Extensions;

use Fyre\ORM\Model;
use Override;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Resolves model() calls to concrete model classes.
 */
class ModelFunctionReturnTypeExtension extends ModelReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{
    /**
     * Gets the return type for a function call.
     *
     * @param FunctionReflection $functionReflection The function reflection.
     * @param FuncCall $functionCall The function call.
     * @param Scope $scope The scope.
     * @return Type The return type.
     */
    #[Override]
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope
    ): Type {
        $args = $functionCall->getArgs();

        if ($args === []) {
            return new ObjectType(Model::class);
        }

        $classAliases = $scope->getType($args[0]->value)->getConstantStrings();

        foreach ($classAliases as $classAlias) {
            if ($modelClass = $this->resolveModelClass($classAlias->getValue(), $scope)) {
                return new ObjectType($modelClass);
            }
        }

        return new ObjectType(Model::class);
    }

    /**
     * Checks whether the function is supported.
     *
     * @param FunctionReflection $functionReflection The function reflection.
     * @return bool Whether the function is supported.
     */
    #[Override]
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'model';
    }
}
