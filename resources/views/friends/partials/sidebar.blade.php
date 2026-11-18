{{--
    Left column of the friends page and of a DM (ISSUE-23): Friends, the
    direct messages (conversations/partials/dm-list.blade.php, latest
    activity first), the user's servers and a way back to the dashboard.

    @var int $incomingCount
    @var \App\Models\Conversation|null $activeConversation  the open DM, or NULL on the friends page
--}}
@php
    $servers = auth()->user()?->servers()->orderBy('name')->get() ?? collect();
    // Unread dots and mention badges (ISSUE-32).
    $serverUnread = auth()->user() ? \App\Support\UnreadState::forServers(auth()->user(), $servers) : [];
    $onFriendsPage = ($activeConversation ?? null) === null;
@endphp

<nav aria-label="Navigation" class="flex w-full shrink-0 flex-col bg-[#2b2d31] md:w-60">
    <div class="flex min-h-12 items-center border-b border-[#1f2023] px-4 py-2 shadow-sm">
        <a href="{{ route('dashboard') }}" class="font-semibold text-white hover:underline">{{ config('app.name', 'Laravel') }}</a>
    </div>

    <div class="flex-1 overflow-y-auto px-2 py-3">
        <a
            href="{{ route('friends.index') }}"
            @if ($onFriendsPage) aria-current="page" @endif
            class="mb-4 flex items-center gap-2 rounded px-2 py-1.5 font-medium text-[#b5bac1] hover:bg-[#35373c] hover:text-white aria-[current=page]:bg-[#404249] aria-[current=page]:text-white"
        >
            <span aria-hidden="true">👋</span> Friends
            <span data-friend-request-badge @if ($incomingCount === 0) hidden @endif class="ml-auto rounded-full bg-[#da373c] px-1.5 text-xs font-semibold text-white">{{ $incomingCount }}</span>
        </a>

        @include('conversations.partials.dm-list', ['activeConversation' => $activeConversation ?? null])

        <h2 class="mb-1 px-2 text-xs font-semibold uppercase tracking-wide text-[#949ba4]">Your servers</h2>
        @if ($servers->isEmpty())
            <p class="px-2 text-sm text-[#949ba4]">You are not in any servers yet.</p>
        @else
            <ul class="space-y-0.5">
                @foreach ($servers as $server)
                    <li>
                        <a href="{{ route('servers.show', $server) }}" class="flex items-center gap-2 rounded px-2 py-1.5 text-[#b5bac1] hover:bg-[#35373c] hover:text-white">
                            <x-server-icon :server="$server" :size="24" />
                            <span class="truncate">{{ $server->name }}</span>
                            @include('servers.partials.server-unread', ['state' => $serverUnread[$server->id] ?? null])
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</nav>
