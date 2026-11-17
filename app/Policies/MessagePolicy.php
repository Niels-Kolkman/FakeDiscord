<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

/**
 * Discovered automatically for App\Models\Message (ISSUE-16).
 *
 * Only channel messages for now; DMs have no routes yet, so anything else
 * is denied. Authors must still be a member of the server: someone who
 * left keeps their messages but can no longer change them.
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

    private function isAuthor(User $user, Message $message): bool
    {
        return $message->user_id !== null && (int) $message->user_id === (int) $user->id;
    }

    private function isMember(User $user, Message $message): bool
    {
        return $message->channel?->server?->hasMember($user) ?? false;
    }
}
