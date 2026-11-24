<?php
declare(strict_types=1);

namespace Fyre\Utility;

use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Core\Traits\StaticMacroTrait;
use Fyre\Utility\Color\Color;
use GdImage;
use InvalidArgumentException;
use RuntimeException;

use function assert;
use function base64_encode;
use function ceil;
use function clamp;
use function exif_read_data;
use function file_exists;
use function file_get_contents;
use function fmod;
use function function_exists;
use function imagealphablending;
use function imageavif;
use function imagecolorallocate;
use function imagecolorallocatealpha;
use function imagecolorat;
use function imagecolorsforindex;
use function imagecopy;
use function imagecopyresampled;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function imagecrop;
use function imagefill;
use function imagefilter;
use function imageflip;
use function imagegif;
use function imageistruecolor;
use function imagejpeg;
use function imagepalettetotruecolor;
use function imagepng;
use function imagerotate;
use function imagesavealpha;
use function imagescale;
use function imagesx;
use function imagesy;
use function imagewebp;
use function is_finite;
use function is_int;
use function is_string;
use function max;
use function min;
use function ob_end_clean;
use function ob_get_contents;
use function ob_start;
use function pathinfo;
use function round;
use function sprintf;
use function strtolower;

use const IMG_FILTER_BRIGHTNESS;
use const IMG_FILTER_CONTRAST;
use const IMG_FILTER_GAUSSIAN_BLUR;
use const IMG_FILTER_GRAYSCALE;
use const IMG_FILTER_MEAN_REMOVAL;
use const IMG_FILTER_PIXELATE;
use const IMG_FLIP_HORIZONTAL;
use const IMG_FLIP_VERTICAL;
use const PATHINFO_EXTENSION;

/**
 * Provides image manipulation utilities.
 *
 * @phpstan-consistent-constructor
 */
class Image
{
    use DebugTrait;
    use MacroTrait;
    use StaticMacroTrait;

    protected GdImage $image;

    protected int $orientation = 1;

    /**
     * Creates an Image from a file.
     *
     * @param string $filePath The image file path.
     * @return static The new Image instance.
     */
    public static function createFromFile(string $filePath): static
    {
        $data = @file_get_contents($filePath);

        if ($data === false) {
            throw new RuntimeException(sprintf(
                'Image file `%s` could not be read.',
                $filePath
            ));
        }

        $image = static::createFromString($data);
        $image->orientation = static::getOrientation($filePath);

        return $image;
    }

    /**
     * Creates an Image from a binary string.
     *
     * @param string $data The binary image data.
     * @return static The new Image instance.
     */
    public static function createFromString(string $data): static
    {
        $image = @imagecreatefromstring($data);

        if (!($image instanceof GdImage)) {
            throw new InvalidArgumentException('Image data is not valid.');
        }

        return new static($image);
    }

    /**
     * Constructs an Image.
     *
     * @param GdImage $image The GD image.
     */
    protected function __construct(GdImage $image)
    {
        $this->replaceImage($image);
    }

    /**
     * Applies a Gaussian blur to the image.
     *
     * @param int $passes The number of blur passes.
     * @return static The Image instance.
     */
    public function blur(int $passes = 1): static
    {
        if ($passes < 1) {
            throw new InvalidArgumentException('Blur passes must be greater than 0.');
        }

        for ($i = 0; $i < $passes; $i++) {
            $this->filter(IMG_FILTER_GAUSSIAN_BLUR);
        }

        return $this;
    }

    /**
     * Adjusts the image brightness.
     *
     * @param int $amount The brightness adjustment. (-255, 255)
     * @return static The Image instance.
     */
    public function brightness(int $amount): static
    {
        if ($amount < -255 || $amount > 255) {
            throw new InvalidArgumentException('Brightness must be between -255 and 255.');
        }

        return $this->filter(IMG_FILTER_BRIGHTNESS, $amount);
    }

