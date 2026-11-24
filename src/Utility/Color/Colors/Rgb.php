<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Fyre\Utility\Color\Traits\RgbTrait;
use Override;

use function array_search;
use function max;
use function preg_match;
use function round;
use function sprintf;

/**
 * Represents an RGB color.
 */
class Rgb extends Color
{
    use RgbTrait;

    #[Override]
    protected const COLOR_SPACE = 'rgb';

    /**
     * Constructs an Rgb.
     *
     * @param float $red The red value.
     * @param float $green The green value.
     * @param float $blue The blue value.
     * @param float $alpha The alpha value. (0, 1)
     */
    public function __construct(
        float $red = 0,
        float $green = 0,
        float $blue = 0,
        float $alpha = 1,
    ) {
        parent::__construct($alpha);

        static::ensureFinite($red);
        static::ensureFinite($green);
        static::ensureFinite($blue);

        $this->red = $red;
        $this->green = $green;
        $this->blue = $blue;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toHex(): Hex
    {
        return new Hex($this->red, $this->green, $this->blue, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toRgb(): Rgb
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toSrgb(): Srgb
    {
        [$r, $g, $b] = ColorConverter::rgbToSrgb($this->red, $this->green, $this->blue);

        return new Srgb($r, $g, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toSrgbLinear(): SrgbLinear
    {
        return $this->toSrgb()->toSrgbLinear();
    }

    /**
     * {@inheritDoc}
     *
     * @param int $precision The decimal precision for red/green/blue; alpha is rounded with up to $precision - 2 decimals.
     * @param bool $name Whether to use CSS color names for fully opaque colors when possible.
     */
    #[Override]
    public function toString(bool|null $alpha = null, int $precision = 2, bool $name = false): string
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

        $result = 'rgb('.
            round($this->red, $precision).' '.
            round($this->green, $precision).' '.
            round($this->blue, $precision);

        if ($alpha) {
            $result .= ' / '.round($this->alpha * 100, max(0, $precision - 2)).'%';
        }

        $result .= ')';

        return $result;
    }

    /**
     * Returns the hexadecimal representation of the Color.
     *
     * @param bool $alpha Whether to include the alpha component.
     * @param bool $shortenHex Whether to shorten hexadecimal output.
     * @return string The hexadecimal Color string without prefix.
     */
    protected function getHex(bool $alpha = false, bool $shortenHex = true): string
    {
        $red = (int) static::clamp(round($this->red), 0, 255);
        $green = (int) static::clamp(round($this->green), 0, 255);
        $blue = (int) static::clamp(round($this->blue), 0, 255);
        $alphaValue = (int) static::clamp(round($this->alpha * 255), 0, 255);

        $result = $alpha ?
            sprintf(
                '%02x%02x%02x%02x',
                $red,
                $green,
                $blue,
                $alphaValue
            ) :
            sprintf(
                '%02x%02x%02x',
                $red,
                $green,
                $blue
            );

        if ($shortenHex && preg_match('/\A([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3([0-9a-f])?\4?\z/i', $result, $match)) {
            $result = $match[1].$match[2].$match[3].($match[4] ?? '');
        }

        return $result;
    }
}
