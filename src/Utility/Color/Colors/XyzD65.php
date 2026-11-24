<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Fyre\Utility\Color\Traits\XyzTrait;
use Override;

/**
 * Represents an XYZ D65 color.
 */
class XyzD65 extends Color
{
    use XyzTrait;

    #[Override]
    protected const COLOR_SPACE = 'xyz-d65';

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toA98Rgb(): A98Rgb
    {
        [$r, $g, $b] = ColorConverter::xyzD65ToA98Rgb($this->x, $this->y, $this->z);

        return new A98Rgb($r, $g, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toDisplayP3Linear(): DisplayP3Linear
    {
        [$r, $g, $b] = ColorConverter::xyzD65ToDisplayP3Linear($this->x, $this->y, $this->z);

        return new DisplayP3Linear($r, $g, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toOkLab(): OkLab
    {
        [$L, $a, $b] = ColorConverter::xyzD65ToOkLab($this->x, $this->y, $this->z);

        return new OkLab($L, $a, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toRec2020(): Rec2020
    {
        [$r, $g, $b] = ColorConverter::xyzD65ToRec2020($this->x, $this->y, $this->z);

        return new Rec2020($r, $g, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toSrgbLinear(): SrgbLinear
    {
        [$r, $g, $b] = ColorConverter::xyzD65ToSrgbLinear($this->x, $this->y, $this->z);

        return new SrgbLinear($r, $g, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toXyzD50(): XyzD50
    {
        [$x, $y, $z] = ColorConverter::xyzD65ToXyzD50($this->x, $this->y, $this->z);

        return new XyzD50($x, $y, $z, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toXyzD65(): static
    {
        return $this;
    }
}
