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

function typeParserUse(TypeParser $typeParser): void
{
    assertType(DecimalType::class, $typeParser->use('decimal'));
    assertType(DecimalType::class, $typeParser->use(type: 'decimal'));
    assertType('numeric-string|null', $typeParser->use('decimal')->parse('12.50'));
    assertType(IntegerType::class, $typeParser->use('integer'));
    assertType(BooleanType::class, $typeParser->use('bool'));
}

function typeParserUseFallback(TypeParser $typeParser, string $name): void
{
    assertType(StringType::class, $typeParser->use('missing'));
    assertType(Type::class, $typeParser->use($name));
}

function typeParserUseOverrides(TypeParser $typeParser): void
{
    assertType(DecimalType::class, $typeParser->use('money'));
    assertType(FloatType::class, $typeParser->use('double'));
    assertType(DecimalType::class, $typeParser->use('int'));
}

/**
 * @param 'decimal'|'integer' $name
 */
function typeParserUseUnion(TypeParser $typeParser, string $name): void
{
    assertType('Fyre\DB\Types\DecimalType|Fyre\DB\Types\IntegerType', $typeParser->use($name));
}