    /**
     * Resizes the image to fit within the specified dimensions and fills the remaining area.
     *
     * @param int $width The target width.
     * @param int $height The target height.
     * @param Color|string $background The background color.
     * @return static The Image instance.
     */
    public function contain(int $width, int $height, Color|string $background = 'transparent'): static
    {
        $this->fit($width, $height);

        assert($width > 0 && $height > 0);

        $resizeWidth = $this->getWidth();
        $resizeHeight = $this->getHeight();

        if ($resizeWidth === $width && $resizeHeight === $height) {
            return $this;
        }

        $image = imagecreatetruecolor($width, $height);

        if (!($image instanceof GdImage)) {
            throw new RuntimeException('Image could not be contained.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, $this->allocateColor($background));
        imagealphablending($image, true);
        imagecopy(
            $image,
            $this->image,
            (int) (($width - $resizeWidth) / 2),
            (int) (($height - $resizeHeight) / 2),
            0,
            0,
            $resizeWidth,
            $resizeHeight
        );

        return $this->replaceImage($image);
    }

    /**
     * Adjusts the image contrast.
     *
     * @param int $amount The contrast adjustment. (-100, 100)
     * @return static The Image instance.
     */
    public function contrast(int $amount): static
    {
        if ($amount < -100 || $amount > 100) {
            throw new InvalidArgumentException('Contrast must be between -100 and 100.');
        }

        return $this->filter(IMG_FILTER_CONTRAST, -$amount);
    }

    /**
     * Resizes and crops the image to cover the specified dimensions.
     *
     * @param int $width The target width.
     * @param int $height The target height.
     * @return static The Image instance.
     */
    public function cover(int $width, int $height): static
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Image dimensions must be greater than 0.');
        }

        $sourceWidth = $this->getWidth();
        $sourceHeight = $this->getHeight();
        $scale = max($width / $sourceWidth, $height / $sourceHeight);

        $resizeWidth = (int) ceil($sourceWidth * $scale);
        $resizeHeight = (int) ceil($sourceHeight * $scale);

        $this->resize($resizeWidth, $resizeHeight);

        if ($resizeWidth === $width && $resizeHeight === $height) {
            return $this;
        }

        return $this->crop(
            (int) (($resizeWidth - $width) / 2),
            (int) (($resizeHeight - $height) / 2),
            $width,
            $height
        );
    }

