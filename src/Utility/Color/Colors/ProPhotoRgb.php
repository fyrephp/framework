<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Fyre\Utility\Color\Traits\RgbTrait;
use Override;

/**
 * Represents a ProPhoto RGB color.
 */
class ProPhotoRgb extends Color
{
    use RgbTrait;

    #[Override]
    protected const COLOR_SPACE = 'prophoto-rgb';

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toProPhotoRgb(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toXyzD50(): XyzD50
    {
        [$x, $y, $z] = ColorConverter::prophotoRgbToXyzD50($this->red, $this->green, $this->blue);

        return new XyzD50($x, $y, $z, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toXyzD65(): XyzD65
    {
        return $this->toXyzD50()->toXyzD65();
    }
}
