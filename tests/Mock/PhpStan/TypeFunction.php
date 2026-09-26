<?php
declare(strict_types=1);

namespace Tests\Mock\PhpStan;

use Fyre\DB\Type;
use Fyre\DB\TypeParser;
use Fyre\DB\Types\BooleanType;
use Fyre\DB\Types\DecimalType;
use Fyre\DB\Types\FloatType;
use Fyre\DB\Types\IntegerType;
use Fyre\DB\Types\StringType;

use function PHPStan\Testing\assertType;
use function type;
use function type as loadType;

function typeFunction(): void
{
    assertType(TypeParser::class, type());
    assertType(TypeParser::class, type(null));
    assertType(DecimalType::class, type('decimal'));
    assertType(DecimalType::class, loadType('decimal'));
    assertType(DecimalType::class, type(type: 'decimal'));
    assertType('numeric-string|null', type('decimal')->parse('12.50'));
    assertType(IntegerType::class, type('integer'));
    assertType(BooleanType::class, type('bool'));
    assertType(DecimalType::class, type()->use('decimal'));
}

function typeFunctionFallback(string $name, string|null $nullable): void
{
    assertType(StringType::class, type('missing'));
    assertType(Type::class, type($name));
    assertType('Fyre\DB\Type|Fyre\DB\TypeParser', type($nullable));
}

function typeFunctionOverrides(): void
{
    assertType(DecimalType::class, type('money'));
    assertType(FloatType::class, type('double'));
    assertType(DecimalType::class, type('int'));
}

/**
 * @param 'decimal'|'integer' $name
 * @param 'decimal'|null $nullable
 */
function typeFunctionUnion(string $name, string|null $nullable): void
{
    assertType('Fyre\DB\Types\DecimalType|Fyre\DB\Types\IntegerType', type($name));
    assertType('Fyre\DB\TypeParser|Fyre\DB\Types\DecimalType', type($nullable));
}
