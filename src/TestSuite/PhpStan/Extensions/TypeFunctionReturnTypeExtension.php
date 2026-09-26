<?php
declare(strict_types=1);

namespace Fyre\TestSuite\PhpStan\Extensions;

use Fyre\DB\TypeParser;
use Override;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Resolves type() calls to concrete database type classes.
 */
class TypeFunctionReturnTypeExtension extends TypeReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{
    /**
     * Gets the return type for a function call.
     *
     * @param FunctionReflection $functionReflection The function reflection.
     * @param FuncCall $functionCall The function call.
     * @param Scope $scope The scope.
     * @return Type|null The return type, or null to use the declared return type.
     */
    #[Override]
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope
    ): Type|null {
        $args = $functionCall->getArgs();

        if ($args === []) {
            return null;
        }

        $argumentType = $scope->getType($args[0]->value);

        if ($argumentType->isNull()->yes()) {
            return new ObjectType(TypeParser::class);
        }

        $type = $this->resolveType(TypeCombinator::removeNull($argumentType));

        if ($type !== null && !$argumentType->isNull()->no()) {
            return TypeCombinator::union($type, new ObjectType(TypeParser::class));
        }

        return $type;
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
        return $functionReflection->getName() === 'type';
    }
}
