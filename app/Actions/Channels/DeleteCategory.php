<?php

namespace App\Actions\Channels;

use App\Models\ChannelCategory;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a category but keeps its channels, like Discord: they move, in
 * their current order, to the bottom of the uncategorised list.
 */
class DeleteCategory
{
    public function delete(ChannelCategory $category): void
    {
        DB::transaction(function () use ($category): void {
            $server = $category->server;
            $position = CreateChannel::nextPosition($server, null);

            foreach ($category->channels()->get() as $channel) {
                $channel->update(['category_id' => null, 'position' => $position++]);
            }

            $category->delete();
        });
    }
}
