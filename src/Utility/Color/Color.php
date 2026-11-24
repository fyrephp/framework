<?php
declare(strict_types=1);

namespace Fyre\Utility\Color;

use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Core\Traits\StaticMacroTrait;
use Fyre\Utility\Color\Colors\A98Rgb;
use Fyre\Utility\Color\Colors\DisplayP3;
use Fyre\Utility\Color\Colors\DisplayP3Linear;
use Fyre\Utility\Color\Colors\Hex;
use Fyre\Utility\Color\Colors\Hsl;
use Fyre\Utility\Color\Colors\Hwb;
use Fyre\Utility\Color\Colors\Lab;
use Fyre\Utility\Color\Colors\Lch;
use Fyre\Utility\Color\Colors\OkLab;
use Fyre\Utility\Color\Colors\OkLch;
use Fyre\Utility\Color\Colors\ProPhotoRgb;
use Fyre\Utility\Color\Colors\Rec2020;
use Fyre\Utility\Color\Colors\Rgb;
use Fyre\Utility\Color\Colors\Srgb;
use Fyre\Utility\Color\Colors\SrgbLinear;
use Fyre\Utility\Color\Colors\XyzD50;
use Fyre\Utility\Color\Colors\XyzD65;
use InvalidArgumentException;
use LogicException;
use Override;
use Stringable;
use UnexpectedValueException;

use function array_map;
use function array_reduce;
use function array_values;
use function count;
use function explode;
use function fmod;
use function hexdec;
use function hypot;
use function implode;
use function in_array;
use function is_finite;
use function max;
use function min;
use function preg_match;
use function preg_replace;
use function rad2deg;
use function round;
use function sprintf;
use function str_contains;
use function str_split;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const PHP_INT_MAX;

/**
 * Provides color parsing, formatting, and conversion utilities.
 *
 * Note: Hue values are wrapped to 0–360 and alpha values are clamped to 0–1.
 * Other channels preserve extended values to avoid conversion clipping.
 *
 * @phpstan-consistent-constructor
 */
abstract class Color implements Stringable
{
    use DebugTrait;
    use MacroTrait;
    use StaticMacroTrait;

