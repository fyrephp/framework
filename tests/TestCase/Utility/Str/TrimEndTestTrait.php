<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\Str;

use Fyre\Utility\Str;
use PHPUnit\Framework\Attributes\DataProvider;

trait TrimEndTestTrait
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function trimEndProvider(): array
    {
        return [
            'whitespace' => ["\r\n This is a test string \r\n", "\r\n This is a test string"],
            'form feed' => ["\ftest\f", "\ftest"],
            'string' => ['This is a test string', 'This is a test string'],
        ];
    }

    #[DataProvider('trimEndProvider')]
    public function testTrimEnd(string $string, string $expected): void
    {
        $this->assertSame(
            $expected,
            Str::trimEnd($string)
        );
    }

    public function testTrimEndWithMask(): void
    {
        $this->assertSame(
            '000123456',
            Str::trimEnd('000123456000', '0')
        );
    }
}
