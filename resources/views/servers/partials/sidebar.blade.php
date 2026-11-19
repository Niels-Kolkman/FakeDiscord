{{--
    Channel sidebar: uncategorised channels, then collapsible categories.
    resources/js/channel-sidebar.js adds collapsing (remembered per browser)
    and, for users who can manage channels, drag and drop reordering.

    @var \App\Models\Server $server
    @var \App\Support\ChannelLayout $layout
    @var \App\Models\Channel|null $activeChannel

    Unread channels (ISSUE-32, App\Support\UnreadState) get a dot and a
    mention badge; resources/js/unread.js keeps them live.
--}}
@php
    $canManage = auth()->user()?->can('manageChannels', $server) ?? false;
    $unread ??= auth()->user() ? \App\Support\UnreadState::forChannels(auth()->user(), $layout->channels()) : new \App\Support\UnreadState;
@endphp

<nav
    aria-label="{{ $server->name }} channels"
    class="flex w-full shrink-0 flex-col bg-[#2b2d31] md:w-60"
    data-channel-sidebar
    data-server-id="{{ $server->id }}"
    @auth data-blocked-user-ids="{{ json_encode(array_values(\App\Models\Friendship::blockedIds(auth()->user()))) }}" @endauth
    @if ($canManage) data-layout-url="{{ route('channels.layout', $server) }}" @endif
>
    <div class="flex min-h-12 items-center gap-2 border-b border-[#1f2023] px-4 py-2 shadow-sm">
        <x-server-icon :server="$server" :size="24" />
        <span class="truncate font-semibold text-white">{{ $server->name }}</span>
    </div>

    <div class="flex-1 overflow-y-auto px-2 py-3">
        <ul class="mb-2 min-h-1 space-y-0.5" data-channel-list data-category-id="">
            @foreach ($layout->uncategorized as $channel)
                @include('servers.partials.channel-item')
            @endforeach
        </ul>

        <div data-category-list>
            @foreach ($layout->categories as $category)
                <section class="mt-4" data-category data-category-id="{{ $category->id }}" @if ($canManage) draggable="true" @endif>
                    <div class="group flex items-center gap-1 pr-1">
                        <button type="button"
                            class="flex min-w-0 flex-1 items-center gap-1 text-left text-xs font-semibold uppercase tracking-wide text-[#949ba4] hover:text-[#dbdee1]"
                            aria-expanded="true"
                            aria-controls="category-{{ $category->id }}-channels"
                            data-category-toggle
                            @if ($canManage) draggable="false" @endif
                        >
                            <svg aria-hidden="true" class="h-3 w-3 shrink-0 transition-transform" data-category-chevron viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m6 9 6 6 6-6"/></svg>
                            <span class="truncate">{{ $category->name }}</span>
                        </button>
                        @if ($canManage)
                            <a href="{{ route('channels.create', [$server, 'category' => $category->id]) }}" draggable="false"
                                class="px-1 text-lg leading-none text-[#949ba4] hover:text-white"
                                title="Create channel" aria-label="Create channel in {{ $category->name }}">+</a>
                            <a href="{{ route('categories.edit', [$server, $category]) }}" draggable="false"
                                class="px-1 text-sm text-[#949ba4] opacity-0 hover:text-white focus:opacity-100 group-hover:opacity-100"
                                title="Edit category" aria-label="Edit category {{ $category->name }}">⚙</a>
                        @endif
                    </div>

                    <ul id="category-{{ $category->id }}-channels" class="mt-1 min-h-1 space-y-0.5" data-channel-list data-category-id="{{ $category->id }}">
                        @foreach ($category->channels as $channel)
                            @include('servers.partials.channel-item')
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>

        @if ($canManage)
            <p class="mt-4 hidden px-2 text-xs text-[#949ba4]" data-reorder-hint>
                Drag channels and categories to reorder them, or focus one and press Alt+↑ / Alt+↓.
            </p>
            <p class="mt-2 hidden rounded bg-red-900/40 px-2 py-1 text-xs text-red-300" role="alert" data-reorder-error></p>
        @endif
    </div>

    <div class="space-y-1 border-t border-[#1f2023] p-2 text-sm">
        {{-- Online presence (ISSUE-31). --}}
        <x-status-picker />
        @if ($canManage)
            <a href="{{ route('channels.create', $server) }}" class="block rounded px-2 py-1 text-[#b5bac1] hover:bg-[#35373c] hover:text-white">Create channel</a>
            <a href="{{ route('categories.create', $server) }}" class="block rounded px-2 py-1 text-[#b5bac1] hover:bg-[#35373c] hover:text-white">Create category</a>
        @endif
        @can('createInvite', $server)
            <a href="{{ route('servers.invites.index', $server) }}" class="block rounded px-2 py-1 text-[#b5bac1] hover:bg-[#35373c] hover:text-white">Invite people</a>
        @endcan
        @can('update', $server)
            <a href="{{ route('servers.edit', $server) }}" class="block rounded px-2 py-1 text-[#b5bac1] hover:bg-[#35373c] hover:text-white">Server settings</a>
        @endcan
        @can('manageRoles', $server)
            <a href="{{ route('roles.index', $server) }}" class="block rounded px-2 py-1 text-[#b5bac1] hover:bg-[#35373c] hover:text-white">Roles</a>
        @endcan
        {{-- Moderation (ISSUE-29): Kick, Ban or Timeout Members. --}}
        @can('moderateMembers', $server)
            <a href="{{ route('members.index', $server) }}" class="block rounded px-2 py-1 text-[#b5bac1] hover:bg-[#35373c] hover:text-white">Members</a>
        @endcan
        @can('manageBans', $server)
            <a href="{{ route('bans.index', $server) }}" class="block rounded px-2 py-1 text-[#b5bac1] hover:bg-[#35373c] hover:text-white">Bans</a>
        @endcan
        {{-- Audit log (ISSUE-30): owner only. --}}
        @can('viewAuditLog', $server)
            <a href="{{ route('audit-log.index', $server) }}" class="block rounded px-2 py-1 text-[#b5bac1] hover:bg-[#35373c] hover:text-white">Audit log</a>
        @endcan
        {{-- The owner gets the same link; the page explains the transfer. --}}
        <a href="{{ route('servers.leave.confirm', $server) }}" class="block rounded px-2 py-1 text-[#f23f43] hover:bg-[#35373c]">Leave server</a>
        <a href="{{ route('dashboard') }}" class="block rounded px-2 py-1 text-[#b5bac1] hover:bg-[#35373c] hover:text-white">Back to dashboard</a>
    </div>
</nav>
