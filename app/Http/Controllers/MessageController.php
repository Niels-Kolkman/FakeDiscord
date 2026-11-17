<?php

namespace App\Http\Controllers;

use App\Actions\Messages\DeleteMessage;
use App\Actions\Messages\EditMessage;
use App\Actions\Messages\SendMessage;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\UpdateMessageRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Support\MessageFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Read, post, edit and delete messages in a channel.
 * resources/js/channel-messages.js loads older pages while you scroll up,
 * and the composer sends JSON and gets the stored message back. Without
 * JavaScript the forms post normally and redirect back to the channel.
 *
 * Editing and deleting (ISSUE-16) follow App\Policies\MessagePolicy: the
 * author edits, the author or a moderator deletes.
 *
 * Routes use scoped bindings, so a channel id from another server, or a
 * message id from another channel, is a 404. So is a deleted message.
 */
class MessageController extends Controller
{
    /**
     * One page of history, oldest first: the newest page, or with
     * ?before={cursor} the page before it. next_cursor is the cursor for
     * the page before this one, NULL at the start of the channel.
     */
    public function index(Request $request, Server $server, Channel $channel): JsonResponse
    {
        Gate::authorize('view', $server);
        abort_if($channel->isVoice(), 404);

        $before = null;
        if ($request->has(MessageFeed::CURSOR)) {
            $before = MessageFeed::decodeCursor($request->query(MessageFeed::CURSOR));

            if ($before === null) {
                throw ValidationException::withMessages([
                    MessageFeed::CURSOR => __('The cursor is invalid.'),
                ]);
            }
        }

        return response()->json(MessageFeed::for($channel, before: $before)->toPayload());
    }

    public function store(StoreMessageRequest $request, Server $server, Channel $channel, SendMessage $messages): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $message = $messages->send($user, $channel, $request->content());

        if ($request->expectsJson()) {
            return response()->json(['message' => $message->toPayload()], 201);
        }

        return redirect()->route('channels.show', [$server, $channel]);
    }

    public function update(UpdateMessageRequest $request, Server $server, Channel $channel, Message $message, EditMessage $messages): JsonResponse|RedirectResponse
    {
        $message = $messages->edit($message, $request->content());

        if ($request->expectsJson()) {
            return response()->json(['message' => $message->toPayload()]);
        }

        return redirect()->route('channels.show', [$server, $channel]);
    }

    public function destroy(Request $request, Server $server, Channel $channel, Message $message, DeleteMessage $messages): Response|RedirectResponse
    {
        Gate::authorize('delete', $message);

        $messages->delete($message);

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect()->route('channels.show', [$server, $channel]);
    }
}
