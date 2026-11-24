<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Fyre\Utility\Color\Traits\RgbTrait;
use Override;

/**
 * Represents a Display P3 color.
 */
class DisplayP3 extends Color
{
    use RgbTrait;

    #[Override]
    protected const COLOR_SPACE = 'display-p3';

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toDisplayP3(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toDisplayP3Linear(): DisplayP3Linear
    {
        [$r, $g, $b] = ColorConverter::displayP3ToDisplayP3Linear($this->red, $this->green, $this->blue);

        return new DisplayP3Linear($r, $g, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toXyzD65(): XyzD65
    {
        return $this->toDisplayP3Linear()->toXyzD65();
    }
}
