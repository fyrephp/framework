<?php
declare(strict_types=1);

namespace Fyre\TestSuite\PhpStan\Extensions;

use Fyre\Core\Container;
use Fyre\DB\Type as DatabaseType;
use Fyre\DB\TypeParser;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Resolves database type names using configured mappings.
 */
abstract class TypeReturnTypeExtension
{
    protected TypeParser $typeParser;

    /**
     * @param array<string, class-string<DatabaseType>> $typeMap The custom type mappings.
     */
    public function __construct(array $typeMap = [])
    {
        $this->typeParser = new TypeParser(new Container());

        foreach ($typeMap as $name => $className) {
            $this->typeParser->map($name, $className);
        }
    }

    /**
     * Resolves constant type names to concrete type classes.
     *
     * @param Type $type The argument type.
     * @return Type|null The resolved type, or null to use the declared return type.
     */
    protected function resolveType(Type $type): Type|null
    {
        if (!$type->isString()->yes() || !$type->isConstantScalarValue()->yes()) {
            return null;
        }

        $types = [];

        foreach ($type->getConstantStrings() as $name) {
            $types[] = new ObjectType($this->typeParser->getType($name->getValue()));
        }

        return TypeCombinator::union(...$types);
    }
}
