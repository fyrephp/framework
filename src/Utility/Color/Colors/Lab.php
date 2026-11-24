<?php
declare(strict_types=1);

namespace Fyre\Utility\Color\Colors;

use Fyre\Utility\Color\Color;
use Fyre\Utility\Color\ColorConverter;
use Fyre\Utility\Color\Traits\LabTrait;
use Override;

use function round;

/**
 * Represents a LAB color.
 */
class Lab extends Color
{
    use LabTrait;

    #[Override]
    protected const COLOR_SPACE = 'lab';

    /**
     * Constructs a Lab.
     *
     * @param float $lightness The lightness value.
     * @param float $a The a value.
     * @param float $b The b value.
     * @param float $alpha The alpha value. (0, 1)
     */
    public function __construct(
        float $lightness = 0,
        float $a = 0,
        float $b = 0,
        float $alpha = 1,
    ) {
        parent::__construct($alpha);

        static::ensureFinite($lightness);
        static::ensureFinite($a);
        static::ensureFinite($b);

        $this->lightness = $lightness;
        $this->a = $a;
        $this->b = $b;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toLab(): static
    {
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toLch(): Lch
    {
        [$L, $C, $H] = ColorConverter::labToLch($this->lightness, $this->a, $this->b);

        return new Lch($L, $C, $H, $this->alpha);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toString(bool|null $alpha = null, int $precision = 2): string
    {
        $alpha ??= $this->alpha < 1;

        $result = 'lab('.
            round($this->lightness, $precision).'% '.
            round($this->a, $precision).' '.
            round($this->b, $precision);

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
    public function toXyzD50(): XyzD50
    {
        [$x, $y, $z] = ColorConverter::labToXyzD50($this->lightness, $this->a, $this->b);

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
