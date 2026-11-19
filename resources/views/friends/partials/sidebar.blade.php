{{--
    Left column of the friends page and of a DM (ISSUE-23): Friends, the
    direct messages (conversations/partials/dm-list.blade.php, latest
    activity first) and a way back to the dashboard. Your servers, with
    their unread dots and mention badges, are in the server rail left of
    it (components/server-rail.blade.php, ISSUE-35).

    @var int $incomingCount
    @var \App\Models\Conversation|null $activeConversation  the open DM, or NULL on the friends page
--}}
@php
    $onFriendsPage = ($activeConversation ?? null) === null;
@endphp

<nav aria-label="Navigation" aria-describedby="dm-shortcuts-help" class="flex min-w-0 flex-1 flex-col bg-surface-2 md:w-60 md:flex-none">
    {{-- Keyboard shortcuts (ISSUE-38, resources/js/keyboard-shortcuts.js). --}}
    <p id="dm-shortcuts-help" class="sr-only">Alt+Up and Alt+Down switch between direct messages.</p>

    <div class="flex min-h-12 items-center border-b border-edge px-4 py-2 shadow-sm">
        <a href="{{ route('dashboard') }}" class="font-semibold text-fg-strong hover:underline">{{ config('app.name', 'Laravel') }}</a>
    </div>

    <div class="flex-1 overflow-y-auto px-2 py-3">
        <a
            href="{{ route('friends.index') }}"
            @if ($onFriendsPage) aria-current="page" @endif
            class="mb-4 flex items-center gap-2 rounded px-2 py-1.5 font-medium text-fg-muted hover:bg-hover hover:text-fg-strong aria-[current=page]:bg-active aria-[current=page]:text-fg-strong"
        >
            <span aria-hidden="true">👋</span> Friends
            <span data-friend-request-badge @if ($incomingCount === 0) hidden @endif class="ml-auto rounded-full bg-[#da373c] px-1.5 text-xs font-semibold text-white">{{ $incomingCount }}</span>
        </a>

        @include('conversations.partials.dm-list', ['activeConversation' => $activeConversation ?? null])
    </div>
</nav>
