<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use Illuminate\Support\Facades\DB;

/**
 * Rename a channel, change its topic or move it to another category.
 */
class UpdateChannel
{
    public function update(Channel $channel, string $name, ?string $topic, ?int $categoryId): Channel
    {
        return DB::transaction(function () use ($channel, $name, $topic, $categoryId): Channel {
            $attributes = ['name' => $name, 'category_id' => $categoryId];

            // Voice channels have no topic.
            if (! $channel->isVoice()) {
                $attributes['topic'] = $topic;
            }

            // A channel moved to another category goes to the bottom of it.
            if ($categoryId !== $channel->category_id) {
                $attributes['position'] = CreateChannel::nextPosition($channel->server, $categoryId);
            }

            $channel->update($attributes);

            return $channel;
        });
    }
}
