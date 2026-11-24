<?php
declare(strict_types=1);

namespace Fyre\Utility\Color;

use function abs;
use function atan2;
use function cos;
use function deg2rad;
use function fmod;
use function hypot;
use function max;
use function min;
use function pow;
use function rad2deg;
use function sin;

/**
 * Provides low-level color conversion utilities.
 *
 * This class contains the conversion math used by the Color classes and does not validate inputs beyond what is
 * required for the calculations.
 *
 * @internal
 */
abstract class ColorConverter
{
    /**
     * Converts A98 RGB color values to XYZ D65.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The XYZ D65 values.
     */
    public static function a98RgbToXyzD65(float $r, float $g, float $b): array
    {
        $r = static::powSigned($r, 2.19921875);
        $g = static::powSigned($g, 2.19921875);
        $b = static::powSigned($b, 2.19921875);

        return [
            (0.5766690429101308 * $r) + (0.1855582379065463 * $g) + (0.1882286462349947 * $b),
            (0.2973449752505362 * $r) + (0.6273635662554660 * $g) + (0.0752914584939979 * $b),
            (0.0270313613864124 * $r) + (0.0706888525358271 * $g) + (0.9913375368376389 * $b),
        ];
    }

    /**
     * Converts Display P3 Linear color values to Display P3.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The Display P3 values.
     */
    public static function displayP3LinearToDisplayP3(float $r, float $g, float $b): array
    {
        return [
            static::linearSrgbChannelToSrgb($r),
            static::linearSrgbChannelToSrgb($g),
            static::linearSrgbChannelToSrgb($b),
        ];
    }

    /**
     * Converts Display P3 Linear color values to XYZ D65.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The XYZ D65 values.
     */
    public static function displayP3LinearToXyzD65(float $r, float $g, float $b): array
    {
        return [
            (0.4865709486482163 * $r) + (0.2656676931690929 * $g) + (0.1982172852343625 * $b),
            (0.2289745640697488 * $r) + (0.6917385218365062 * $g) + (0.0792869140937450 * $b),
            (0.0451133818589026 * $g) + (1.0439443689009757 * $b),
        ];
    }

    /**
     * Converts Display P3 color values to Display P3 Linear.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The Display P3 Linear values.
     */
    public static function displayP3ToDisplayP3Linear(float $r, float $g, float $b): array
    {
        return [
            static::srgbChannelToLinear($r),
            static::srgbChannelToLinear($g),
            static::srgbChannelToLinear($b),
        ];
    }

    /**
     * Converts HSL color values to SRGB.
     *
     * @param float $h The hue value. (0, 360)
     * @param float $s The saturation value. (0, 1)
     * @param float $l The lightness value. (0, 1)
     * @return array{float, float, float} The SRGB values.
     */
    public static function hslToSrgb(float $h, float $s, float $l): array
    {
        $h = fmod($h, 360) / 360;

        $r = $g = $b = $l;

        if ($s != 0) {
            $q = $l < 0.5 ?
                ($l * (1 + $s)) :
                ($l + $s - ($l * $s));
            $p = (2 * $l) - $q;
            $r = static::rgbHue($p, $q, $h + (1 / 3));
            $g = static::rgbHue($p, $q, $h);
            $b = static::rgbHue($p, $q, $h - (1 / 3));
        }

        return [$r, $g, $b];
    }

    /**
     * Converts HSV color values to SRGB.
     *
     * @param float $h The hue value. (0, 360)
     * @param float $s The saturation value. (0, 1)
     * @param float $v The brightness value. (0, 1)
     * @return array{float, float, float} The SRGB values.
     */
    public static function hsvToSrgb(float $h, float $s, float $v): array
    {
        $h = fmod($h + 360, 360);
        $c = $v * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $v - $c;

        if ($h < 60) {
            [$r1, $g1, $b1] = [$c, $x, 0];
        } else if ($h < 120) {
            [$r1, $g1, $b1] = [$x, $c, 0];
        } else if ($h < 180) {
            [$r1, $g1, $b1] = [0, $c, $x];
        } else if ($h < 240) {
            [$r1, $g1, $b1] = [0, $x, $c];
        } else if ($h < 300) {
            [$r1, $g1, $b1] = [$x, 0, $c];
        } else {
            [$r1, $g1, $b1] = [$c, 0, $x];
        }

        return [$r1 + $m, $g1 + $m, $b1 + $m];
    }

