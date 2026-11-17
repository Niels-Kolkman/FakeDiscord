<?php

namespace App\Http\Controllers;

use App\Actions\Channels\ReorderChannels;
use App\Http\Requests\ReorderChannelsRequest;
use App\Models\Server;
use Illuminate\Http\JsonResponse;

/**
 * Saves the sidebar order after a drag and drop (resources/js/channel-sidebar.js).
 */
class ChannelLayoutController extends Controller
{
    public function update(ReorderChannelsRequest $request, Server $server, ReorderChannels $reorder): JsonResponse
    {
        $reorder->reorder($server, $request->uncategorized(), $request->categories());

        return response()->json(['status' => 'saved']);
    }
}
