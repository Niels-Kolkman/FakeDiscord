<?php

namespace App\Http\Controllers;

use App\Actions\Channels\MarkChannelRead;
use App\Models\Channel;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Marks a channel as read while it is open (ISSUE-32). resources/js/unread.js
 * calls it when a message arrives live in the channel you are looking at,
 * with that message's `message_id`; without one, the whole channel is read.
 */
class ChannelReadController extends Controller
{
    public function store(Request $request, Server $server, Channel $channel, MarkChannelRead $reads): JsonResponse
    {
        Gate::authorize('view', $server);
        Gate::authorize('view', $channel);

        $validated = $request->validate([
            'message_id' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $messageId = isset($validated['message_id']) ? (int) $validated['message_id'] : null;

        return response()->json([
            'last_read_message_id' => $reads->markRead($user, $channel, $messageId),
        ]);
    }
}
