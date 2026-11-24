<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Fyre\Utility\Color\Traits\RgbTrait;
use Override;

/**
 * Represents an sRGB Linear color.
 */
class SrgbLinear extends Color
{
    use RgbTrait;

    #[Override]
    protected const COLOR_SPACE = 'srgb-linear';

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toSrgb(): Srgb
    {
        [$r, $g, $b] = ColorConverter::srgbLinearToSrgb($this->red, $this->green, $this->blue);

        return new Srgb($r, $g, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toSrgbLinear(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toXyzD65(): XyzD65
    {
        [$x, $y, $z] = ColorConverter::srgbLinearToXyzD65($this->red, $this->green, $this->blue);

        return new XyzD65($x, $y, $z, $this->alpha);
    }
}