    public const CSS_COLORS = [
        'aliceblue' => '#f0f8ff',
        'antiquewhite' => '#faebd7',
        'aqua' => '#00ffff',
        'aquamarine' => '#7fffd4',
        'azure' => '#f0ffff',
        'beige' => '#f5f5dc',
        'bisque' => '#ffe4c4',
        'black' => '#000000',
        'blanchedalmond' => '#ffebcd',
        'blue' => '#0000ff',
        'blueviolet' => '#8a2be2',
        'brown' => '#a52a2a',
        'burlywood' => '#deb887',
        'cadetblue' => '#5f9ea0',
        'chartreuse' => '#7fff00',
        'chocolate' => '#d2691e',
        'coral' => '#ff7f50',
        'cornflowerblue' => '#6495ed',
        'cornsilk' => '#fff8dc',
        'crimson' => '#dc143c',
        'cyan' => '#00ffff',
        'darkblue' => '#00008b',
        'darkcyan' => '#008b8b',
        'darkgoldenrod' => '#b8860b',
        'darkgray' => '#a9a9a9',
        'darkgrey' => '#a9a9a9',
        'darkgreen' => '#006400',
        'darkkhaki' => '#bdb76b',
        'darkmagenta' => '#8b008b',
        'darkolivegreen' => '#556b2f',
        'darkorange' => '#ff8c00',
        'darkorchid' => '#9932cc',
        'darkred' => '#8b0000',
        'darksalmon' => '#e9967a',
        'darkseagreen' => '#8fbc8f',
        'darkslateblue' => '#483d8b',
        'darkslategray' => '#2f4f4f',
        'darkslategrey' => '#2f4f4f',
        'darkturquoise' => '#00ced1',
        'darkviolet' => '#9400d3',
        'deeppink' => '#ff1493',
        'deepskyblue' => '#00bfff',
        'dimgray' => '#696969',
        'dimgrey' => '#696969',
        'dodgerblue' => '#1e90ff',
        'firebrick' => '#b22222',
        'floralwhite' => '#fffaf0',
        'forestgreen' => '#228b22',
        'fuchsia' => '#ff00ff',
        'gainsboro' => '#dcdcdc',
        'ghostwhite' => '#f8f8ff',
        'gold' => '#ffd700',
        'goldenrod' => '#daa520',
        'gray' => '#808080',
        'grey' => '#808080',
        'green' => '#008000',
        'greenyellow' => '#adff2f',
        'honeydew' => '#f0fff0',
        'hotpink' => '#ff69b4',
        'indianred' => '#cd5c5c',
        'indigo' => '#4b0082',
        'ivory' => '#fffff0',
        'khaki' => '#f0e68c',
        'lavender' => '#e6e6fa',
        'lavenderblush' => '#fff0f5',
        'lawngreen' => '#7cfc00',
        'lemonchiffon' => '#fffacd',
        'lightblue' => '#add8e6',
        'lightcoral' => '#f08080',
        'lightcyan' => '#e0ffff',
        'lightgoldenrodyellow' => '#fafad2',
        'lightgray' => '#d3d3d3',
        'lightgrey' => '#d3d3d3',
        'lightgreen' => '#90ee90',
        'lightpink' => '#ffb6c1',
        'lightsalmon' => '#ffa07a',
        'lightseagreen' => '#20b2aa',
        'lightskyblue' => '#87cefa',
        'lightslategray' => '#778899',
        'lightslategrey' => '#778899',
        'lightsteelblue' => '#b0c4de',
        'lightyellow' => '#ffffe0',
        'lime' => '#00ff00',
        'limegreen' => '#32cd32',
        'linen' => '#faf0e6',
        'magenta' => '#ff00ff',
        'maroon' => '#800000',
        'mediumaquamarine' => '#66cdaa',
        'mediumblue' => '#0000cd',
        'mediumorchid' => '#ba55d3',
        'mediumpurple' => '#9370db',
        'mediumseagreen' => '#3cb371',
        'mediumslateblue' => '#7b68ee',
        'mediumspringgreen' => '#00fa9a',
        'mediumturquoise' => '#48d1cc',
        'mediumvioletred' => '#c71585',
        'midnightblue' => '#191970',
        'mintcream' => '#f5fffa',
        'mistyrose' => '#ffe4e1',
        'moccasin' => '#ffe4b5',
        'navajowhite' => '#ffdead',
        'navy' => '#000080',
        'oldlace' => '#fdf5e6',
        'olive' => '#808000',
        'olivedrab' => '#6b8e23',
        'orange' => '#ffa500',
        'orangered' => '#ff4500',
        'orchid' => '#da70d6',
        'palegoldenrod' => '#eee8aa',
        'palegreen' => '#98fb98',
        'paleturquoise' => '#afeeee',
        'palevioletred' => '#db7093',
        'papayawhip' => '#ffefd5',
        'peachpuff' => '#ffdab9',
        'peru' => '#cd853f',
        'pink' => '#ffc0cb',
        'plum' => '#dda0dd',
        'powderblue' => '#b0e0e6',
        'purple' => '#800080',
        'rebeccapurple' => '#663399',
        'red' => '#ff0000',
        'rosybrown' => '#bc8f8f',
        'royalblue' => '#4169e1',
        'saddlebrown' => '#8b4513',
        'salmon' => '#fa8072',
        'sandybrown' => '#f4a460',
        'seagreen' => '#2e8b57',
        'seashell' => '#fff5ee',
        'sienna' => '#a0522d',
        'silver' => '#c0c0c0',
        'skyblue' => '#87ceeb',
        'slateblue' => '#6a5acd',
        'slategray' => '#708090',
        'slategrey' => '#708090',
        'snow' => '#fffafa',
        'springgreen' => '#00ff7f',
        'steelblue' => '#4682b4',
        'tan' => '#d2b48c',
        'teal' => '#008080',
        'thistle' => '#d8bfd8',
        'tomato' => '#ff6347',
        'turquoise' => '#40e0d0',
        'violet' => '#ee82ee',
        'wheat' => '#f5deb3',
        'white' => '#ffffff',
        'whitesmoke' => '#f5f5f5',
        'yellow' => '#ffff00',
        'yellowgreen' => '#9acd32',
    ];

    protected const COLOR_SPACE = '';

    protected const CONVERSION_MAP = [
        'a98-rgb' => 'toA98Rgb',
        'display-p3' => 'toDisplayP3',
        'display-p3-linear' => 'toDisplayP3Linear',
        'hsl' => 'toHsl',
        'hwb' => 'toHwb',
        'lab' => 'toLab',
        'lch' => 'toLch',
        'oklab' => 'toOkLab',
        'oklch' => 'toOkLch',
        'prophoto-rgb' => 'toProPhotoRgb',
        'rec2020' => 'toRec2020',
        'hex' => 'toHex',
        'rgb' => 'toRgb',
        'srgb' => 'toSrgb',
        'srgb-linear' => 'toSrgbLinear',
        'xyz-d50' => 'toXyzD50',
        'xyz-d65' => 'toXyzD65',
    ];