    /**
     * Crops the image.
     *
     * @param int $x The horizontal offset.
     * @param int $y The vertical offset.
     * @param int $width The crop width.
     * @param int $height The crop height.
     * @return static The Image instance.
     */
    public function crop(int $x, int $y, int $width, int $height): static
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Image dimensions must be greater than 0.');
        }

        if (
            $x < 0 ||
            $y < 0 ||
            $x + $width > $this->getWidth() ||
            $y + $height > $this->getHeight()
        ) {
            throw new InvalidArgumentException('Crop area must be within the image bounds.');
        }

        $image = imagecrop($this->image, [
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
        ]);

        if (!($image instanceof GdImage)) {
            throw new RuntimeException('Image could not be cropped.');
        }

        return $this->replaceImage($image);
    }

    /**
     * Returns the dominant color of the image.
     *
     * @return string The hexadecimal color string.
     */
    public function dominantColor(): string
    {
        $sample = imagecreatetruecolor(1, 1);

        if (!($sample instanceof GdImage)) {
            throw new RuntimeException('Image could not be sampled.');
        }

        imagealphablending($sample, false);
        imagesavealpha($sample, true);

        imagecopyresampled(
            $sample,
            $this->image,
            0,
            0,
            0,
            0,
            1,
            1,
            $this->getWidth(),
            $this->getHeight()
        );

        $identifier = imagecolorat($sample, 0, 0);

        if ($identifier === false) {
            throw new RuntimeException('Image color could not be read.');
        }

        $color = imagecolorsforindex($sample, $identifier);

        return Color::createFromRgb($color['red'], $color['green'], $color['blue'])
            ->toHex()
            ->toString(shortenHex: false);
    }

    /**
     * Resizes the image to fit within the specified dimensions.
     *
     * @param int $width The maximum width.
     * @param int $height The maximum height.
     * @return static The Image instance.
     */
    public function fit(int $width, int $height): static
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Image dimensions must be greater than 0.');
        }

        $sourceWidth = $this->getWidth();
        $sourceHeight = $this->getHeight();
        $scale = min($width / $sourceWidth, $height / $sourceHeight);

        return $this->resize(
            max(1, (int) round($sourceWidth * $scale)),
            max(1, (int) round($sourceHeight * $scale))
        );
    }

    /**
     * Flips the image horizontally.
     *
     * @return static The Image instance.
     */
    public function flipHorizontal(): static
    {
        imageflip($this->image, IMG_FLIP_HORIZONTAL);

        return $this;
    }

    /**
     * Flips the image vertically.
     *
     * @return static The Image instance.
     */
    public function flipVertical(): static
    {
        imageflip($this->image, IMG_FLIP_VERTICAL);

        return $this;
    }

    /**
     * Returns the image height.
     *
     * @return int The image height.
     */
    public function getHeight(): int
    {
        return imagesy($this->image);
    }

    /**
     * Returns the image width.
     *
     * @return int The image width.
     */
    public function getWidth(): int
    {
        return imagesx($this->image);
    }

    /**
     * Converts the image to grayscale.
     *
     * @return static The Image instance.
     */
    public function grayscale(): static
    {
        return $this->filter(IMG_FILTER_GRAYSCALE);
    }

    /**
     * Normalizes the image using its EXIF orientation.
     *
     * @return static The Image instance.
     */
    public function orient(): static
    {
        switch ($this->orientation) {
            case 2:
                $this->flipHorizontal();
                break;
            case 3:
                $this->rotate(180);
                break;
            case 4:
                $this->flipVertical();
                break;
            case 5:
                $this->rotate(90)->flipHorizontal();
                break;
            case 6:
                $this->rotate(90);
                break;
            case 7:
                $this->rotate(270)->flipHorizontal();
                break;
            case 8:
                $this->rotate(270);
                break;
        }

        $this->orientation = 1;

        return $this;
    }

    /**
     * Applies a pixelation effect to the image.
     *
     * @param int $size The pixel block size.
     * @return static The Image instance.
     */
    public function pixelate(int $size): static
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Pixel size must be greater than 0.');
        }

        return $this->filter(IMG_FILTER_PIXELATE, $size, true);
    }

    /**
     * Resizes the image to the specified dimensions.
     *
     * @param int $width The target width.
     * @param int $height The target height.
     * @return static The Image instance.
     */
    public function resize(int $width, int $height): static
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Image dimensions must be greater than 0.');
        }

        if ($width === $this->getWidth() && $height === $this->getHeight()) {
            return $this;
        }

        $image = imagescale($this->image, $width, $height);

        if (!($image instanceof GdImage)) {
            throw new RuntimeException('Image could not be resized.');
        }

        return $this->replaceImage($image);
    }

    /**
     * Rotates the image clockwise.
     *
     * @param float $degrees The rotation in degrees.
     * @param Color|string $background The background color.
     * @return static The Image instance.
     */
    public function rotate(float $degrees, Color|string $background = 'transparent'): static
    {
        if (!is_finite($degrees)) {
            throw new InvalidArgumentException('Rotation must be a finite number.');
        }

        $degrees = fmod($degrees, 360);

        if ($degrees === 0.0) {
            return $this;
        }

        $color = $this->allocateColor($background);
        $image = imagerotate($this->image, -$degrees, $color);

        if (!($image instanceof GdImage)) {
            throw new RuntimeException('Image could not be rotated.');
        }

        return $this->replaceImage($image);
    }

    /**
     * Saves the image to a file.
     *
     * @param string $filePath The output file path.
     * @param int $quality The output quality. (0, 100)
     * @param bool $overwrite Whether to overwrite an existing file.
     */
    public function save(string $filePath, int $quality = 90, bool $overwrite = false): void
    {
        if (!$filePath) {
            throw new InvalidArgumentException('Image file path is required.');
        }

        if (!$overwrite && file_exists($filePath)) {
            throw new RuntimeException(sprintf(
                'File `%s` already exists.',
                $filePath
            ));
        }

        if ($quality < 0 || $quality > 100) {
            throw new InvalidArgumentException('Image quality must be between 0 and 100.');
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        if (!$extension) {
            throw new InvalidArgumentException('Image file extension is required.');
        }

        $format = $this->normalizeFormat($extension);

        if (!$this->encode($format, $filePath, $quality)) {
            throw new RuntimeException(sprintf(
                'Image file `%s` could not be saved.',
                $filePath
            ));
        }
    }

    /**
     * Sharpens the image.
     *
     * @return static The Image instance.
     */
    public function sharpen(): static
    {
        return $this->filter(IMG_FILTER_MEAN_REMOVAL);
    }

    /**
     * Returns the Base64 encoded image data.
     *
     * @param string $format The output format.
     * @param int $quality The output quality. (0, 100)
     * @return string The Base64 encoded image data.
     */
    public function toBase64(string $format = 'png', int $quality = 90): string
    {
        return $this->toBinary($format, $quality) |> base64_encode(...);
    }

    /**
     * Returns the encoded image data.
     *
     * @param string $format The output format.
     * @param int $quality The output quality. (0, 100)
     * @return string The encoded image data.
     */
    public function toBinary(string $format = 'png', int $quality = 90): string
    {
        $format = $this->normalizeFormat($format);

        if ($quality < 0 || $quality > 100) {
            throw new InvalidArgumentException('Image quality must be between 0 and 100.');
        }

        if (!ob_start()) {
            throw new RuntimeException('Output buffering could not be started.');
        }

        try {
            if (!$this->encode($format, null, $quality)) {
                throw new RuntimeException(sprintf(
                    'Image could not be encoded as `%s`.',
                    $format
                ));
            }

            $data = ob_get_contents();

            if ($data === false) {
                throw new RuntimeException('Encoded image data could not be read.');
            }
        } finally {
            ob_end_clean();
        }

        return $data;
    }

    /**
     * Returns the image as a data URI.
     *
     * @param string $format The output format.
     * @param int $quality The output quality. (0, 100)
     * @return string The image data URI.
     */
    public function toDataUri(string $format = 'png', int $quality = 90): string
    {
        $format = $this->normalizeFormat($format);

        return 'data:image/'.$format.';base64,'.$this->toBase64($format, $quality);
    }

    /**
     * Allocates a color for a GD image.
     *
     * @param Color|string $color The color.
     * @return int The allocated color identifier.
     */
    protected function allocateColor(Color|string $color): int
    {
        if (is_string($color)) {
            $color = Color::createFromString($color);
        }

        $color = $color->toRgb();

        $red = $color->getRed() |> static::normalizeChannel(...);
        $green = $color->getGreen() |> static::normalizeChannel(...);
        $blue = $color->getBlue() |> static::normalizeChannel(...);
        $alpha = static::normalizeChannel((1 - $color->getAlpha()) * 127, 127);

        assert($red >= 0 && $red <= 255);
        assert($green >= 0 && $green <= 255);
        assert($blue >= 0 && $blue <= 255);
        assert($alpha >= 0 && $alpha <= 127);

        $identifier = imagecolorallocatealpha($this->image, $red, $green, $blue, $alpha);

        if ($identifier === false) {
            throw new RuntimeException('Image color could not be allocated.');
        }

        return $identifier;
    }

    /**
     * Encodes the image.
     *
     * @param string $format The output format.
     * @param string|null $filePath The output file path.
     * @param int $quality The output quality.
     * @return bool Whether the image was encoded.
     */
    protected function encode(string $format, string|null $filePath, int $quality): bool
    {
        $image = $format === 'jpeg' ? $this->flatten() : $this->image;

        return function_exists('image'.$format) && match ($format) {
            'avif' => @imageavif($image, $filePath, $quality),
            'gif' => @imagegif($image, $filePath),
            'jpeg' => @imagejpeg($image, $filePath, $quality),
            'png' => @imagepng($image, $filePath, 9 - (int) round($quality * 9 / 100)),
            'webp' => @imagewebp($image, $filePath, $quality),
            default => false,
        };
    }

    /**
     * Applies an image filter.
     *
     * @param int $filter The filter type.
     * @param mixed ...$arguments The filter arguments.
     * @return static The Image instance.
     */
    protected function filter(int $filter, mixed ...$arguments): static
    {
        if (!imagefilter($this->image, $filter, ...$arguments)) {
            throw new RuntimeException('Image filter could not be applied.');
        }

        return $this;
    }

    /**
     * Flattens the image onto a white background.
     *
     * @return GdImage The flattened image.
     */
    protected function flatten(): GdImage
    {
        $width = imagesx($this->image);
        $height = imagesy($this->image);
        $image = imagecreatetruecolor($width, $height);

        if (!($image instanceof GdImage)) {
            throw new RuntimeException('Image could not be flattened.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);

        if ($white === false) {
            throw new RuntimeException('Image color could not be allocated.');
        }

        imagefill($image, 0, 0, $white);
        imagecopy(
            $image,
            $this->image,
            0,
            0,
            0,
            0,
            $width,
            $height
        );

        return $image;
    }

    /**
     * Normalizes an image format.
     *
     * @param string $format The image format.
     * @return string The normalized image format.
     */
    protected function normalizeFormat(string $format): string
    {
        return match (strtolower($format)) {
            'avif' => 'avif',
            'gif' => 'gif',
            'jpeg', 'jpg' => 'jpeg',
            'png' => 'png',
            'webp' => 'webp',
            default => throw new InvalidArgumentException(sprintf(
                'Image format `%s` is not supported.',
                $format
            )),
        };
    }

    /**
     * Replaces the underlying GD image.
     *
     * @param GdImage $image The GD image.
     * @return static The Image instance.
     */
    protected function replaceImage(GdImage $image): static
    {
        if (!imageistruecolor($image) && !imagepalettetotruecolor($image)) {
            throw new RuntimeException('Image could not be converted to true color.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $this->image = $image;

        return $this;
    }

    /**
     * Returns the EXIF orientation for an image file.
     *
     * @param string $filePath The image file path.
     * @return int The EXIF orientation.
     */
    protected static function getOrientation(string $filePath): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($filePath, 'IFD0');

        if ($exif === false) {
            return 1;
        }

        $orientation = $exif['Orientation'] ?? null;

        if (!is_int($orientation) || $orientation < 1 || $orientation > 8) {
            return 1;
        }

        return $orientation;
    }

    /**
     * Normalizes a GD color channel.
     *
     * @param float $value The channel value.
     * @param int $max The maximum value.
     * @return int The normalized channel value.
     */
    protected static function normalizeChannel(float $value, int $max = 255): int
    {
        return clamp((int) round($value), 0, $max);
    }
}
