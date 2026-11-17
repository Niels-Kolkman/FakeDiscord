<?php

namespace App\Http\Controllers;

use App\Actions\Messages\SendMessage;
use App\Http\Requests\StoreMessageRequest;
use App\Models\Channel;
use App\Models\Server;
use App\Models\User;
use App\Support\MessageFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Read and post messages in a channel. resources/js/channel-messages.js
 * loads older pages while you scroll up, and the composer sends JSON and
 * gets the stored message back. Without JavaScript the form posts normally
 * and is redirected back to the channel.
 *
 * Routes use scoped bindings, so a channel id from another server is a 404.
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
}