    protected const CSS_NUMBER_PATTERN = '[+-]?(?:\d+|\d*\.\d+)(?:e[+-]?\d+)?';

    protected const FIT_GAMUT_PRECISION = 0.0001;

    protected const FIT_GAMUT_RANGES = [
        'a98-rgb' => [0.0, 1.0],
        'display-p3' => [0.0, 1.0],
        'display-p3-linear' => [0.0, 1.0],
        'prophoto-rgb' => [0.0, 1.0],
        'rec2020' => [0.0, 1.0],
        'srgb' => [0.0, 1.0],
        'srgb-linear' => [0.0, 1.0],
        'rgb' => [0.0, 255.0],
    ];

    public readonly float $alpha;

    /**
     * Creates a Color from A98 RGB color values.
     *
     * @param float $red The red value. (0, 1)
     * @param float $green The green value. (0, 1)
     * @param float $blue The blue value. (0, 1)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromA98Rgb(float $red = 0, float $green = 0, float $blue = 0, float $alpha = 1): static
    {
        return new A98Rgb($red, $green, $blue, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from Display P3 color values.
     *
     * @param float $red The red value. (0, 1)
     * @param float $green The green value. (0, 1)
     * @param float $blue The blue value. (0, 1)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromDisplayP3(float $red = 0, float $green = 0, float $blue = 0, float $alpha = 1): static
    {
        return new DisplayP3($red, $green, $blue, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from Display P3 Linear color values.
     *
     * @param float $red The red value. (0, 1)
     * @param float $green The green value. (0, 1)
     * @param float $blue The blue value. (0, 1)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromDisplayP3Linear(float $red = 0, float $green = 0, float $blue = 0, float $alpha = 1): static
    {
        return new DisplayP3Linear($red, $green, $blue, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from HSL color values.
     *
     * @param float $hue The hue value. (0, 360)
     * @param float $saturation The saturation value. (0, 100)
     * @param float $lightness The lightness value. (0, 100)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromHsl(float $hue = 0, float $saturation = 0, float $lightness = 0, float $alpha = 1): static
    {
        return new Hsl($hue, $saturation, $lightness, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from HWB color values.
     *
     * @param float $hue The hue value. (0, 360)
     * @param float $whiteness The whiteness value. (0, 100)
     * @param float $blackness The blackness value. (0, 100)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromHwb(float $hue = 0, float $whiteness = 0, float $blackness = 0, float $alpha = 1): static
    {
        return new Hwb($hue, $whiteness, $blackness, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from LAB color values.
     *
     * @param float $lightness The lightness value. (0, 100)
     * @param float $a The a value. (-128, 127)
     * @param float $b The b value. (-128, 127)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromLab(float $lightness = 0, float $a = 0, float $b = 0, float $alpha = 1): static
    {
        return new Lab($lightness, $a, $b, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from LCH color values.
     *
     * @param float $lightness The lightness value. (0, 100)
     * @param float $chroma The chroma value. (0, 230)
     * @param float $hue The hue value. (0, 360)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromLch(float $lightness = 0, float $chroma = 0, float $hue = 0, float $alpha = 1): static
    {
        return new Lch($lightness, $chroma, $hue, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from OK LAB color values.
     *
     * @param float $lightness The lightness value. (0, 1)
     * @param float $a The a value. (-0.4, 0.4)
     * @param float $b The b value. (-0.4, 0.4)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromOkLab(float $lightness = 0, float $a = 0, float $b = 0, float $alpha = 1): static
    {
        return new OkLab($lightness, $a, $b, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from OK LCH color values.
     *
     * @param float $lightness The lightness value. (0, 1)
     * @param float $chroma The chroma value. (0, 0.4)
     * @param float $hue The hue value. (0, 360)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromOkLch(float $lightness = 0, float $chroma = 0, float $hue = 0, float $alpha = 1): static
    {
        return new OkLch($lightness, $chroma, $hue, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from ProPhoto RGB color values.
     *
     * @param float $red The red value. (0, 1)
     * @param float $green The green value. (0, 1)
     * @param float $blue The blue value. (0, 1)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromProPhotoRgb(float $red = 0, float $green = 0, float $blue = 0, float $alpha = 1): static
    {
        return new ProPhotoRgb($red, $green, $blue, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from Rec. 2020 color values.
     *
     * @param float $red The red value. (0, 1)
     * @param float $green The green value. (0, 1)
     * @param float $blue The blue value. (0, 1)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromRec2020(float $red = 0, float $green = 0, float $blue = 0, float $alpha = 1): static
    {
        return new Rec2020($red, $green, $blue, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from RGB color values.
     *
     * @param float $red The red value. (0, 255)
     * @param float $green The green value. (0, 255)
     * @param float $blue The blue value. (0, 255)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromRgb(float $red = 0, float $green = 0, float $blue = 0, float $alpha = 1): static
    {
        return new Rgb($red, $green, $blue, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from SRGB color values.
     *
     * @param float $red The red value. (0, 1)
     * @param float $green The green value. (0, 1)
     * @param float $blue The blue value. (0, 1)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromSrgb(float $red = 0, float $green = 0, float $blue = 0, float $alpha = 1): static
    {
        return new Srgb($red, $green, $blue, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from SRGB Linear color values.
     *
     * @param float $red The red value. (0, 1)
     * @param float $green The green value. (0, 1)
     * @param float $blue The blue value. (0, 1)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromSrgbLinear(float $red = 0, float $green = 0, float $blue = 0, float $alpha = 1): static
    {
        return new SrgbLinear($red, $green, $blue, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from a CSS color string.
     *
     * @param string $string The CSS color string.
     * @return static The new Color instance.
     *
     * Supported formats include named colors, hex colors, functional notations, and `color()` values.
     * Whitespace and case are normalized before parsing.
     *
     * CSS parsing notes (percent reference ranges):
     * - `rgb()` / `rgba()`: channel percentages are relative to 255.
     * - `hsl()` / `hsla()` / `hwb()`: percentages map directly to 0-100 channel values.
     * - `lab()`: lightness percentages map to 0-100; `a`/`b` percentages map to 125.
     * - `lch()`: lightness percentages map to 0-100; chroma percentages map to 150.
     * - `oklab()`: lightness percentages map to 1; `a`/`b` percentages map to 0.4.
     * - `oklch()`: lightness percentages map to 1; chroma percentages map to 0.4.
     * - `color(<profile> ...)`: percentages map to 0-1 component values.
     *
     * Additional parsing rules:
     * - `<angle>` supports plain degrees plus `%`, `grad`, `rad`, and `turn` units.
     * - Negative chroma in `lch()` and `oklch()` is clamped to 0 at parse time.
     *
     * @throws InvalidArgumentException If the CSS color string is not valid.
     */
    public static function createFromString(string $string): static
    {
        $string = ((string) preg_replace('/\s+/', ' ', $string))
            |> trim(...)
            |> strtolower(...);

        if ($string === 'transparent') {
            return static::createFromRgb(alpha: 0);
        }

        if (isset(static::CSS_COLORS[$string])) {
            $string = static::CSS_COLORS[$string];
        }

        if (
            preg_match('/\A#([0-9a-f]+)\z/i', $string, $match) &&
            in_array(strlen($match[1]), [3, 4, 6, 8], true)
        ) {
            $hex = $match[1];

            if (strlen($hex) <= 4) {
                $characters = array_map(
                    static fn(string $character): string => $character.$character,
                    str_split($hex)
                );
                $hex = implode('', $characters);
            }

            return new Hex(
                substr($hex, 0, 2) |> hexdec(...),
                substr($hex, 2, 2) |> hexdec(...),
                substr($hex, 4, 2) |> hexdec(...),
                strlen($hex) > 6 ?
                    (substr($hex, 6, 2) |> hexdec(...)) / 255 :
                    1,
            ) |> static::toStaticColor(...);
        }

        try {
            if (preg_match('/\A(rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch)\((.+)\)\z/', $string, $match)) {
                $space = $match[1];
                $parts = static::parseCssArguments(
                    $match[2],
                    in_array($space, ['rgb', 'rgba', 'hsl', 'hsla', 'hwb'], true)
                );

                switch ($space) {
                    case 'hsl':
                    case 'hsla':
                        return static::createFromHsl(
                            static::parseCssAngle($parts[0]),
                            static::parseCssNumber($parts[1], 100),
                            static::parseCssNumber($parts[2], 100),
                            static::parseCssNumber($parts[3]),
                        );
                    case 'hwb':
                        return static::createFromHwb(
                            static::parseCssAngle($parts[0]),
                            static::parseCssNumber($parts[1], 100),
                            static::parseCssNumber($parts[2], 100),
                            static::parseCssNumber($parts[3]),
                        );
                    case 'lab':
                        return static::createFromLab(
                            static::parseCssNumber($parts[0], 100),
                            static::parseCssNumber($parts[1], 125),
                            static::parseCssNumber($parts[2], 125),
                            static::parseCssNumber($parts[3]),
                        );
                    case 'lch':
                        $chroma = max(
                            0,
                            static::parseCssNumber($parts[1], 150)
                        );

                        return static::createFromLch(
                            static::parseCssNumber($parts[0], 100),
                            $chroma,
                            static::parseCssAngle($parts[2]),
                            static::parseCssNumber($parts[3]),
                        );
                    case 'oklab':
                        return static::createFromOkLab(
                            static::parseCssNumber($parts[0]),
                            static::parseCssNumber($parts[1], 0.4),
                            static::parseCssNumber($parts[2], 0.4),
                            static::parseCssNumber($parts[3]),
                        );
                    case 'oklch':
                        $chroma = max(
                            0,
                            static::parseCssNumber($parts[1], 0.4)
                        );

                        return static::createFromOkLch(
                            static::parseCssNumber($parts[0]),
                            $chroma,
                            static::parseCssAngle($parts[2]),
                            static::parseCssNumber($parts[3]),
                        );
                    case 'rgb':
                    case 'rgba':
                        return static::createFromRgb(
                            static::parseCssNumber($parts[0], 255),
                            static::parseCssNumber($parts[1], 255),
                            static::parseCssNumber($parts[2], 255),
                            static::parseCssNumber($parts[3]),
                        );
                }
            }

            if (preg_match('/\Acolor\((a98-rgb|display-p3(?:-linear)?|prophoto-rgb|rec2020|srgb(?:-linear)?|xyz(?:-d50|-d65)?)\s+(.+)\)\z/', $string, $match)) {
                $space = $match[1];
                $values = static::parseCssArguments($match[2])
                    |> array_map(static::parseCssNumber(...), ?);

                switch ($space) {
                    case 'a98-rgb':
                        return static::createFromA98Rgb(...$values);
                    case 'display-p3':
                        return static::createFromDisplayP3(...$values);
                    case 'display-p3-linear':
                        return static::createFromDisplayP3Linear(...$values);
                    case 'prophoto-rgb':
                        return static::createFromProPhotoRgb(...$values);
                    case 'rec2020':
                        return static::createFromRec2020(...$values);
                    case 'srgb':
                        return static::createFromSrgb(...$values);
                    case 'srgb-linear':
                        return static::createFromSrgbLinear(...$values);
                    case 'xyz-d50':
                        return static::createFromXyzD50(...$values);
                    case 'xyz':
                    case 'xyz-d65':
                        return static::createFromXyzD65(...$values);
                }
            }
        } catch (UnexpectedValueException) {
            // Fall through to the consistent invalid string exception.
        }

        throw new InvalidArgumentException(sprintf(
            'Color string `%s` is not valid.',
            $string
        ));
    }

