<?php

namespace App\Actions\Servers;

use App\Models\Server;
use App\Support\SquarePng;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores an uploaded server icon as a SIZE x SIZE PNG on the server icon disk
 * and deletes the previous icon file.
 */
class UpdateServerIcon
{
    /** Width and height of stored icons, in pixels. */
    public const SIZE = 256;

    public static function disk(): string
    {
        return config()->string('filesystems.server_icon_disk', 'public');
    }

    /**
     * @throws ValidationException when GD cannot decode the image
     */
    public function update(Server $server, UploadedFile $file): void
    {
        $png = SquarePng::fromString((string) file_get_contents($file->getRealPath()), self::SIZE);
        if ($png === null) {
            throw ValidationException::withMessages([
                'icon' => 'The icon could not be read as an image.',
            ]);
        }

        $path = 'server-icons/'.$server->getKey().'/'.Str::random(40).'.png';
        Storage::disk(self::disk())->put($path, $png, 'public');

        $previous = $server->icon_path;
        $server->forceFill(['icon_path' => $path])->save();

        if ($previous !== null && $previous !== $path) {
            Storage::disk(self::disk())->delete($previous);
        }
    }

    public function remove(Server $server): void
    {
        if ($server->icon_path === null) {
            return;
        }

        Storage::disk(self::disk())->delete($server->icon_path);
        $server->forceFill(['icon_path' => null])->save();
    }
}
