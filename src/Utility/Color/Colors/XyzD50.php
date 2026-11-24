<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Fyre\Utility\Color\Traits\XyzTrait;
use Override;

/**
 * Represents an XYZ D50 color.
 */
class XyzD50 extends Color
{
    use XyzTrait;

    #[Override]
    protected const COLOR_SPACE = 'xyz-d50';

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toLab(): Lab
    {
        [$L, $a, $b] = ColorConverter::xyzD50ToLab($this->x, $this->y, $this->z);

        return new Lab($L, $a, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toProPhotoRgb(): ProPhotoRgb
    {
        [$r, $g, $b] = ColorConverter::xyzD50ToProPhotoRgb($this->x, $this->y, $this->z);

        return new ProPhotoRgb($r, $g, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toXyzD50(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toXyzD65(): XyzD65
    {
        [$x, $y, $z] = ColorConverter::xyzD50ToXyzD65($this->x, $this->y, $this->z);

        return new XyzD65($x, $y, $z, $this->alpha);
    }
}
