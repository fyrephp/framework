<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Fyre\Utility\Color\Traits\RgbTrait;
use Override;

/**
 * Represents a Display P3 Linear color.
 */
class DisplayP3Linear extends Color
{
    use RgbTrait;

    #[Override]
    protected const COLOR_SPACE = 'display-p3-linear';

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toDisplayP3(): DisplayP3
    {
        [$r, $g, $b] = ColorConverter::displayP3LinearToDisplayP3($this->red, $this->green, $this->blue);

        return new DisplayP3($r, $g, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toDisplayP3Linear(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toXyzD65(): XyzD65
    {
        [$x, $y, $z] = ColorConverter::displayP3LinearToXyzD65($this->red, $this->green, $this->blue);

        return new XyzD65($x, $y, $z, $this->alpha);
    }
}
