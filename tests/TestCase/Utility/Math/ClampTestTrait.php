<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\Math;

use Fyre\Utility\Math;
use PHPUnit\Framework\Attributes\DataProvider;
use ValueError;

use const NAN;

trait ClampTestTrait
{
    /**
     * @return array<string, array{float|int, float|int}>
     */
    public static function clampInvalidBoundsProvider(): array
    {
        return [
            'reversed' => [10, 0],
            'NaN minimum' => [NAN, 1],
            'NaN maximum' => [0, NAN],
        ];
    }

    public function testClamp(): void
    {
        $this->assertSame(
            .5,
            Math::clamp(.5)
        );
    }

    public function testClampAbove(): void
    {
        $this->assertSame(
            2,
            Math::clamp(3, 1, 2)
        );
    }

    public function testClampBelow(): void
    {
        $this->assertSame(
            1,
            Math::clamp(0, 1, 2)
        );
    }

    #[DataProvider('clampInvalidBoundsProvider')]
    public function testClampInvalidBounds(float|int $min, float|int $max): void
    {
        $this->expectException(ValueError::class);

        Math::clamp(1, $min, $max);
    }
}
