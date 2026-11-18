<?php

namespace App\Policies;

use App\Actions\Conversations\OpenDirectConversation;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Discovered automatically for App\Models\Conversation (ISSUE-23).
 *
 * Only the participants see a conversation; anyone else gets a 404, so
 * conversation ids do not tell anyone who talks to whom.
 *
 * Group DMs (ISSUE-24), like Discord: every participant may write, add
 * their friends and rename the group; only the owner removes other
 * people; anyone may leave. A 1:1 DM has none of this (a 404).
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): Response
    {
        return $conversation->hasParticipant($user) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Participants of a group DM, or of a 1:1 DM while the other person
     * still has an account and neither has blocked the other.
     */
    public function sendMessage(User $user, Conversation $conversation): Response
    {
        $view = $this->view($user, $conversation);
        if ($view->denied() || $conversation->isGroup()) {
            return $view;
        }

        if (! $conversation->isDirect()) {
            return Response::deny(__('You cannot send messages here.'));
        }

        $other = $conversation->otherParticipant($user);

        if ($other === null || OpenDirectConversation::blocked($user, $other)) {
            return Response::deny(__('You cannot send messages to this user.'));
        }

        return Response::allow();
    }

    /**
     * Renaming a group DM: any participant.
     */
    public function update(User $user, Conversation $conversation): Response
    {
        return $this->groupParticipant($user, $conversation);
    }

    /**
     * Adding friends to a group DM: any participant. The seat limit and the
     * friends-only rule are checked by ManageGroupConversation::add().
     */
    public function addParticipants(User $user, Conversation $conversation): Response
    {
        return $this->groupParticipant($user, $conversation);
    }

    /**
     * Taking $target out of a group DM: yourself (leaving), or anyone when
     * you own the group.
     */
    public function removeParticipant(User $user, Conversation $conversation, User $target): Response
    {
        $participant = $this->groupParticipant($user, $conversation);
        if ($participant->denied()) {
            return $participant;
        }

        return $target->is($user) || $conversation->isOwnedBy($user)
            ? Response::allow()
            : Response::deny(__('Only the group owner can remove people.'));
    }

    private function groupParticipant(User $user, Conversation $conversation): Response
    {
        $view = $this->view($user, $conversation);
        if ($view->denied()) {
            return $view;
        }

        return $conversation->isGroup() ? Response::allow() : Response::denyAsNotFound();
    }
}
