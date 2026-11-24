<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\Math;

use Fyre\Utility\Math;
use PHPUnit\Framework\Attributes\DataProvider;

trait ClampPercentTestTrait
{
    /**
     * @return array<string, array{float|int, float|int}>
     */
    public static function clampPercentProvider(): array
    {
        return [
            'within bounds' => [50, 50],
            'float at lower bound' => [0.0, 0.0],
            'above bounds' => [150, 100],
            'below bounds' => [-50, 0],
        ];
    }

    #[DataProvider('clampPercentProvider')]
    public function testClampPercent(float|int $number, float|int $expected): void
    {
        $this->assertSame(
            $expected,
            Math::clampPercent($number)
        );
    }
}
