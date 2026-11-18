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
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): Response
    {
        return $conversation->hasParticipant($user) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Participants of a 1:1 DM, while the other person still has an
     * account and neither has blocked the other. Group DMs are ISSUE-24.
     */
    public function sendMessage(User $user, Conversation $conversation): Response
    {
        $view = $this->view($user, $conversation);
        if ($view->denied()) {
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
}
