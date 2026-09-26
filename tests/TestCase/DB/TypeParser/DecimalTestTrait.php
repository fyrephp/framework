<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\TypeParser;

use PHPUnit\Framework\Attributes\DataProvider;

use const INF;
use const NAN;

trait DecimalTestTrait
{
    /**
     * @return array<string, array{string|null, float|int|string|null}>
     */
    public static function decimalParseProvider(): array
    {
        return [
            'default' => ['33.3', '33.3'],
            'float' => ['33.3', 33.3],
            'infinity' => [null, INF],
            'integer' => ['0', 0],
            'invalid' => [null, 'invalid'],
            'nan' => [null, NAN],
            'negativeInfinity' => [null, -INF],
            'null' => [null, null],
            'precision' => ['12345678901234567890.1234567890', '12345678901234567890.1234567890'],
            'scientific' => ['1e9999', '1e9999'],
        ];
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function decimalToDatabaseProvider(): array
    {
        return [
            'default' => ['33.3', '33.3'],
            'invalid' => [null, 'invalid'],
            'null' => [null, null],
        ];
    }

    public function testDecimalFromDatabase(): void
    {
        $this->assertSame(
            '33.3',
            $this->type->use('decimal')->fromDatabase('33.3')
        );
    }

    public function testDecimalFromDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('decimal')->fromDatabase(null)
        );
    }

    #[DataProvider('decimalParseProvider')]
    public function testDecimalParse(string|null $expected, float|int|string|null $value): void
    {
        $this->assertSame(
            $expected,
            $this->type->use('decimal')->parse($value)
        );
    }

    #[DataProvider('decimalToDatabaseProvider')]
    public function testDecimalToDatabase(string|null $expected, string|null $value): void
    {
        $this->assertSame(
            $expected,
            $this->type->use('decimal')->toDatabase($value)
        );
    }
}
