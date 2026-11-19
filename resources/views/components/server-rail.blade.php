{{--
    The server rail (ISSUE-35): the far-left column of the app shell
    (components/layouts/app.blade.php). Home (friends and direct messages)
    on top, then every server you are in, then "Create a server".

    What is open comes from the route: a page under /servers/{server} marks
    that server (aria-current and the tall pill), the friends, DM and
    notification pages mark Home. Unread dots and mention badges are the
    same App\Support\UnreadState as the dashboard; like the other server
    lists they are worked out when the page loads (not live).

    It is a 72px column on every screen size: below md it sits in the
    navigation drawer next to the channel sidebar (ISSUE-37, see the app
    layout). The theme toggle is at the bottom.
--}}
@php
    $viewer = auth()->user();
    $servers = $viewer?->servers()->orderBy('name')->get() ?? collect();
    $serverUnread = $viewer ? \App\Support\UnreadState::forServers($viewer, $servers) : [];

    $routeServer = request()->route('server');
    $activeServerId = $routeServer instanceof \App\Models\Server ? (int) $routeServer->id : null;
    $homeActive = $activeServerId === null && request()->routeIs('friends.*', 'dms.*', 'notifications.*');

    // The pill left of an item: tall when open, short when unread, medium on hover.
    $pill = 'pointer-events-none absolute left-0 top-1/2 w-1 -translate-y-1/2 rounded-r-full bg-fg-strong transition-all';
    $item = 'relative block rounded-full transition-transform focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2] focus-visible:ring-offset-2 focus-visible:ring-offset-surface-3';
@endphp

<nav
    aria-label="Servers"
    class="flex w-[72px] shrink-0 flex-col items-center gap-2 overflow-y-auto bg-surface-3 py-3"
    data-server-rail
>
    <div class="group relative flex w-full shrink-0 justify-center">
        <span aria-hidden="true" class="{{ $pill }} {{ $homeActive ? 'h-10' : 'h-0 group-hover:h-5' }}"></span>
        <a
            href="{{ route('friends.index') }}"
            title="Home"
            @if ($homeActive) aria-current="page" @endif
            class="{{ $item }} flex h-12 w-12 items-center justify-center bg-surface text-[#3ba55d] hover:bg-[#3ba55d] hover:text-white aria-[current=page]:bg-[#3ba55d] aria-[current=page]:text-white"
            data-rail-home
        >
            <x-brand-mark :size="28" />
            <span class="sr-only">Home: friends and direct messages</span>
        </a>
    </div>

    <span aria-hidden="true" class="h-0.5 w-8 shrink-0 rounded bg-hover"></span>

    @if ($servers->isNotEmpty())
        <ul class="flex w-full shrink-0 flex-col items-center gap-2" data-rail-servers>
            @foreach ($servers as $server)
                @php
                    $state = $serverUnread[$server->id] ?? null;
                    $active = (int) $server->id === $activeServerId;
                    $mentions = $state?->totalMentions() ?? 0;
                    $unread = $state?->hasUnread() ?? false;
                @endphp
                <li class="group relative flex w-full shrink-0 justify-center" data-rail-server="{{ $server->id }}">
                    <span aria-hidden="true" class="{{ $pill }} {{ $active ? 'h-10' : ($unread ? 'h-2 group-hover:h-5' : 'h-0 group-hover:h-5') }}"></span>
                    <a
                        href="{{ route('servers.show', $server) }}"
                        title="{{ $server->name }}"
                        @if ($active) aria-current="page" @endif
                        class="{{ $item }} hover:-translate-y-0.5"
                    >
                        <x-server-icon :server="$server" :size="48" />
                        @if ($mentions > 0)
                            <span class="absolute -bottom-1 -right-1 min-w-5 rounded-full border-4 border-surface-3 bg-[#da373c] px-1 text-center text-xs font-semibold leading-3 text-white" data-server-mentions>{{ $mentions }}<span class="sr-only"> {{ $mentions === 1 ? 'mention' : 'mentions' }}</span></span>
                        @elseif ($unread && ! $active)
                            {{-- The short pill shows it; this is for screen readers. --}}
                            <span data-server-unread><span class="sr-only">(unread)</span></span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    @can('create', \App\Models\Server::class)
        <div class="group relative flex w-full shrink-0 justify-center">
            <span aria-hidden="true" class="{{ $pill }} h-0 group-hover:h-5"></span>
            <a
                href="{{ route('servers.create') }}"
                title="Create a server"
                class="{{ $item }} flex h-12 w-12 items-center justify-center bg-surface text-2xl leading-none text-[#3ba55d] hover:bg-[#3ba55d] hover:text-white"
                data-rail-create
            ><span aria-hidden="true">+</span><span class="sr-only">Create a server</span></a>
        </div>
    @endcan

    {{-- Dark / light theme (ISSUE-37), at the bottom. --}}
    <x-theme-toggle variant="rail" class="mt-auto pt-2" />
</nav>
