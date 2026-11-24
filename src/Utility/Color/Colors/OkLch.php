<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Fyre\Utility\Color\Traits\LchTrait;
use Override;

use function round;

/**
 * Represents an OK LCH color.
 */
class OkLch extends Color
{
    use LchTrait;

    #[Override]
    protected const COLOR_SPACE = 'oklch';

    /**
     * Constructs an OkLch.
     *
     * @param float $lightness The lightness value.
     * @param float $chroma The chroma value.
     * @param float $hue The hue value. (0, 360)
     * @param float $alpha The alpha value. (0, 1)
     */
    public function __construct(
        float $lightness = 0,
        float $chroma = 0,
        float $hue = 0,
        float $alpha = 1,
    ) {
        parent::__construct($alpha);

        static::ensureFinite($lightness);
        static::ensureFinite($chroma);

        $this->lightness = $lightness;
        $this->chroma = $chroma;
        $this->hue = static::clampHue($hue);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toOkLab(): OkLab
    {
        [$L, $a, $b] = ColorConverter::okLchToOkLab($this->lightness, $this->chroma, $this->hue);

        return new OkLab($L, $a, $b, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toOkLch(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toString(bool|null $alpha = null, int $precision = 2): string
    {
        $alpha ??= $this->alpha < 1;

        $result = 'oklch('.
            round($this->lightness, $precision).' '.
            round($this->chroma, $precision).' '.
            round($this->hue, $precision).'deg';

        if ($alpha) {
            $result .= ' / '.round($this->alpha, $precision);
        }

        $result .= ')';

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toXyzD65(): XyzD65
    {
        return $this->toOkLab()->toXyzD65();
    }
}
