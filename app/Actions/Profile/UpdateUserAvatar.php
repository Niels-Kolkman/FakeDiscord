<?php

namespace App\Actions\Profile;

use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores an uploaded avatar: centre-crops it to a square, resizes it to
 * SIZE x SIZE with GD and saves it as PNG on the avatar disk. The previous
 * avatar file is deleted once the new one is saved.
 */
class UpdateUserAvatar
{
    /** Width and height of stored avatars, in pixels. */
    public const SIZE = 256;

    public static function disk(): string
    {
        return config()->string('filesystems.avatar_disk', 'public');
    }

    /**
     * @throws ValidationException when GD cannot decode the image
     */
    public function update(User $user, UploadedFile $file): void
    {
        $png = $this->resize((string) file_get_contents($file->getRealPath()));

        $path = 'avatars/'.$user->getKey().'/'.Str::random(40).'.png';
        Storage::disk(self::disk())->put($path, $png, 'public');

        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => $path])->save();

        if ($previous !== null && $previous !== $path) {
            Storage::disk(self::disk())->delete($previous);
        }
    }

    public function remove(User $user): void
    {
        if ($user->avatar_path === null) {
            return;
        }

        Storage::disk(self::disk())->delete($user->avatar_path);
        $user->forceFill(['avatar_path' => null])->save();
    }

    /**
     * @throws ValidationException
     */
    private function resize(string $contents): string
    {
        $source = $contents === '' ? false : @imagecreatefromstring($contents);
        if (! $source instanceof GdImage) {
            throw ValidationException::withMessages([
                'avatar' => 'The avatar could not be read as an image.',
            ]);
        }

        $source = $this->applyExifOrientation($source, $contents);

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        imagecopyresampled(
            $canvas, $source,
            0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            self::SIZE, self::SIZE,
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
    private function applyExifOrientation(GdImage $image, string $contents): GdImage
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
