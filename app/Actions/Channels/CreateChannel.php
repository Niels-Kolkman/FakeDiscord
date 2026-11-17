<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a text channel at the bottom of its category (or of the
 * uncategorised list).
 */
class CreateChannel
{
    /**
     * @throws ValidationException when the server already has the maximum number of channels
     */
    public function create(Server $server, string $name, ?string $topic = null, ?int $categoryId = null): Channel
    {
        return DB::transaction(function () use ($server, $name, $topic, $categoryId): Channel {
            if ($server->channels()->count() >= Channel::MAX_PER_SERVER) {
                throw ValidationException::withMessages([
                    'name' => 'A server can have at most '.Channel::MAX_PER_SERVER.' channels.',
                ]);
            }

            return $server->channels()->create([
                'name' => $name,
                'type' => Channel::TYPE_TEXT,
                'topic' => $topic,
                'category_id' => $categoryId,
                'position' => self::nextPosition($server, $categoryId),
            ]);
        });
    }

    /**
     * One past the highest position in the group, so new or moved channels go to the bottom.
     */
    public static function nextPosition(Server $server, ?int $categoryId): int
    {
        $max = $server->channels()->getQuery()
            ->where('category_id', $categoryId)
            ->max('position');

        return $max === null ? 0 : (int) $max + 1;
    }
}
