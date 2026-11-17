<?php

namespace App\Actions\Profile;

use App\Models\User;
use App\Support\SquarePng;
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
        $png = SquarePng::fromString((string) file_get_contents($file->getRealPath()), self::SIZE);
        if ($png === null) {
            throw ValidationException::withMessages([
                'avatar' => 'The avatar could not be read as an image.',
            ]);
        }

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
}