    /**
     * Creates a Color from XYZ D50 color values.
     *
     * @param float $x The x value. (0, 1)
     * @param float $y The y value. (0, 1)
     * @param float $z The z value. (0, 1)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromXyzD50(float $x = 0, float $y = 0, float $z = 0, float $alpha = 1): static
    {
        return new XyzD50($x, $y, $z, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Creates a Color from XYZ D65 color values.
     *
     * @param float $x The x value. (0, 1)
     * @param float $y The y value. (0, 1)
     * @param float $z The z value. (0, 1)
     * @param float $alpha The alpha value. (0, 1)
     * @return static The new Color instance.
     */
    public static function createFromXyzD65(float $x = 0, float $y = 0, float $z = 0, float $alpha = 1): static
    {
        return new XyzD65($x, $y, $z, $alpha) |> static::toStaticColor(...);
    }

    /**
     * Constructs a Color.
     *
     * @param float $alpha The alpha value. (0, 1)
     */
    public function __construct(
        float $alpha = 1,
    ) {
        $this->alpha = static::clamp($alpha);
    }

    /**
     * Returns the CSS color string.
     *
     * @return string The CSS color string.
     */
    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Composites this Color over a background Color using source-over alpha compositing in sRGB.
     *
     * @param Color $background The background Color.
     * @return static The composited Color.
     */
    public function composite(Color $background): static
    {
        $foreground = $this->toSrgb();
        $background = $background->toSrgb();

        $foregroundAlpha = $foreground->getAlpha();
        $backgroundAlpha = $background->getAlpha();
        $backgroundContribution = $backgroundAlpha * (1 - $foregroundAlpha);
        $alpha = $foregroundAlpha + $backgroundContribution;

        if ($alpha === 0.0) {
            return $this;
        }

        $foregroundWeight = $foregroundAlpha / $alpha;
        $backgroundWeight = $backgroundContribution / $alpha;

        $red = ($foreground->getRed() * $foregroundWeight) +
            ($background->getRed() * $backgroundWeight);
        $green = ($foreground->getGreen() * $foregroundWeight) +
            ($background->getGreen() * $backgroundWeight);
        $blue = ($foreground->getBlue() * $foregroundWeight) +
            ($background->getBlue() * $backgroundWeight);

        return static::createFromSrgb($red, $green, $blue, $alpha);
    }

