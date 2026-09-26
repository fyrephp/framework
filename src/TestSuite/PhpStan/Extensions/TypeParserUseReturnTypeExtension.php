<?php
declare(strict_types=1);

namespace Fyre\TestSuite\PhpStan\Extensions;

use Fyre\DB\TypeParser;
use Override;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

/**
 * Resolves TypeParser::use() calls to concrete database type classes.
 */
class TypeParserUseReturnTypeExtension extends TypeReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Gets the class supported by this extension.
     *
     * @return class-string<TypeParser> The supported class.
     */
    #[Override]
    public function getClass(): string
    {
        return TypeParser::class;
    }

    /**
     * Gets the return type for a method call.
     *
     * @param MethodReflection $methodReflection The method reflection.
     * @param MethodCall $methodCall The method call.
     * @param Scope $scope The scope.
     * @return Type|null The return type, or null to use the declared return type.
     */
    #[Override]
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): Type|null {
        $args = $methodCall->getArgs();

        if ($args === []) {
            return null;
        }

        return $scope->getType($args[0]->value) |> $this->resolveType(...);
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
