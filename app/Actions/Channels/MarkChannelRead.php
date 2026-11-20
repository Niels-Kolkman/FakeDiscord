<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\ChannelRead;
use App\Models\User;
use App\Support\NotificationInbox;

/**
 * Moves a user's read marker in a channel forward. Opening a
 * channel marks everything in it as read; while it stays open,
 * resources/js/unread.js marks the messages that arrive live
 * (ChannelReadController).
 *
 * The marker never moves back, and never past the newest message of the
 * channel, so a made-up id cannot hide messages that are still to come.
 * Authorisation is the caller's job (ChannelPolicy::view).
 *
 * Mention notifications of the channel up to the marker are
 * marked read with it: the user has seen those messages.
 */
class MarkChannelRead
{
    public function markRead(User $user, Channel $channel, ?int $upTo = null): int
    {
        // Deleted messages count too: a marker on one is still a position.
        $latest = (int) $channel->messages()->withTrashed()->max('id');
        $target = $upTo === null ? $latest : max(0, min($upTo, $latest));

        $read = ChannelRead::query()->createOrFirst(
            ['user_id' => $user->id, 'channel_id' => $channel->id],
            ['last_read_message_id' => $target],
        );

        if ($read->last_read_message_id < $target) {
            // A conditional update, so two tabs cannot move it back.
            ChannelRead::query()
                ->whereKey($read->id)
                ->where('last_read_message_id', '<', $target)
                ->update(['last_read_message_id' => $target, 'updated_at' => now()]);
        }

        $marker = max($target, $read->last_read_message_id);

        // Seen in the channel, so read in the inbox too.
        NotificationInbox::markChannelRead($user, $channel, $marker);

        return $marker;
    }
}
