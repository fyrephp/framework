<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Fyre\Utility\Color\Traits\RgbTrait;
use Override;

/**
 * Represents an sRGB color.
 */
class Srgb extends Color
{
    use RgbTrait;

    #[Override]
    protected const COLOR_SPACE = 'srgb';

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function luma(): float
    {
        return ColorConverter::srgbToLuma($this->red, $this->green, $this->blue);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toHsl(): Hsl
    {
        [$h, $s, $l] = ColorConverter::srgbToHsl($this->red, $this->green, $this->blue);

        return new Hsl($h, $s * 100, $l * 100, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toHwb(): Hwb
    {
        [$h, $w, $b] = ColorConverter::srgbToHwb($this->red, $this->green, $this->blue);

        return new Hwb($h, $w * 100, $b * 100, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toRgb(): Rgb
    {
        [$r, $g, $b] = ColorConverter::srgbToRgb($this->red, $this->green, $this->blue);

        return new Rgb($r, $g, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toSrgb(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toSrgbLinear(): SrgbLinear
    {
        [$r, $g, $b] = ColorConverter::srgbToSrgbLinear($this->red, $this->green, $this->blue);

        return new SrgbLinear($r, $g, $b, $this->alpha);
    }
}