    /**
     * Converts HWB color values to SRGB.
     *
     * @param float $h The hue value. (0, 360)
     * @param float $w The whiteness value. (0, 1)
     * @param float $bl The blackness value. (0, 1)
     * @return array{float, float, float} The SRGB values.
     */
    public static function hwbToSrgb(float $h, float $w, float $bl): array
    {
        $total = $w + $bl;
        if ($total > 1) {
            $w /= $total;
            $bl /= $total;
        }

        [$r, $g, $b] = static::hsvToSrgb($h, 1, 1);
        $factor = 1 - $w - $bl;

        return [
            ($r * $factor) + $w,
            ($g * $factor) + $w,
            ($b * $factor) + $w,
        ];
    }

    /**
     * Converts LAB color values to LCH.
     *
     * @param float $L The lightness value. (0, 100)
     * @param float $a The a value. (-128, 127)
     * @param float $b The b value. (-128, 127)
     * @return array{float, float, float} The LCH values.
     */
    public static function labToLch(float $L, float $a, float $b): array
    {
        $C = hypot($a, $b);
        $H = atan2($b, $a)
            |> rad2deg(...)
            |> fmod(?, 360);

        if ($H < 0) {
            $H += 360;
        }

        return [$L, $C, $H];
    }

    /**
     * Converts LAB color values to XYZ D50.
     *
     * @param float $L The lightness value. (0, 100)
     * @param float $a The a value. (-128, 127)
     * @param float $b The b value. (-128, 127)
     * @return array{float, float, float} The XYZ D50 values.
     */
    public static function labToXyzD50(float $L, float $a, float $b): array
    {
        $epsilon = 216 / 24389;
        $kappa = 24389 / 27;
        $fy = ($L + 16) / 116;
        $fx = $fy + ($a / 500);
        $fz = $fy - ($b / 200);

        $fx3 = pow($fx, 3);
        $fz3 = pow($fz, 3);

        $xr = $fx3 > $epsilon ?
            $fx3 :
            (((116 * $fx) - 16) / $kappa);
        $yr = $L > ($kappa * $epsilon) ?
            pow($fy, 3) :
            ($L / $kappa);
        $zr = $fz3 > $epsilon ?
            $fz3 :
            (((116 * $fz) - 16) / $kappa);

        return [
            $xr * 0.9642956764295677,
            $yr,
            $zr * 0.8251046025104602,
        ];
    }

    /**
     * Converts LCH color values to LAB.
     *
     * @param float $L The lightness value. (0, 100)
     * @param float $C The chroma value. (0, 230)
     * @param float $H The hue value. (0, 360)
     * @return array{float, float, float} The LAB values.
     */
    public static function lchToLab(float $L, float $C, float $H): array
    {
        $H = deg2rad($H);

        return [
            $L,
            $C * cos($H),
            $C * sin($H),
        ];
    }

    /**
     * Converts OK LAB color values to OK LCH.
     *
     * @param float $L The lightness value. (0, 1)
     * @param float $a The a value. (-0.4, 0.4)
     * @param float $b The b value. (-0.4, 0.4)
     * @return array{float, float, float} The OK LCH values.
     */
    public static function okLabToOkLch(float $L, float $a, float $b): array
    {
        $C = hypot($a, $b);
        $H = atan2($b, $a)
            |> rad2deg(...)
            |> fmod(?, 360);

        if ($H < 0) {
            $H += 360;
        }

        return [$L, $C, $H];
    }

