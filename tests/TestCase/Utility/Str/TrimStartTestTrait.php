<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\Str;

use Fyre\Utility\Str;
use PHPUnit\Framework\Attributes\DataProvider;

trait TrimStartTestTrait
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function trimStartProvider(): array
    {
        return [
            'whitespace' => ["\r\n This is a test string \r\n", "This is a test string \r\n"],
            'form feed' => ["\ftest\f", "test\f"],
            'string' => ['This is a test string', 'This is a test string'],
        ];
    }

    #[DataProvider('trimStartProvider')]
    public function testTrimStart(string $string, string $expected): void
    {
        $this->assertSame(
            $expected,
            Str::trimStart($string)
        );
    }

    public function testTrimStartWithMask(): void
    {
        $this->assertSame(
            '123456000',
            Str::trimStart('000123456000', '0')
        );
    }
}
