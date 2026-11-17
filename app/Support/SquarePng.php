<?php

namespace App\Support;

use GdImage;

/**
 * Turns an uploaded image into a square PNG with GD: rotates it upright
 * (EXIF), centre-crops it to a square and resizes it to $size x $size,
 * keeping transparency. Used for user avatars and server icons.
 */
class SquarePng
{
    /**
     * @return string|null the PNG bytes, or null when GD cannot decode the image
     */
    public static function fromString(string $contents, int $size): ?string
    {
        $source = $contents === '' ? false : @imagecreatefromstring($contents);
        if (! $source instanceof GdImage) {
            return null;
        }

        $source = self::applyExifOrientation($source, $contents);

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        imagecopyresampled(
            $canvas, $source,
            0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            $size, $size,
            $side, $side,
        );

        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }

    /**
     * Phone photos are often stored sideways with an EXIF orientation flag;
     * rotate them upright before cropping. No-op without the exif extension.
     */
    private static function applyExifOrientation(GdImage $image, string $contents): GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($contents, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contents));
        $angle = match (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
