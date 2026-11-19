{{-- Placeholder landing page after login; the real app shell comes in later stories. --}}
<x-layouts.auth title="You're logged in">
    <div class="mb-6 flex items-center justify-center gap-3">
        <x-user-avatar :user="auth()->user()" :size="48" />
        <p>
            Signed in as <strong class="text-fg-strong">{{ auth()->user()?->name }}</strong>
            <span class="text-fg-subtle">{{ '@'.auth()->user()?->username }}</span>
        </p>
    </div>

    <a href="{{ route('profile.edit') }}" class="mb-3 block w-full rounded bg-[#4e5058] py-2 text-center font-medium text-white hover:bg-[#6d6f78]">Edit profile</a>

    {{-- Friends (ISSUE-22). The badge counts requests waiting for an answer; resources/js/friends.js keeps it live. --}}
    @if (auth()->user()?->hasVerifiedEmail())
        @php($friendRequests = \App\Models\Friendship::incomingCount(auth()->user()))
        <a href="{{ route('friends.index', $friendRequests > 0 ? ['tab' => 'pending'] : []) }}" class="mb-3 flex w-full items-center justify-center gap-2 rounded bg-[#4e5058] py-2 font-medium text-white hover:bg-[#6d6f78]">
            Friends
            <span data-friend-request-badge @if ($friendRequests === 0) hidden @endif class="rounded-full bg-[#da373c] px-1.5 text-xs font-semibold">{{ $friendRequests }}</span>
        </a>

        {{-- Mention notifications (ISSUE-33). Not live here; the bell on the app pages is. --}}
        @php($inboxUnread = \App\Support\NotificationInbox::unreadCount(auth()->user()))
        <a href="{{ route('notifications.index') }}" class="mb-3 flex w-full items-center justify-center gap-2 rounded bg-[#4e5058] py-2 font-medium text-white hover:bg-[#6d6f78]" data-dashboard-inbox>
            Inbox
            @if ($inboxUnread > 0)
                <span class="rounded-full bg-[#da373c] px-1.5 text-xs font-semibold">{{ \App\Support\NotificationInbox::badge($inboxUnread) }}<span class="sr-only"> unread</span></span>
            @endif
        </a>
    @endif

    @if (request()->query('verified'))
        <div role="status" class="mb-4 rounded bg-green-900/40 px-3 py-2 text-sm text-green-300 in-data-[theme=light]:bg-green-100 in-data-[theme=light]:text-green-800">
            Your email address is verified.
        </div>
    @endif

    @unless (auth()->user()?->hasVerifiedEmail())
        <div class="mb-6 rounded bg-yellow-900/40 px-3 py-2 text-sm text-yellow-200 in-data-[theme=light]:bg-yellow-100 in-data-[theme=light]:text-yellow-900">
            <p class="mb-2">Verify your email address before you join servers. Check your inbox for the link.</p>
            <a href="{{ route('verification.notice') }}" class="font-medium text-link hover:underline">Resend verification email</a>
        </div>
    @endunless

    @php($servers = auth()->user()?->servers()->orderBy('name')->get() ?? collect())

    <section aria-labelledby="servers-heading" class="mb-6">
        <h2 id="servers-heading" class="mb-2 text-xs font-semibold uppercase tracking-wide text-fg-subtle">Your servers</h2>

        @if ($servers->isEmpty())
            <p class="mb-3 text-sm text-fg-muted">You are not in any servers yet.</p>
        @else
            {{-- Unread dots and mention badges (ISSUE-32). --}}
            @php($serverUnread = \App\Support\UnreadState::forServers(auth()->user(), $servers))
            <ul class="mb-3 space-y-1">
                @foreach ($servers as $server)
                    <li>
                        <a href="{{ route('servers.show', $server) }}" class="flex items-center gap-3 rounded px-2 py-1.5 hover:bg-hover">
                            <x-server-icon :server="$server" :size="32" />
                            <span class="truncate text-fg-strong">{{ $server->name }}</span>
                            @include('servers.partials.server-unread', ['state' => $serverUnread[$server->id] ?? null])
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @can('create', \App\Models\Server::class)
            <a href="{{ route('servers.create') }}" class="block w-full rounded bg-[#248046] py-2 text-center font-medium text-white hover:bg-[#1a6334]">Create a server</a>
        @endcan
    </section>

    <p class="mb-4 text-center">
        <a href="{{ route('settings.account') }}" class="font-medium text-link hover:underline">Account settings</a>
        @if (auth()->user()?->hasVerifiedEmail())
            <span aria-hidden="true" class="text-[#4e5058]">·</span>
            <a href="{{ route('settings.notifications') }}" class="font-medium text-link hover:underline">Notifications</a>
        @endif
    </p>

    {{-- Dark / light theme (ISSUE-37); also at the bottom of the server rail. --}}
    <x-theme-toggle class="mb-3" />

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="w-full rounded bg-[#da373c] py-2 font-medium text-white hover:bg-[#a12828]">Log Out</button>
    </form>
</x-layouts.auth>
