<?php

namespace App\Http\Controllers;

use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\MarkChannelRead;
use App\Actions\Channels\UpdateChannel;
use App\Http\Requests\StoreChannelRequest;
use App\Http\Requests\UpdateChannelRequest;
use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Models\Server;
use App\Models\User;
use App\Support\MessageFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Open, create, edit and delete channels. Opening one needs
 * ChannelPolicy::view (View Channels, ISSUE-27);
 * changing them needs ServerPolicy::manageChannels, and a channel hidden
 * from the member (ISSUE-28) cannot be edited or deleted by them either.
 *
 * Routes use scoped bindings, so a channel id from another server is a 404.
 */
class ChannelController extends Controller
{
    /**
     * Opening a text channel marks it read (ISSUE-32), before the sidebar
     * works out what is still unread.
     */
    public function show(Request $request, Server $server, Channel $channel, MarkChannelRead $reads): View
    {
        Gate::authorize('view', $server);
        Gate::authorize('view', $channel);

        /** @var User $user */
        $user = $request->user();
        if (! $channel->isVoice()) {
            $reads->markRead($user, $channel);
        }

        return view('servers.show', [
            'server' => $server,
            'layout' => $server->channelLayout(auth()->user()),
            'channel' => $channel,
            'feed' => MessageFeed::for($channel, viewer: auth()->user()),
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
        Gate::authorize('view', $channel);

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
        Gate::authorize('view', $channel);

        // Messages and attachment rows go through ON DELETE CASCADE; the
        // attachment files are removed once that is committed.
        $attachmentFiles = MessageAttachment::filesInChannels([(int) $channel->id]);
        DB::transaction(fn () => $channel->delete());
        MessageAttachment::deleteFiles($attachmentFiles);

        return redirect()
            ->route('servers.show', $server)
            ->with('status', "#{$channel->name} has been deleted.");
    }
}
