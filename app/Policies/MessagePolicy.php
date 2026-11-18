<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

/**
 * Discovered automatically for App\Models\Message (ISSUE-16).
 *
 * Channel messages need the channel to be visible to the user
 * (ChannelPolicy::view, which needs membership and View Channels, ISSUE-27):
 * someone who left keeps their messages but can no longer change them.
 * Moderating needs ChannelPolicy::manageMessages.
 *
 * Direct messages (ISSUE-23) need a seat in the conversation instead; there
 * are no moderators there, so only the author edits and deletes, and nobody
 * pins (yet).
 */
class MessagePolicy
{
    /**
     * Like Discord, only the author can edit a message; moderators cannot.
     */
    public function update(User $user, Message $message): bool
    {
        return $this->isAuthor($user, $message) && $this->canSee($user, $message);
    }

    /**
     * The author, or a moderator of the channel.
     */
    public function delete(User $user, Message $message): bool
    {
        if (! $this->canSee($user, $message)) {
            return false;
        }

        return $this->isAuthor($user, $message) || $this->canModerate($user, $message);
    }

    /**
     * Join an existing reaction or take your own back (ISSUE-18), on
     * anyone's message. Starting a new one is addReaction().
     */
    public function react(User $user, Message $message): bool
    {
        return $this->canSee($user, $message);
    }

    /**
     * Add $emoji to the message. Like Discord, a new emoji needs Add
     * Reactions (ChannelPolicy::addReactions); an emoji someone already
     * reacted with can be joined by anyone who can react. DMs have no
     * permissions: every participant may react.
     */
    public function addReaction(User $user, Message $message, string $emoji): bool
    {
        if (! $this->react($user, $message)) {
            return false;
        }

        $channel = $message->channel;
        if ($channel === null || $user->can('addReactions', $channel)) {
            return true;
        }

        return $message->reactions()->where('emoji', $emoji)->exists();
    }

    /**
     * Pinning and unpinning (ISSUE-20) needs Manage Messages, on anyone's
     * message. Not in DMs.
     */
    public function pin(User $user, Message $message): bool
    {
        return $message->channel !== null && $this->canModerate($user, $message);
    }

    private function isAuthor(User $user, Message $message): bool
    {
        return $message->user_id !== null && (int) $message->user_id === (int) $user->id;
    }

    /**
     * A user who can see the channel, or a participant of the conversation
     * the message was sent in.
     */
    private function canSee(User $user, Message $message): bool
    {
        if ($message->conversation_id !== null) {
            return $message->conversation?->hasParticipant($user) ?? false;
        }

        $channel = $message->channel;

        return $channel !== null && $user->can('view', $channel);
    }

    private function canModerate(User $user, Message $message): bool
    {
        $channel = $message->channel;

        return $channel !== null && $user->can('manageMessages', $channel);
    }
}
