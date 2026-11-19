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
    // Mute settings (ISSUE-34): the 🔕 toggles in the header and on each channel.
    $mutes ??= auth()->user() ? \App\Support\NotificationMutes::forUser(auth()->user()) : ['servers' => [], 'channels' => []];
    $serverMuted = in_array((int) $server->id, $mutes['servers'], true);
@endphp

<nav
    aria-label="{{ $server->name }} channels"
    aria-describedby="channel-shortcuts-help"
    class="flex min-w-0 flex-1 flex-col bg-surface-2 md:w-60 md:flex-none"
    data-channel-sidebar
    data-server-id="{{ $server->id }}"
    @auth data-blocked-user-ids="{{ json_encode(array_values(\App\Models\Friendship::blockedIds(auth()->user()))) }}" @endauth
    {{-- Role mentions (ISSUE-33) count for the roles the viewer holds here. --}}
    @auth data-viewer-role-ids="{{ json_encode(\App\Support\Mentions::roleIdsOf(auth()->user(), $server)) }}" @endauth
    @if ($canManage) data-layout-url="{{ route('channels.layout', $server) }}" @endif
>
    <div class="flex min-h-12 items-center gap-2 border-b border-edge px-4 py-2 shadow-sm">
        <x-server-icon :server="$server" :size="24" />
        <span class="min-w-0 flex-1 truncate font-semibold text-fg-strong">{{ $server->name }}</span>
        @auth
            <form method="POST" action="{{ $serverMuted ? route('servers.unmute', $server) : route('servers.mute', $server) }}" class="shrink-0" data-mute-server-form @if ($serverMuted) data-muted @endif>
                @csrf
                @method($serverMuted ? 'DELETE' : 'PUT')
                <button type="submit"
                    class="rounded px-1 text-sm {{ $serverMuted ? 'text-fg-strong' : 'text-fg-subtle hover:text-fg-strong' }} focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]"
                    title="{{ $serverMuted ? 'Unmute server' : 'Mute server' }}"
                    aria-pressed="{{ $serverMuted ? 'true' : 'false' }}"
                ><span aria-hidden="true">{{ $serverMuted ? '🔕' : '🔔' }}</span><span class="sr-only">Mute {{ $server->name }}</span></button>
            </form>
        @endauth
    </div>

    {{-- Keyboard shortcuts (ISSUE-38, resources/js/keyboard-shortcuts.js). --}}
    <p id="channel-shortcuts-help" class="sr-only">Alt+Up and Alt+Down switch channels, Alt+Shift+Up and Alt+Shift+Down go to unread channels.</p>

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
                            class="flex min-w-0 flex-1 items-center gap-1 text-left text-xs font-semibold uppercase tracking-wide text-fg-subtle hover:text-fg"
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
                                class="px-1 text-lg leading-none text-fg-subtle hover:text-fg-strong"
                                title="Create channel" aria-label="Create channel in {{ $category->name }}">+</a>
                            <a href="{{ route('categories.edit', [$server, $category]) }}" draggable="false"
                                class="px-1 text-sm text-fg-subtle opacity-0 hover:text-fg-strong focus:opacity-100 group-hover:opacity-100"
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
            <p class="mt-4 hidden px-2 text-xs text-fg-subtle" data-reorder-hint>
                Drag channels and categories to reorder them, or focus one and press Alt+↑ / Alt+↓.
            </p>
            <p class="mt-2 hidden rounded bg-red-900/40 px-2 py-1 text-xs text-red-300 in-data-[theme=light]:bg-red-100 in-data-[theme=light]:text-red-800" role="alert" data-reorder-error></p>
        @endif
    </div>

    <div class="space-y-1 border-t border-edge p-2 text-sm">
        {{-- Online presence (ISSUE-31). --}}
        <x-status-picker />
        @if ($canManage)
            <a href="{{ route('channels.create', $server) }}" class="block rounded px-2 py-1 text-fg-muted hover:bg-hover hover:text-fg-strong">Create channel</a>
            <a href="{{ route('categories.create', $server) }}" class="block rounded px-2 py-1 text-fg-muted hover:bg-hover hover:text-fg-strong">Create category</a>
        @endif
        @can('createInvite', $server)
            <a href="{{ route('servers.invites.index', $server) }}" class="block rounded px-2 py-1 text-fg-muted hover:bg-hover hover:text-fg-strong">Invite people</a>
        @endcan
        @can('update', $server)
            <a href="{{ route('servers.edit', $server) }}" class="block rounded px-2 py-1 text-fg-muted hover:bg-hover hover:text-fg-strong">Server settings</a>
        @endcan
        @can('manageRoles', $server)
            <a href="{{ route('roles.index', $server) }}" class="block rounded px-2 py-1 text-fg-muted hover:bg-hover hover:text-fg-strong">Roles</a>
        @endcan
        {{-- Moderation (ISSUE-29): Kick, Ban or Timeout Members. --}}
        @can('moderateMembers', $server)
            <a href="{{ route('members.index', $server) }}" class="block rounded px-2 py-1 text-fg-muted hover:bg-hover hover:text-fg-strong">Members</a>
        @endcan
        @can('manageBans', $server)
            <a href="{{ route('bans.index', $server) }}" class="block rounded px-2 py-1 text-fg-muted hover:bg-hover hover:text-fg-strong">Bans</a>
        @endcan
        {{-- Audit log (ISSUE-30): owner only. --}}
        @can('viewAuditLog', $server)
            <a href="{{ route('audit-log.index', $server) }}" class="block rounded px-2 py-1 text-fg-muted hover:bg-hover hover:text-fg-strong">Audit log</a>
        @endcan
        {{-- The owner gets the same link; the page explains the transfer. --}}
        <a href="{{ route('servers.leave.confirm', $server) }}" class="block rounded px-2 py-1 text-danger-fg hover:bg-hover">Leave server</a>
        <a href="{{ route('dashboard') }}" class="block rounded px-2 py-1 text-fg-muted hover:bg-hover hover:text-fg-strong">Back to dashboard</a>
    </div>
</nav>
