<?php

namespace App\Http\Controllers;

use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\UpdateChannel;
use App\Http\Requests\StoreChannelRequest;
use App\Http\Requests\UpdateChannelRequest;
use App\Models\Channel;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Open, create, edit and delete channels. Members can open channels;
 * changing them needs ServerPolicy::manageChannels.
 *
 * Routes use scoped bindings, so a channel id from another server is a 404.
 */
class ChannelController extends Controller
{
    public function show(Server $server, Channel $channel): View
    {
        Gate::authorize('view', $server);

        return view('servers.show', [
            'server' => $server,
            'layout' => $server->channelLayout(),
            'channel' => $channel,
            'members' => $server->memberList(),
        ]);
    }

    public function create(Request $request, Server $server): View
    {
        Gate::authorize('manageChannels', $server);

        $categories = $server->categories()->get();
        $selected = $request->integer('category') ?: null;

        return view('channels.create', [
            'server' => $server,
            'categories' => $categories,
            'selectedCategory' => $categories->contains('id', $selected) ? $selected : null,
        ]);
    }

    public function store(StoreChannelRequest $request, Server $server, CreateChannel $channels): RedirectResponse
    {
        $channel = $channels->create(
            $server,
            $request->string('name')->value(),
            $request->topic(),
            $request->categoryId(),
        );

        return redirect()
            ->route('channels.show', [$server, $channel])
            ->with('status', "#{$channel->name} has been created.");
    }

    public function edit(Server $server, Channel $channel): View
    {
        Gate::authorize('manageChannels', $server);

        return view('channels.edit', [
            'server' => $server,
            'channel' => $channel,
            'categories' => $server->categories()->get(),
        ]);
    }

    public function update(UpdateChannelRequest $request, Server $server, Channel $channel, UpdateChannel $channels): RedirectResponse
    {
        $channels->update(
            $channel,
            $request->string('name')->value(),
            $request->topic(),
            $request->categoryId(),
        );

        return redirect()
            ->route('channels.edit', [$server, $channel])
            ->with('status', 'Channel settings saved.');
    }

    /**
     * Messages go with the channel (ON DELETE CASCADE). Invites that pointed
     * at it stay, with channel_id set to NULL.
     */
    public function destroy(Server $server, Channel $channel): RedirectResponse
    {
        Gate::authorize('manageChannels', $server);

        DB::transaction(fn () => $channel->delete());

        return redirect()
            ->route('servers.show', $server)
            ->with('status', "#{$channel->name} has been deleted.");
    }
}
