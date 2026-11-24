<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Override;

use function max;
use function round;

/**
 * Represents an HWB color.
 */
class Hwb extends Color
{
    #[Override]
    protected const COLOR_SPACE = 'hwb';

    public readonly float $blackness;

    public readonly float $hue;

    public readonly float $whiteness;

    /**
     * Constructs an Hwb.
     *
     * @param float $hue The hue value. (0, 360)
     * @param float $whiteness The whiteness value.
     * @param float $blackness The blackness value.
     * @param float $alpha The alpha value. (0, 1)
     */
    public function __construct(
        float $hue = 0,
        float $whiteness = 0,
        float $blackness = 0,
        float $alpha = 1,
    ) {
        parent::__construct($alpha);

        $this->hue = static::clampHue($hue);
        static::ensureFinite($whiteness);
        static::ensureFinite($blackness);

        $this->whiteness = $whiteness;
        $this->blackness = $blackness;
    }

    /**
     * Returns the blackness value.
     *
     * @return float The blackness value.
     */
    public function getBlackness(): float
    {
        return $this->blackness;
    }

    /**
     * Returns the hue value.
     *
     * @return float The hue value.
     */
    public function getHue(): float
    {
        return $this->hue;
    }

    /**
     * Returns the whiteness value.
     *
     * @return float The whiteness value.
     */
    public function getWhiteness(): float
    {
        return $this->whiteness;
    }

    /**
     * {@inheritDoc}
     *
     * @return array{hue: float, whiteness: float, blackness: float, alpha: float} The color components.
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'hue' => $this->hue,
            'whiteness' => $this->whiteness,
            'blackness' => $this->blackness,
            'alpha' => $this->alpha,
        ];
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toHwb(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toSrgb(): Srgb
    {
        [$r, $g, $b] = ColorConverter::hwbToSrgb($this->hue, $this->whiteness / 100, $this->blackness / 100);

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
     */
    #[Override]
    public function toString(bool|null $alpha = null, int $precision = 2): string
    {
        $alpha ??= $this->alpha < 1;

        $result = 'hwb('.
            round($this->hue, $precision).'deg '.
            round($this->whiteness, $precision).'% '.
            round($this->blackness, $precision).'%';

        if ($alpha) {
            $result .= ' / '.round($this->alpha * 100, max(0, $precision - 2)).'%';
        }

        $result .= ')';

        return $result;
    }

    /**
     * Clones the Color with a new blackness value.
     *
     * @param float $blackness The blackness value.
     * @return static The new Color instance with the updated blackness value.
     */
    public function withBlackness(float $blackness): static
    {
        return new static($this->hue, $this->whiteness, $blackness, $this->alpha);
    }

    /**
     * Clones the Color with a new hue value.
     *
     * @param float $hue The hue value.
     * @return static The new Color instance with the updated hue value.
     */
    public function withHue(float $hue): static
    {
        return new static($hue, $this->whiteness, $this->blackness, $this->alpha);
    }

    /**
     * Clones the Color with a new whiteness value.
     *
     * @param float $whiteness The whiteness value.
     * @return static The new Color instance with the updated whiteness value.
     */
    public function withWhiteness(float $whiteness): static
    {
        return new static($this->hue, $whiteness, $this->blackness, $this->alpha);
    }
}