    /**
     * Converts OK LAB color values to XYZ D65.
     *
     * @param float $L The lightness value. (0, 1)
     * @param float $a The a value. (-0.4, 0.4)
     * @param float $b The b value. (-0.4, 0.4)
     * @return array{float, float, float} The XYZ D65 values.
     */
    public static function okLabToXyzD65(float $L, float $a, float $b): array
    {
        $l = pow($L + (0.3963377773761749 * $a) + (0.2158037573099136 * $b), 3);
        $m = pow($L - (0.1055613458156586 * $a) - (0.0638541728258133 * $b), 3);
        $s = pow($L - (0.0894841775298119 * $a) - (1.2914855480194092 * $b), 3);

        return [
            (1.2268798758459243 * $l) - (0.5578149944602171 * $m) + (0.2813910456659647 * $s),
            (-0.0405757452148008 * $l) + (1.1122868032803170 * $m) - (0.0717110580655164 * $s),
            (-0.0763729366746601 * $l) - (0.4214933324022432 * $m) + (1.5869240198367816 * $s),
        ];
    }

    /**
     * Converts OK LCH color values to OK LAB.
     *
     * @param float $L The lightness value. (0, 1)
     * @param float $C The chroma value. (0, 0.4)
     * @param float $H The hue value. (0, 360)
     * @return array{float, float, float} The OK LAB values.
     */
    public static function okLchToOkLab(float $L, float $C, float $H): array
    {
        $H = deg2rad($H);

        return [
            $L,
            $C * cos($H),
            $C * sin($H),
        ];
    }

    /**
     * Converts ProPhoto RGB color values to XYZ D50.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The XYZ D50 values.
     */
    public static function prophotoRgbToXyzD50(float $r, float $g, float $b): array
    {
        $decode = static fn(float $v): float => abs($v) <= 0.03125 ?
            $v / 16 :
            static::powSigned($v, 1.8);

        $r = $decode($r);
        $g = $decode($g);
        $b = $decode($b);

        return [
            (0.7977666449006423 * $r) + (0.1351812974005331 * $g) + (0.0313477341283922 * $b),
            (0.2880748288194013 * $r) + (0.7118352342418730 * $g) + (0.0000899369387256 * $b),
            0.8251046025104602 * $b,
        ];
    }

    /**
     * Converts Rec. 2020 color values to XYZ D65.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The XYZ D65 values.
     */
    public static function rec2020ToXyzD65(float $r, float $g, float $b): array
    {
        $r = static::powSigned($r, 2.4);
        $g = static::powSigned($g, 2.4);
        $b = static::powSigned($b, 2.4);

        return [
            (0.6369580483012913 * $r) + (0.1446169035862084 * $g) + (0.1688809751641721 * $b),
            (0.2627002120112670 * $r) + (0.6779980715188710 * $g) + (0.0593017164698619 * $b),
            (0.0280726930490875 * $g) + (1.0609850577107909 * $b),
        ];
    }

    /**
     * Converts RGB color values to SRGB.
     *
     * @param float $r The red value. (0, 255)
     * @param float $g The green value. (0, 255)
     * @param float $b The blue value. (0, 255)
     * @return array{float, float, float} The SRGB values.
     */
    public static function rgbToSrgb(float $r, float $g, float $b): array
    {
        return [$r / 255, $g / 255, $b / 255];
    }

    /**
     * Converts SRGB Linear color values to SRGB.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The SRGB values.
     */
    public static function srgbLinearToSrgb(float $r, float $g, float $b): array
    {
        return [
            static::linearSrgbChannelToSrgb($r),
            static::linearSrgbChannelToSrgb($g),
            static::linearSrgbChannelToSrgb($b),
        ];
    }

    /**
     * Converts SRGB Linear color values to XYZ D65.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The XYZ D65 values.
     */
    public static function srgbLinearToXyzD65(float $r, float $g, float $b): array
    {
        return [
            (0.4123907992659595 * $r) + (0.3575843393838780 * $g) + (0.1804807884018343 * $b),
            (0.2126390058715104 * $r) + (0.7151686787677559 * $g) + (0.0721923153607337 * $b),
            (0.0193308187155918 * $r) + (0.1191947797946260 * $g) + (0.9505321522496606 * $b),
        ];
    }

    /**
     * Converts SRGB color values to HSL.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The HSL values.
     */
    public static function srgbToHsl(float $r, float $g, float $b): array
    {
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d < 1e-12) {
            $h = $s = 0;
        } else {
            $s = $l > 0.5 ?
                ($d / (2 - $max - $min)) :
                ($d / ($max + $min));

            switch ($max) {
                case $r:
                    $h = (($g - $b) / $d) + ($g < $b ? 6 : 0);
                    break;
                case $g:
                    $h = (($b - $r) / $d) + 2;
                    break;
                case $b:
                    $h = (($r - $g) / $d) + 4;
                    break;
                default:
                    $h = 0;
                    break;
            }

            $h = fmod($h * 60, 360);

            if ($h < 0) {
                $h += 360;
            }
        }

