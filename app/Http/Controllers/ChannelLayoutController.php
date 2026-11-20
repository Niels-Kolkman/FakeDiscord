<?php

namespace App\Http\Controllers;

use App\Actions\Channels\ReorderChannels;
use App\Http\Requests\ReorderChannelsRequest;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Saves the sidebar order after a drag and drop (resources/js/channel-sidebar.js).
 * The sidebar only lists what the member can see, so the order
 * is checked against that and hidden channels stay where they are.
 */
class ChannelLayoutController extends Controller
{
    public function update(ReorderChannelsRequest $request, Server $server, ReorderChannels $reorder): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $reorder->reorder($server, $request->uncategorized(), $request->categories(), $user);

        return response()->json(['status' => 'saved']);
    }
}