    /**
     * Calculates the contrast between this and another Color.
     *
     * @param Color $other The other Color.
     * @return float The contrast.
     */
    public function contrast(Color $other): float
    {
        if ($this->alpha < 1 || $other->alpha < 1) {
            throw new LogicException('Contrast can only be calculated between fully opaque colors.');
        }

        $l1 = $this->luma();
        $l2 = $other->luma();

        if ($l1 < $l2) {
            return ($l2 + .05) / ($l1 + .05);
        }

        return ($l1 + .05) / ($l2 + .05);
    }

    /**
     * Fits this color to the target gamut by reducing OKLCH chroma.
     *
     * @param string $space The target gamut space.
     * @return static The fitted Color in the current color space.
     *
     * @throws InvalidArgumentException If the color space is not supported for gamut fitting.
     */
    public function fitGamut(string $space = 'srgb'): static
    {
        if (!isset(static::FIT_GAMUT_RANGES[$space])) {
            throw new InvalidArgumentException(sprintf(
                'Color space `%s` does not support gamut fitting.',
                $space
            ));
        }

        $converted = $this->to($space);

        if (static::isInGamut($converted, $space)) {
            return $this;
        }

        $okLch = $this->toOkLch();
        $lightness = $okLch->getLightness();

        if ($lightness <= 0 || $lightness >= 1) {
            $fitted = $okLch->withLightness(static::clamp($lightness))->withChroma(0);

            return static::toStaticColor($fitted);
        }

        $low = 0.0;
        $high = max(0.0, $okLch->getChroma());

        while ($high - $low > static::FIT_GAMUT_PRECISION) {
            $mid = ($low + $high) / 2;
            $candidate = $okLch->withChroma($mid);

            if (static::isInGamut($candidate->to($space), $space)) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return $okLch->withChroma($low) |> static::toStaticColor(...);
    }

    /**
     * Returns the alpha value.
     *
     * @return float The alpha value.
     */
    public function getAlpha(): float
    {
        return $this->alpha;
    }

    /**
     * Finds the closest CSS named color for this color (in the current color space).
     *
     * @return string The closest CSS named color.
     */
    public function label(): string
    {
        $a = $this->toArray() |> array_values(...);

        $closestDist = PHP_INT_MAX;
        foreach (static::CSS_COLORS as $label => $hex) {
            $b = static::createFromString($hex)->toArray() |> array_values(...);

            $dist = array_reduce([
                $a[0] - $b[0],
                $a[1] - $b[1],
                $a[2] - $b[2],
            ], hypot(...), 0);

            if ($dist < $closestDist) {
                $closest = $label;
                $closestDist = $dist;
            }
        }

        return $closest ?? '';
    }

    /**
     * Calculates the relative luminance value.
     *
     * @return float The relative luminance value.
     */
    public function luma(): float
    {
        return $this->toSrgb()->luma();
    }

    /**
     * Returns the current color space.
     *
     * @return string The current color space.
     */
    public function space(): string
    {
        return static::COLOR_SPACE;
    }

    /**
     * Converts the Color to a named color space.
     *
     * If the color space is an empty string or matches the current color space,
     * the current instance is returned.
     *
     * @param string $space The color space.
     * @return self The Color instance.
     *
     * @throws InvalidArgumentException If the color space is not valid.
     */
    public function to(string $space): self
    {
        if (!$space || static::COLOR_SPACE === $space) {
            return $this;
        }

        if (!isset(static::CONVERSION_MAP[$space])) {
            throw new InvalidArgumentException(sprintf(
                'Color space `%s` is not valid.',
                $space
            ));
        }

        return $this->{static::CONVERSION_MAP[$space]}();
    }

    /**
     * Converts the Color to A98Rgb.
     *
     * @return A98Rgb The new A98Rgb instance.
     */
    public function toA98Rgb(): A98Rgb
    {
        return $this->toXyzD65()->toA98Rgb();
    }

    /**
     * Returns the color components as an array.
     *
     * @return array<string, float> The color components.
     */
    abstract public function toArray(): array;

    /**
     * Converts the Color to DisplayP3.
     *
     * @return DisplayP3 The new DisplayP3 instance.
     */
    public function toDisplayP3(): DisplayP3
    {
        return $this->toDisplayP3Linear()->toDisplayP3();
    }

    /**
     * Converts the Color to DisplayP3Linear.
     *
     * @return DisplayP3Linear The new DisplayP3Linear instance.
     */
    public function toDisplayP3Linear(): DisplayP3Linear
    {
        return $this->toXyzD65()->toDisplayP3Linear();
    }

    /**
     * Converts the Color to Hex.
     *
     * @return Hex The new Hex instance.
     */
    public function toHex(): Hex
    {
        return $this->toRgb()->toHex();
    }

    /**
     * Converts the Color to Hsl.
     *
     * @return Hsl The new Hsl instance.
     */
    public function toHsl(): Hsl
    {
        return $this->toSrgb()->toHsl();
    }

    /**
     * Converts the Color to Hwb.
     *
     * @return Hwb The new Hwb instance.
     */
    public function toHwb(): Hwb
    {
        return $this->toSrgb()->toHwb();
    }

    /**
     * Converts the Color to Lab.
     *
     * @return Lab The new Lab instance.
     */
    public function toLab(): Lab
    {
        return $this->toXyzD50()->toLab();
    }

    /**
     * Converts the Color to Lch.
     *
     * @return Lch The new Lch instance.
     */
    public function toLch(): Lch
    {
        return $this->toLab()->toLch();
    }

    /**
     * Converts the Color to OkLab.
     *
     * @return OkLab The new OkLab instance.
     */
    public function toOkLab(): OkLab
    {
        return $this->toXyzD65()->toOkLab();
    }

    /**
     * Converts the Color to OkLch.
     *
     * @return OkLch The new OkLch instance.
     */
    public function toOkLch(): OkLch
    {
        return $this->toOkLab()->toOkLch();
    }

    /**
     * Converts the Color to ProPhotoRgb.
     *
     * @return ProPhotoRgb The new ProPhotoRgb instance.
     */
    public function toProPhotoRgb(): ProPhotoRgb
    {
        return $this->toXyzD50()->toProPhotoRgb();
    }

    /**
     * Converts the Color to Rec2020.
     *
     * @return Rec2020 The new Rec2020 instance.
     */
    public function toRec2020(): Rec2020
    {
        return $this->toXyzD65()->toRec2020();
    }

    /**
     * Converts the Color to Rgb.
     *
     * @return Rgb The new Rgb instance.
     */
    public function toRgb(): Rgb
    {
        return $this->toSrgb()->toRgb();
    }

    /**
     * Converts the Color to Srgb.
     *
     * @return Srgb The new Srgb instance.
     */
    public function toSrgb(): Srgb
    {
        return $this->toSrgbLinear()->toSrgb();
    }

    /**
     * Converts the Color to SrgbLinear.
     *
     * @return SrgbLinear The new SrgbLinear instance.
     */
    public function toSrgbLinear(): SrgbLinear
    {
        return $this->toXyzD65()->toSrgbLinear();
    }

    /**
     * Returns the CSS `color()` string for the current color space.
     *
     * @param bool|null $alpha Whether to include the alpha value.
     * @param int $precision The decimal precision.
     * @return string The CSS color string.
     */
    public function toString(bool|null $alpha = null, int $precision = 2): string
    {
        $alpha ??= $this->alpha < 1;

        $values = $this->toArray() |> array_values(...);

        $result = 'color('.
            static::COLOR_SPACE.
            ' '.
            round($values[0], $precision).' '.
            round($values[1], $precision).' '.
            round($values[2], $precision);

        if ($alpha) {
            $result .= ' / '.round($this->alpha, $precision);
        }

        $result .= ')';

        return $result;
    }

    /**
     * Converts the Color to XyzD50.
     *
     * @return XyzD50 The new XyzD50 instance.
     */
    public function toXyzD50(): XyzD50
    {
        return $this->toXyzD65()->toXyzD50();
    }

    /**
     * Converts the Color to XyzD65.
     *
     * @return XyzD65 The new XyzD65 instance.
     */
    public function toXyzD65(): XyzD65
    {
        return $this->toSrgbLinear()->toXyzD65();
    }

    /**
     * Clones the Color with a new alpha value.
     *
     * @param float $alpha The alpha value.
     * @return static The new Color instance with the updated alpha value.
     */
    public function withAlpha(float $alpha): static
    {
        $data = $this->toArray();
        $data['alpha'] = $alpha;

        return new static(...array_values($data));
    }

    /**
     * Clamps a value between a min and max.
     *
     * @param float $value The value to clamp.
     * @param float $min The minimum value.
     * @param float $max The maximum value.
     * @return float The clamped value.
     */
    protected static function clamp(float $value, float $min = 0, float $max = 1): float
    {
        static::ensureFinite($value);

        return max($min, min($max, $value));
    }

    /**
     * Clamps a hue value.
     *
     * @param float $value The value to clamp.
     * @return float The clamped value.
     */
    protected static function clampHue(float $value): float
    {
        static::ensureFinite($value);

        $value = fmod($value, 360);

        if ($value < 0) {
            $value += 360;
        }

        return $value;
    }

    /**
     * Ensures a value is finite.
     *
     * @param float $value The value.
     *
     * @throws InvalidArgumentException If the value is not finite.
     */
    protected static function ensureFinite(float $value): void
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('Color channel values must be finite numbers.');
        }
    }

    /**
     * Checks whether a color falls within the gamut bounds for the given space.
     *
     * @param self $color The Color in the target color space.
     * @param string $space The color space.
     * @return bool TRUE if in gamut, otherwise FALSE.
     */
    protected static function isInGamut(self $color, string $space): bool
    {
        [$min, $max] = static::FIT_GAMUT_RANGES[$space];
        $epsilon = ($max - $min) * 1e-12;
        $values = $color->toArray() |> array_values(...);

        foreach ([$values[0], $values[1], $values[2]] as $value) {
            if (!is_finite($value) || $value < $min - $epsilon || $value > $max + $epsilon) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parses an angle from a CSS value.
     *
     * @param string $value The CSS value.
     * @return float The parsed angle.
     */
    protected static function parseCssAngle(string $value): float
    {
        if (!preg_match('/\A('.static::CSS_NUMBER_PATTERN.')(deg|grad|rad|turn|%)?\z/', $value, $match)) {
            throw new UnexpectedValueException();
        }

        $number = (float) $match[1];

        return match ($match[2] ?? '') {
            '%' => $number * 3.6,
            'grad' => $number * .9,
            'rad' => rad2deg($number),
            'turn' => $number * 360,
            default => $number,
        };
    }

    /**
     * Parses CSS function arguments.
     *
     * @param string $value The argument string.
     * @param bool $allowCommas Whether legacy comma separators are allowed.
     * @return array{string, string, string, string} The parsed arguments.
     *
     * @throws UnexpectedValueException If the arguments are not valid.
     */
    protected static function parseCssArguments(string $value, bool $allowCommas = false): array
    {
        $parts = [];

        if (str_contains($value, ',')) {
            if ($allowCommas && !str_contains($value, '/')) {
                $parts = explode(',', $value) |> array_map(trim(...), ?);

                if (count($parts) === 3) {
                    $parts[] = '1';
                }
            }
        } else {
            $groups = explode('/', $value) |> array_map(trim(...), ?);

            if (count($groups) <= 2) {
                $parts = explode(' ', $groups[0]);
                $parts[] = $groups[1] ?? '1';
            }
        }

        if (count($parts) !== 4 || in_array('', $parts, true)) {
            throw new UnexpectedValueException();
        }

        return $parts;
    }

    /**
     * Parses a number from a CSS value.
     *
     * @param string $value The CSS value.
     * @param float $percentMultiplier The percent multiplier.
     * @return float The parsed number.
     */
    protected static function parseCssNumber(string $value, float $percentMultiplier = 1): float
    {
        if (!preg_match('/\A('.static::CSS_NUMBER_PATTERN.')(%)?\z/', $value, $match)) {
            throw new UnexpectedValueException();
        }

        $number = (float) $match[1];

        return isset($match[2]) ?
            $number / 100 * $percentMultiplier :
            $number;
    }

    /**
     * Converts a Color to the late-bound color space.
     *
     * @param Color $color The Color.
     * @return static The Color in the late-bound color space.
     */
    protected static function toStaticColor(Color $color): static
    {
        $color = $color->to(static::COLOR_SPACE);

        /** @var static $color */
        return $color;
    }
}
