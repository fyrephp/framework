<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Override;

use function array_search;

/**
 * Represents a hexadecimal color.
 */
class Hex extends Rgb
{
    #[Override]
    protected const COLOR_SPACE = 'hex';

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toHex(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toRgb(): Rgb
    {
        return new Rgb($this->red, $this->green, $this->blue, $this->alpha);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $precision Unused precision parameter for compatibility.
     * @param bool $shortenHex Whether to shorten hexadecimal output.
     * @param bool $name Whether to use CSS color names for fully opaque colors when possible.
     */
    #[Override]
    public function toString(bool|null $alpha = null, int $precision = 2, bool $shortenHex = true, bool $name = false): string
    {
        $alpha ??= $this->alpha < 1;

        if ($name && $this->alpha <= 0) {
            return 'transparent';
        }

        if ($name && $this->alpha >= 1) {
            $hex = $this->getHex(shortenHex: false);
            $colorName = array_search('#'.$hex, static::CSS_COLORS, true);

            if ($colorName !== false) {
                return (string) $colorName;
            }
        }

        return '#'.$this->getHex($alpha, $shortenHex);
    }
}
