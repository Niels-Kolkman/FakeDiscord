{{--
    The server's bans (ISSUE-29), newest first, with who banned them and
    why. Ban Members only (ServerPolicy::manageBans). Unbanning lets the
    user join again with an invite.

    @var \App\Models\Server $server
    @var \Illuminate\Support\Collection<int, \App\Models\Ban> $bans  with user and bannedBy
--}}
<x-layouts.auth :title="'Bans of '.$server->name">
    @if ($bans->isEmpty())
        <p class="mb-6 text-sm text-fg-muted">Nobody is banned from this server.</p>
    @else
        <ul class="mb-6 space-y-2" aria-label="Bans">
            @foreach ($bans as $ban)
                <li class="flex items-start gap-3 rounded bg-surface-3 px-3 py-2 text-sm" data-ban="{{ $ban->user_id }}">
                    <x-user-avatar :user="$ban->user" :size="32" />
                    <div class="min-w-0 flex-1">
                        <span class="block truncate font-medium text-fg-strong">{{ $ban->user->name }}</span>
                        <span class="block truncate text-xs text-fg-subtle">{{ '@'.$ban->user->username }}</span>
                        <span class="block text-xs text-fg-muted">{{ $ban->reason ?? 'No reason given.' }}</span>
                        <span class="block text-xs text-fg-subtle">
                            Banned by {{ $ban->bannedBy?->name ?? 'Deleted User' }}
                            @if ($ban->created_at)
                                · <time datetime="{{ $ban->created_at->toIso8601String() }}">{{ $ban->created_at->toFormattedDateString() }}</time>
                            @endif
                        </span>
                    </div>
                    <form method="POST" action="{{ route('bans.destroy', [$server, $ban->user]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" aria-label="Unban {{ $ban->user->name }}"
                            class="rounded bg-[#4e5058] px-2 py-1 text-xs font-medium text-white hover:bg-[#6d6f78]">Unban</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="space-x-3 text-sm text-fg-subtle">
        <a href="{{ route('members.index', $server) }}" class="text-link hover:underline">Members</a>
        <a href="{{ route('servers.show', $server) }}" class="text-link hover:underline">Back to server</a>
    </p>
</x-layouts.auth>