        return [$h, $s, $l];
    }

    /**
     * Converts SRGB color values to HSV.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The HSV values.
     */
    public static function srgbToHsv(float $r, float $g, float $b): array
    {
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $v = $max;
        $d = $max - $min;
        $s = $max < 1e-12 ?
            0 :
            ($d / $max);

        if ($d < 1e-12) {
            $h = 0;
        } else if ($max === $r) {
            $h = 60 * fmod((($g - $b) / $d), 6);
        } else if ($max === $g) {
            $h = 60 * ((($b - $r) / $d) + 2);
        } else {
            $h = 60 * ((($r - $g) / $d) + 4);
        }

        $h = fmod($h, 360);

        if ($h < 0) {
            $h += 360;
        }

        return [$h, $s, $v];
    }

    /**
     * Converts SRGB color values to HWB.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The HWB values.
     */
    public static function srgbToHwb(float $r, float $g, float $b): array
    {
        [$h, $_, $_] = static::srgbToHsv($r, $g, $b);

        return [
            $h,
            min($r, $g, $b),
            1 - max($r, $g, $b),
        ];
    }

    /**
     * Converts SRGB color values to Luma.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return float The Luma value.
     */
    public static function srgbToLuma(float $r, float $g, float $b): float
    {
        $r = static::srgbChannelToLinear($r);
        $g = static::srgbChannelToLinear($g);
        $b = static::srgbChannelToLinear($b);

        return (.2126 * $r) + (.7152 * $g) + (.0722 * $b);
    }

    /**
     * Converts SRGB color values to RGB.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The RGB values.
     */
    public static function srgbToRgb(float $r, float $g, float $b): array
    {
        return [
            $r * 255,
            $g * 255,
            $b * 255,
        ];
    }

    /**
     * Converts SRGB color values to SRGB Linear.
     *
     * @param float $r The red value. (0, 1)
     * @param float $g The green value. (0, 1)
     * @param float $b The blue value. (0, 1)
     * @return array{float, float, float} The SRGB Linear values.
     */
    public static function srgbToSrgbLinear(float $r, float $g, float $b): array
    {
        return [
            static::srgbChannelToLinear($r),
            static::srgbChannelToLinear($g),
            static::srgbChannelToLinear($b),
        ];
    }

    /**
     * Converts XYZ D50 color values to LAB.
     *
     * @param float $x The x value. (0, 1)
     * @param float $y The y value. (0, 1)
     * @param float $z The z value. (0, 1)
     * @return array{float, float, float} The LAB values.
     */
    public static function xyzD50ToLab(float $x, float $y, float $z): array
    {
        $epsilon = 216 / 24389;
        $kappa = 24389 / 27;
        $encode = static fn(float $v): float => $v > $epsilon ?
            pow($v, 1 / 3) :
            (($kappa * $v + 16) / 116);

        $xr = $x / 0.9642956764295677;
        $yr = $y;
        $zr = $z / 0.8251046025104602;

        $fx = $encode($xr);
        $fy = $encode($yr);
        $fz = $encode($zr);

        return [
            (116 * $fy) - 16,
            500 * ($fx - $fy),
            200 * ($fy - $fz),
        ];
    }

    /**
     * Converts XYZ D50 color values to ProPhoto RGB.
     *
     * @param float $x The x value. (0, 1)
     * @param float $y The y value. (0, 1)
     * @param float $z The z value. (0, 1)
     * @return array{float, float, float} The ProPhoto RGB values.
     */
    public static function xyzD50ToProPhotoRgb(float $x, float $y, float $z): array
    {
        $encode = static fn(float $v): float => abs($v) >= 0.001953125 ?
            static::powSigned($v, 1 / 1.8) :
            $v * 16;

        $r = (1.3457868816471583 * $x) - (0.2555720873797946 * $y) - (0.0511018649755453 * $z);
        $g = (-0.5446307051249019 * $x) + (1.5082477428451468 * $y) + (0.0205274474364214 * $z);
        $b = 1.2119675456389452 * $z;

        $r = $encode($r);
        $g = $encode($g);
        $b = $encode($b);

        return [$r, $g, $b];
    }

    /**
     * Converts XYZ D50 color values to XYZ D65.
     *
     * @param float $x The x value. (0, 1)
     * @param float $y The y value. (0, 1)
     * @param float $z The z value. (0, 1)
     * @return array{float, float, float} The XYZ D65 values.
     */
    public static function xyzD50ToXyzD65(float $x, float $y, float $z): array
    {
        return [
            (0.9554734214880750 * $x) - (0.0230984549487647 * $y) + (0.0632592432005707 * $z),
            (-0.0283697093338637 * $x) + (1.0099953980813041 * $y) + (0.0210414411919173 * $z),
            (0.0123140148644820 * $x) - (0.0205076492988990 * $y) + (1.3303659262421240 * $z),
        ];
    }

    /**
     * Converts XYZ D65 color values to A98 RGB.
     *
     * @param float $x The x value. (0, 1)
     * @param float $y The y value. (0, 1)
     * @param float $z The z value. (0, 1)
     * @return array{float, float, float} The A98 RGB values.
     */
    public static function xyzD65ToA98Rgb(float $x, float $y, float $z): array
    {
        $r = (2.0415879038107461 * $x) - (0.5650069742788596 * $y) - (0.3447313507783295 * $z);
        $g = (-0.9692436362808798 * $x) + (1.8759675015077206 * $y) + (0.0415550574071756 * $z);
        $b = (0.0134442806320310 * $x) - (0.1183623922310182 * $y) + (1.0151749943912054 * $z);

        $gamma = 1 / 2.19921875;

        return [
            static::powSigned($r, $gamma),
            static::powSigned($g, $gamma),
            static::powSigned($b, $gamma),
        ];
    }

    /**
     * Converts XYZ D65 color values to Display P3 Linear.
     *
     * @param float $x The x value. (0, 1)
     * @param float $y The y value. (0, 1)
     * @param float $z The z value. (0, 1)
     * @return array{float, float, float} The Display P3 Linear values.
     */
    public static function xyzD65ToDisplayP3Linear(float $x, float $y, float $z): array
    {
        return [
            (2.4934969119414245 * $x) - (0.9313836179191236 * $y) - (0.4027107844507168 * $z),
            (-0.8294889695615750 * $x) + (1.7626640603183468 * $y) + (0.0236246858419436 * $z),
            (0.0358458302437843 * $x) - (0.0761723892680417 * $y) + (0.9568845240076873 * $z),
        ];
    }

    /**
     * Converts XYZ D65 color values to OK LAB.
     *
     * @param float $x The x value. (0, 1)
     * @param float $y The y value. (0, 1)
     * @param float $z The z value. (0, 1)
     * @return array{float, float, float} The OK LAB values.
     */
    public static function xyzD65ToOkLab(float $x, float $y, float $z): array
    {
        $cbrt = static fn(float $v): float => $v < 0 ?
            -pow(-$v, 1 / 3) :
            pow($v, 1 / 3);

        $l = (0.8190224379967030 * $x) + (0.3619062600528904 * $y) - (0.1288737815209879 * $z);
        $m = (0.0329836539323885 * $x) + (0.9292868615863434 * $y) + (0.0361446663506424 * $z);
        $s = (0.0481771893596242 * $x) + (0.2642395317527308 * $y) + (0.6335478284694309 * $z);

        $l = $cbrt($l);
        $m = $cbrt($m);
        $s = $cbrt($s);

        return [
            (0.2104542683093140 * $l) + (0.7936177747023054 * $m) - (0.0040720430116193 * $s),
            (1.9779985324311684 * $l) - (2.4285922420485799 * $m) + (0.4505937096174110 * $s),
            (0.0259040424655478 * $l) + (0.7827717124575296 * $m) - (0.8086757549230774 * $s),
        ];
    }

    /**
     * Converts XYZ D65 color values to Rec. 2020.
     *
     * @param float $x The x value. (0, 1)
     * @param float $y The y value. (0, 1)
     * @param float $z The z value. (0, 1)
     * @return array{float, float, float} The Rec. 2020 values.
     */
    public static function xyzD65ToRec2020(float $x, float $y, float $z): array
    {
        $r = (1.7166511879712676 * $x) - (0.3556707837763924 * $y) - (0.2533662813736598 * $z);
        $g = (-0.6666843518324890 * $x) + (1.6164812366349390 * $y) + (0.0157685458139111 * $z);
        $b = (0.0176398574453109 * $x) - (0.0427706132578087 * $y) + (0.9421031212354740 * $z);

        return [
            static::powSigned($r, 1 / 2.4),
            static::powSigned($g, 1 / 2.4),
            static::powSigned($b, 1 / 2.4),
        ];
    }

    /**
     * Converts XYZ D65 color values to SRGB Linear.
     *
     * @param float $x The x value. (0, 1)
     * @param float $y The y value. (0, 1)
     * @param float $z The z value. (0, 1)
     * @return array{float, float, float} The SRGB Linear values.
     */
    public static function xyzD65ToSrgbLinear(float $x, float $y, float $z): array
    {
        $r = (3.2409699419045213 * $x) - (1.5373831775700935 * $y) - (0.4986107602930033 * $z);
        $g = (-0.9692436362808798 * $x) + (1.8759675015077206 * $y) + (0.0415550574071756 * $z);
        $b = (0.0556300796969936 * $x) - (0.2039769588889766 * $y) + (1.0569715142428786 * $z);

        return [$r, $g, $b];
    }

    /**
     * Converts XYZ D65 color values to XYZ D50.
     *
     * @param float $x The x value. (0, 1)
     * @param float $y The y value. (0, 1)
     * @param float $z The z value. (0, 1)
     * @return array{float, float, float} The XYZ D50 values.
     */
    public static function xyzD65ToXyzD50(float $x, float $y, float $z): array
    {
        return [
            (1.0479297925449969 * $x) + (0.0229468706016097 * $y) - (0.0501922662892052 * $z),
            (0.0296278087700560 * $x) + (0.9904344267538799 * $y) - (0.0170737990634188 * $z),
            (-0.0092430406462045 * $x) + (0.0150551914902982 * $y) + (0.7518742814281371 * $z),
        ];
    }

    /**
     * Converts a linear SRGB channel to gamma-corrected form.
     *
     * @param float $value The channel value.
     * @return float The gamma-corrected channel value.
     */
    protected static function linearSrgbChannelToSrgb(float $value): float
    {
        $absolute = abs($value);
        $sign = $value < 0 ? -1 : 1;

        return $absolute <= 0.0031308 ?
            $value * 12.92 :
            $sign * ((1.055 * pow($absolute, 1 / 2.4)) - 0.055);
    }

    /**
     * Applies a sign-preserving power transform.
     *
     * @param float $value The value.
     * @param float $exponent The exponent.
     * @return float The transformed value.
     */
    protected static function powSigned(float $value, float $exponent): float
    {
        return $value < 0 ?
            -pow(-$value, $exponent) :
            pow($value, $exponent);
    }

    /**
     * Calculates the R, G or B value via hue interpolation.
     *
     * @param float $p The first value.
     * @param float $q The second value.
     * @param float $t The shifted hue value.
     * @return float The R, G or B value.
     */
    protected static function rgbHue(float $p, float $q, float $t): float
    {
        $t = fmod($t + 1, 1);

        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }

        if ($t < 1 / 2) {
            return $q;
        }

        if ($t < 2 / 3) {
            return $p + (($q - $p) * (2 / 3 - $t) * 6);
        }

        return $p;
    }

    /**
     * Converts a gamma-corrected SRGB channel to linear form.
     *
     * @param float $value The channel value.
     * @return float The linear channel value.
     */
    protected static function srgbChannelToLinear(float $value): float
    {
        $absolute = abs($value);
        $sign = $value < 0 ? -1 : 1;

        return $absolute <= 0.04045 ?
            $value / 12.92 :
            $sign * pow(($absolute + 0.055) / 1.055, 2.4);
    }
}
