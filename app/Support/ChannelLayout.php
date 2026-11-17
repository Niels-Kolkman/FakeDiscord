<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\ChannelCategory;
use Illuminate\Database\Eloquent\Collection;

/**
 * A server's channel sidebar in display order (built by Server::channelLayout()).
 * Each category has its `channels` relation set.
 */
final readonly class ChannelLayout
{
    /**
     * @param  Collection<int, Channel>  $uncategorized
     * @param  Collection<int, ChannelCategory>  $categories
     */
    public function __construct(
        public Collection $uncategorized,
        public Collection $categories,
    ) {}

    /**
     * Every channel in sidebar order.
     *
     * @return \Illuminate\Support\Collection<int, Channel>
     */
    public function channels(): \Illuminate\Support\Collection
    {
        return $this->uncategorized->toBase()->concat(
            $this->categories->flatMap(fn (ChannelCategory $category) => $category->channels)
        )->values();
    }

    /**
     * The channel a server opens on: the first one that is not a voice channel.
     */
    public function defaultChannel(): ?Channel
    {
        return $this->channels()->first(fn (Channel $channel): bool => ! $channel->isVoice());
    }
}
