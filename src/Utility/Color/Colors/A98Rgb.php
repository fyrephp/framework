<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Fyre\Utility\Color\Traits\RgbTrait;
use Override;

/**
 * Represents an A98 RGB color.
 */
class A98Rgb extends Color
{
    use RgbTrait;

    #[Override]
    protected const COLOR_SPACE = 'a98-rgb';

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toA98Rgb(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toXyzD65(): XyzD65
    {
        [$x, $y, $z] = ColorConverter::a98RgbToXyzD65($this->red, $this->green, $this->blue);

        return new XyzD65($x, $y, $z, $this->alpha);
    }
}
