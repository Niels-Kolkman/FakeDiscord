<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * The messages shown when a channel opens: the newest LIMIT, oldest first.
 * Loading older ones while scrolling up is ISSUE-15; until then $hasOlder
 * tells the view whether the "start of the channel" header belongs on top.
 */
final readonly class MessageFeed
{
    public const LIMIT = 50;

    /**
     * @param  Collection<int, Message>  $messages
     */
    public function __construct(
        public Collection $messages,
        public bool $hasOlder,
    ) {}

    public static function empty(): self
    {
        return new self(collect(), false);
    }

    public static function for(?Channel $channel, int $limit = self::LIMIT): self
    {
        if ($channel === null || $channel->isVoice()) {
            return self::empty();
        }

        $newest = $channel->messages()
            ->with('author')
            ->latest('id')
            ->limit($limit + 1)
            ->get();

        return new self(
            $newest->take($limit)->reverse()->values(),
            $newest->count() > $limit,
        );
    }
}
