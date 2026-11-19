{{--
    A server with one channel open. Used by /servers/{id} (the first text
    channel) and /servers/{id}/channels/{channel}.

    Text channels show their newest messages ($feed, App\Support\MessageFeed)
    and a composer in the shared message panel (messages/panel.blade.php,
    also used by DMs). resources/js/channel-messages.js posts new messages,
    appends the ones others send (received over Reverb on "channel.{id}"),
    loads older pages from data-history-url while you scroll up and shows
    the "jump to latest" button when you are far from the bottom.
    It also shows who else is typing in [data-typing-indicator] (ISSUE-17,
    resources/js/typing-indicator.js), also for members who cannot send.
--}}
<x-layouts.app :title="$channel ? '#'.$channel->name.' · '.$server->name : $server->name">
    <x-slot:sidebar>
        @include('servers.partials.sidebar', ['activeChannel' => $channel])
    </x-slot:sidebar>

    <x-slot:aside>
        @include('servers.partials.member-list')
    </x-slot:aside>

    @if ($channel)
        <header class="flex min-h-12 items-center gap-2 border-b border-[#1f2023] px-4 py-2 shadow-sm">
            <span aria-hidden="true" class="text-xl text-[#80848e]">{{ $channel->isVoice() ? '🔊' : '#' }}</span>
            <h1 class="shrink-0 font-semibold text-white">{{ $channel->name }}</h1>
            @if ($channel->topic)
                <span aria-hidden="true" class="h-6 w-px shrink-0 bg-[#3f4147]"></span>
                <p class="truncate text-sm text-[#b5bac1]" title="{{ $channel->topic }}" data-channel-topic>{{ $channel->topic }}</p>
            @endif
            <div class="ml-auto flex shrink-0 items-center gap-2">
                @unless ($channel->isVoice())
                    {{-- Pinned messages (ISSUE-20): the script opens [data-pins-panel]; without it this is the pins page. --}}
                    <a
                        href="{{ route('pins.index', [$server, $channel]) }}"
                        class="shrink-0 rounded px-2 py-1 text-sm text-[#b5bac1] hover:bg-[#404249] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2] aria-expanded:bg-[#404249] aria-expanded:text-white"
                        aria-controls="pins-panel"
                        data-pins-toggle
                    ><span aria-hidden="true">📌</span> Pinned<span class="sr-only"> messages</span></a>
                @endunless
                {{-- Message search (ISSUE-21) over the whole server; filters are on the results page. --}}
                <form method="GET" action="{{ route('servers.search', $server) }}" role="search" class="shrink-0" data-header-search>
                    <label for="header-search" class="sr-only">Search {{ $server->name }}</label>
                    <input
                        id="header-search"
                        type="search"
                        name="q"
                        maxlength="{{ \App\Support\MessageSearch::MAX_QUERY_LENGTH }}"
                        placeholder="Search"
                        autocomplete="off"
                        class="w-36 rounded bg-[#1e1f22] px-2 py-1 text-sm text-white placeholder-[#949ba4] outline-none transition-[width] focus:w-56 focus:ring-2 focus:ring-[#5865f2]"
                    >
                </form>
                {{-- Mention notifications (ISSUE-33). --}}
                <x-inbox />
            </div>
        </header>

        @php
            // Worked out once for every message (messages/item.blade.php)
            // and for the script, which shows Reply / Pin / Edit / Delete
            // the same way.
            $viewerId = (int) auth()->id();
            // Channel permissions (App\Policies\ChannelPolicy, ISSUE-27).
            $canManageMessages = (bool) auth()->user()?->can('manageMessages', $channel);
            $canSendMessages = ! $channel->isVoice() && (bool) auth()->user()?->can('sendMessages', $channel);
            $canAttachFiles = $canSendMessages && (bool) auth()->user()?->can('attachFiles', $channel);
            // People the viewer blocked (ISSUE-25): the history already
            // leaves their messages out; this hides live ones and quotes.
            $blockedUserIds = auth()->user() ? \App\Models\Friendship::blockedIds(auth()->user()) : [];
            // Timeouts (ISSUE-29): the composer is replaced by when it ends.
            $timedOutUntil = auth()->user() ? app(\App\Services\PermissionService::class)->timedOutUntil(auth()->user(), $server) : null;
            // Mentions (ISSUE-33): messages that mention the viewer (by
            // name, a role they hold, or @everyone) are highlighted.
            $viewerRoleIds = auth()->user() ? \App\Support\Mentions::roleIdsOf(auth()->user(), $server) : [];
        @endphp
        @include('messages.panel', ['panel' => [
            'target' => 'channel',
            'id' => $channel->id,
            'enabled' => ! $channel->isVoice(),
            'label' => '#'.$channel->name,
            'historyUrl' => route('messages.index', [$server, $channel]),
            'storeUrl' => route('messages.store', [$server, $channel]),
            'pinsUrl' => route('pins.index', [$server, $channel]),
            'readUrl' => $channel->isVoice() ? null : route('channels.read', [$server, $channel]),
            'mentionsUrl' => $channel->isVoice() ? null : route('mentions.index', [$server, $channel]),
            'broadcastChannel' => \App\Events\MessageSent::channelName((int) $channel->id),
            'typingChannel' => 'typing.'.$channel->id,
            'start' => 'messages.partials.channel-start',
            'readOnlyNotice' => $timedOutUntil
                ? 'You are timed out until '.$timedOutUntil->toDayDateTimeString().'. You can read messages but not send or react.'
                : null,
        ]])
    @else
        <section class="flex flex-1 flex-col items-center justify-center p-8 text-center">
            <h1 class="mb-2 text-xl font-semibold text-white">No text channels</h1>
            <p class="text-[#b5bac1]">
                {{-- The layout only holds the channels the viewer can see (ISSUE-27). --}}
                This server has no text channels yet.
                @can('manageChannels', $server)
                    <a href="{{ route('channels.create', $server) }}" class="text-[#00a8fc] hover:underline">Create one</a>.
                @endcan
            </p>
        </section>
    @endif
</x-layouts.app>
