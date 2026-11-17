<?php

namespace App\Http\Controllers;

use App\Actions\Messages\SendMessage;
use App\Http\Requests\StoreMessageRequest;
use App\Models\Channel;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Post messages in a channel. The composer (resources/js/channel-messages.js)
 * sends JSON and gets the stored message back; without JavaScript the form
 * posts normally and is redirected back to the channel.
 *
 * Routes use scoped bindings, so a channel id from another server is a 404.
 */
class MessageController extends Controller
{
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
