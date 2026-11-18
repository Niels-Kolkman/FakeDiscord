<?php

namespace App\Http\Controllers;

use App\Actions\Messages\DeleteMessage;
use App\Actions\Messages\EditMessage;
use App\Actions\Messages\SendMessage;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\UpdateMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\MessageFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Read, post, edit and delete messages in a 1:1 DM (ISSUE-23). The same
 * JSON and the same actions as MessageController, so
 * resources/js/channel-messages.js drives a DM like a channel.
 *
 * Participants read (ConversationPolicy::view, a 404 for anyone else) and
 * post (ConversationPolicy::sendMessage: not across a block); editing and
 * deleting follow MessagePolicy (the author only, there are no moderators
 * in a DM). Scoped bindings make a message of another conversation, or a
 * deleted one, a 404.
 */
class ConversationMessageController extends Controller
{
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $before = null;
        if ($request->has(MessageFeed::CURSOR)) {
            $before = MessageFeed::decodeCursor($request->query(MessageFeed::CURSOR));

            if ($before === null) {
                throw ValidationException::withMessages([
                    MessageFeed::CURSOR => __('The cursor is invalid.'),
                ]);
            }
        }

        /** @var User $user */
        $user = $request->user();

        return response()->json(MessageFeed::for($conversation, before: $before)->toPayload($user));
    }

    public function store(StoreMessageRequest $request, Conversation $conversation, SendMessage $messages): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $message = $messages->send($user, $conversation, $request->content(), $request->attachments(), $request->replyTo());

        if ($request->expectsJson()) {
            return response()->json(['message' => $message->toPayload()], 201);
        }

        return redirect()->route('dms.show', $conversation);
    }

    public function update(UpdateMessageRequest $request, Conversation $conversation, Message $message, EditMessage $messages): JsonResponse|RedirectResponse
    {
        $message = $messages->edit($message, $request->content());

        if ($request->expectsJson()) {
            /** @var User $user */
            $user = $request->user();

            return response()->json(['message' => $message->toPayload($user)]);
        }

        return redirect()->route('dms.show', $conversation);
    }

    public function destroy(Request $request, Conversation $conversation, Message $message, DeleteMessage $messages): Response|RedirectResponse
    {
        Gate::authorize('delete', $message);

        $messages->delete($message);

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect()->route('dms.show', $conversation);
    }
}
