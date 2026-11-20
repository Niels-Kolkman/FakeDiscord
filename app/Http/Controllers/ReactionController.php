<?php

namespace App\Http\Controllers;

use App\Actions\Messages\AddReaction;
use App\Actions\Messages\RemoveReaction;
use App\Http\Requests\ReactionRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Support\Reactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Add and remove emoji reactions on a channel message.
 *
 * resources/js/channel-messages.js sends JSON and redraws the message's
 * reactions from {"reactions": [...]} (Reactions::groups() for the
 * reactor). Without JavaScript the reaction buttons are forms that
 * redirect back to the channel.
 *
 * Scoped bindings make a message of another channel, or a deleted one, a 404.
 */
class ReactionController extends Controller
{
    public function store(ReactionRequest $request, Server $server, Channel $channel, Message $message, AddReaction $reactions): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $reactions->add($user, $message, $request->emoji());

        return $this->respond($request, $user, $server, $channel, $message);
    }

    public function destroy(ReactionRequest $request, Server $server, Channel $channel, Message $message, RemoveReaction $reactions): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $reactions->remove($user, $message, $request->emoji());

        return $this->respond($request, $user, $server, $channel, $message);
    }

    private function respond(ReactionRequest $request, User $user, Server $server, Channel $channel, Message $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['reactions' => Reactions::groups($message, (int) $user->id)]);
        }

        return redirect()->route('channels.show', [$server, $channel]);
    }
}
