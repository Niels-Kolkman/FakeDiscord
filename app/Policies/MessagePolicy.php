<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

/**
 * Discovered automatically for App\Models\Message (ISSUE-16).
 *
 * Channel messages need membership of the server: someone who left keeps
 * their messages but can no longer change them. Direct messages (ISSUE-23)
 * need a seat in the conversation instead; there are no moderators there,
 * so only the author edits and deletes, and nobody pins (yet).
 */
class MessagePolicy
{
    /**
     * Like Discord, only the author can edit a message; moderators cannot.
     */
    public function update(User $user, Message $message): bool
    {
        return $this->isAuthor($user, $message) && $this->isMember($user, $message);
    }

    /**
     * The author, or a moderator (ServerPolicy::manageMessages).
     */
    public function delete(User $user, Message $message): bool
    {
        if (! $this->isMember($user, $message)) {
            return false;
        }

        $server = $message->channel?->server;

        return $this->isAuthor($user, $message)
            || ($server !== null && $user->can('manageMessages', $server));
    }

    /**
     * Any member of the server may add and remove their own reactions
     * (ISSUE-18), on anyone's message.
     */
    public function react(User $user, Message $message): bool
    {
        return $this->isMember($user, $message);
    }

    /**
     * Pinning and unpinning (ISSUE-20) needs Discord's "Manage Messages"
     * (ServerPolicy::manageMessages), on anyone's message.
     */
    public function pin(User $user, Message $message): bool
    {
        $server = $message->channel?->server;

        return $server !== null
            && $this->isMember($user, $message)
            && $user->can('manageMessages', $server);
    }

    private function isAuthor(User $user, Message $message): bool
    {
        return $message->user_id !== null && (int) $message->user_id === (int) $user->id;
    }

    /**
     * A member of the channel's server, or a participant of the
     * conversation the message was sent in.
     */
    private function isMember(User $user, Message $message): bool
    {
        if ($message->conversation_id !== null) {
            return $message->conversation?->hasParticipant($user) ?? false;
        }

        return $message->channel?->server?->hasMember($user) ?? false;
    }
}
