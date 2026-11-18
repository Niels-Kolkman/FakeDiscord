<?php

namespace App\Http\Controllers;

use App\Actions\Messages\AddReaction;
use App\Actions\Messages\RemoveReaction;
use App\Http\Requests\ReactionRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Reactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Add and remove emoji reactions on a DM message (ISSUE-23), like
 * ReactionController does for channels. Both participants may react
 * (MessagePolicy::react); ReactionsUpdated goes out on "conversation.{id}".
 *
 * Scoped bindings make a message of another conversation, or a deleted
 * one, a 404.
 */
class ConversationReactionController extends Controller
{
    public function store(ReactionRequest $request, Conversation $conversation, Message $message, AddReaction $reactions): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $reactions->add($user, $message, $request->emoji());

        return $this->respond($request, $user, $conversation, $message);
    }

    public function destroy(ReactionRequest $request, Conversation $conversation, Message $message, RemoveReaction $reactions): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $reactions->remove($user, $message, $request->emoji());

        return $this->respond($request, $user, $conversation, $message);
    }

    private function respond(ReactionRequest $request, User $user, Conversation $conversation, Message $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['reactions' => Reactions::groups($message, (int) $user->id)]);
        }

        return redirect()->route('dms.show', $conversation);
    }
}
