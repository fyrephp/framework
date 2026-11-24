<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\Math;

use Fyre\Utility\Math;

trait AtanhTestTrait
{
    public function testAtanh(): void
    {
        $this->assertEqualsWithDelta(
            .5493061443340548,
            Math::atanh(.5),
            1e-15
        );
    }
}
