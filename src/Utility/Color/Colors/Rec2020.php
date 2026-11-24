<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Fyre\Utility\Color\Traits\RgbTrait;
use Override;

/**
 * Represents a Rec. 2020 color.
 */
class Rec2020 extends Color
{
    use RgbTrait;

    #[Override]
    protected const COLOR_SPACE = 'rec2020';

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toRec2020(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toXyzD65(): XyzD65
    {
        [$x, $y, $z] = ColorConverter::rec2020ToXyzD65($this->red, $this->green, $this->blue);

        return new XyzD65($x, $y, $z, $this->alpha);
    }
}
