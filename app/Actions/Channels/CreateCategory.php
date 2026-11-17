<?php

namespace App\Actions\Channels;

use App\Models\ChannelCategory;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a category below the existing ones.
 */
class CreateCategory
{
    /**
     * @throws ValidationException when the server already has the maximum number of categories
     */
    public function create(Server $server, string $name): ChannelCategory
    {
        return DB::transaction(function () use ($server, $name): ChannelCategory {
            if ($server->categories()->count() >= ChannelCategory::MAX_PER_SERVER) {
                throw ValidationException::withMessages([
                    'name' => 'A server can have at most '.ChannelCategory::MAX_PER_SERVER.' categories.',
                ]);
            }

            $max = $server->categories()->getQuery()->max('position');

            return $server->categories()->create([
                'name' => $name,
                'position' => $max === null ? 0 : (int) $max + 1,
            ]);
        });
    }
}
