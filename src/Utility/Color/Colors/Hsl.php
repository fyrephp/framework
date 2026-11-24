<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Override;

use function max;
use function round;

/**
 * Represents an HSL color.
 */
class Hsl extends Color
{
    #[Override]
    protected const COLOR_SPACE = 'hsl';

    public readonly float $hue;

    public readonly float $lightness;

    public readonly float $saturation;

    /**
     * Constructs an Hsl.
     *
     * @param float $hue The hue value. (0, 360)
     * @param float $saturation The saturation value.
     * @param float $lightness The lightness value.
     * @param float $alpha The alpha value. (0, 1)
     */
    public function __construct(
        float $hue = 0,
        float $saturation = 0,
        float $lightness = 0,
        float $alpha = 1,
    ) {
        parent::__construct($alpha);

        $this->hue = static::clampHue($hue);
        static::ensureFinite($saturation);
        static::ensureFinite($lightness);

        $this->saturation = $saturation;
        $this->lightness = $lightness;
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
     * Returns the lightness value.
     *
     * @return float The lightness value.
     */
    public function getLightness(): float
    {
        return $this->lightness;
    }

    /**
     * Returns the saturation value.
     *
     * @return float The saturation value.
     */
    public function getSaturation(): float
    {
        return $this->saturation;
    }

    /**
     * {@inheritDoc}
     *
     * @return array{hue: float, saturation: float, lightness: float, alpha: float} The color components.
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'hue' => $this->hue,
            'saturation' => $this->saturation,
            'lightness' => $this->lightness,
            'alpha' => $this->alpha,
        ];
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toHsl(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toSrgb(): Srgb
    {
        [$r, $g, $b] = ColorConverter::hslToSrgb($this->hue, $this->saturation / 100, $this->lightness / 100);

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

        $result = 'hsl('.
            round($this->hue, $precision).'deg '.
            round($this->saturation, $precision).'% '.
            round($this->lightness, $precision).'%';

        if ($alpha) {
            $result .= ' / '.round($this->alpha * 100, max(0, $precision - 2)).'%';
        }

        $result .= ')';

        return $result;
    }

    /**
     * Clones the Color with a new hue value.
     *
     * @param float $hue The hue value.
     * @return static The new Color instance with the updated hue value.
     */
    public function withHue(float $hue): static
    {
        return new static($hue, $this->saturation, $this->lightness, $this->alpha);
    }

    /**
     * Clones the Color with a new lightness value.
     *
     * @param float $lightness The lightness value.
     * @return static The new Color instance with the updated lightness value.
     */
    public function withLightness(float $lightness): static
    {
        return new static($this->hue, $this->saturation, $lightness, $this->alpha);
    }

    /**
     * Clones the Color with a new saturation value.
     *
     * @param float $saturation The saturation value.
     * @return static The new Color instance with the updated saturation value.
     */
    public function withSaturation(float $saturation): static
    {
        return new static($this->hue, $saturation, $this->lightness, $this->alpha);
    }
}
