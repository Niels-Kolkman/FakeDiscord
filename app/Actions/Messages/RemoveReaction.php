<?php

namespace App\Actions\Messages;

use App\Events\ReactionsUpdated;
use App\Models\Message;
use App\Models\User;
use App\Support\Reactions;

/**
 * Take back your own reaction and broadcast the new counts to the others
 * viewing the channel. Removing a reaction you do not have changes
 * nothing and broadcasts nothing.
 */
class RemoveReaction
{
    /**
     * @return bool whether a reaction was removed
     */
    public function remove(User $user, Message $message, string $emoji): bool
    {
        $deleted = $message->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->delete();

        $message->load(Reactions::relations());

        if ($deleted === 0) {
            return false;
        }

        // Removed either way; if Reverb is down, report it and carry on.
        rescue(function () use ($message, $user, $emoji): void {
            broadcast(new ReactionsUpdated($message, (int) $user->id, $emoji, added: false))->toOthers();
        });

        return true;
    }
}
