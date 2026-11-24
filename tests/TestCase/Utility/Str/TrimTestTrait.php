<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\Str;

use Fyre\Utility\Str;
use PHPUnit\Framework\Attributes\DataProvider;

trait TrimTestTrait
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function trimProvider(): array
    {
        return [
            'whitespace' => ["\r\n This is a test string \r\n", 'This is a test string'],
            'form feed' => ["\ftest\f", 'test'],
            'string' => ['This is a test string', 'This is a test string'],
        ];
    }

    #[DataProvider('trimProvider')]
    public function testTrim(string $string, string $expected): void
    {
        $this->assertSame(
            $expected,
            Str::trim($string)
        );
    }

    public function testTrimWithMask(): void
    {
        $this->assertSame(
            '123456',
            Str::trim('000123456000', '0')
        );
    }
}
