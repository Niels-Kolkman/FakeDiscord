{{--
    The server's roles (ISSUE-26), highest first; @everyone is always last.

    @var \App\Models\Server $server
    @var \Illuminate\Support\Collection<int, \App\Models\Role> $roles  with users_count
--}}
<x-layouts.auth :title="'Roles of '.$server->name">
    <p class="mb-4 text-sm text-fg-muted">
        Members are listed under their highest role. Use the arrows to move a role up or down.
    </p>

    @error('direction')
        <p class="mb-4 text-sm text-red-400">{{ $message }}</p>
    @enderror
    @error('role')
        <p class="mb-4 text-sm text-red-400">{{ $message }}</p>
    @enderror

    <a href="{{ route('roles.create', $server) }}"
        class="mb-6 block w-full rounded bg-[#5865f2] py-2 text-center font-medium text-white hover:bg-[#4752c4]">Create role</a>

    @php($movable = $roles->reject(fn ($role) => $role->is_default)->values())

    <ul class="mb-6 space-y-2" aria-label="Roles">
        @foreach ($roles as $role)
            @php($index = $movable->search(fn ($r) => $r->is($role)))
            {{-- The role hierarchy (ISSUE-27): only roles below your own highest role can be opened or moved, and not past it. --}}
            @php($canManage = auth()->user()?->can('manage', $role) ?? false)
            <li class="flex items-center gap-3 rounded bg-surface-3 px-3 py-2 text-sm" data-role="{{ $role->id }}">
                <span class="h-3 w-3 shrink-0 rounded-full" aria-hidden="true"
                    style="background-color: {{ $role->displayColor() ?? '#99aab5' }}"></span>
                @if ($canManage)
                    <a href="{{ route('roles.edit', [$server, $role]) }}" class="min-w-0 flex-1 hover:underline">
                @else
                    <div class="min-w-0 flex-1" title="Only roles below your highest role can be changed">
                @endif
                    <span class="block truncate font-medium text-fg-strong">{{ $role->name }}</span>
                    <span class="block text-xs text-fg-subtle">
                        @if ($role->is_default)
                            Every member
                        @else
                            {{ $role->users_count }} {{ \Illuminate\Support\Str::plural('member', $role->users_count) }}
                        @endif
                    </span>
                @if ($canManage)
                    </a>
                @else
                    </div>
                @endif

                @if (! $role->is_default && $canManage)
                    @php($topBlocked = $index > 0 && ! (auth()->user()?->can('manage', $movable[$index - 1]) ?? false))
                    @foreach ([['up', '↑', 'Move up', $index === 0 || $topBlocked], ['down', '↓', 'Move down', $index === $movable->count() - 1]] as [$direction, $arrow, $label, $disabled])
                        <form method="POST" action="{{ route('roles.move', [$server, $role]) }}">
                            @csrf
                            <input type="hidden" name="direction" value="{{ $direction }}">
                            <button type="submit" @disabled($disabled) aria-label="{{ $label }}: {{ $role->name }}" title="{{ $label }}"
                                class="rounded px-2 py-1 text-fg-muted hover:bg-hover hover:text-fg-strong disabled:opacity-30 disabled:hover:bg-transparent">{{ $arrow }}</button>
                        </form>
                    @endforeach
                @endif
            </li>
        @endforeach
    </ul>

    <p class="text-sm text-fg-subtle">
        <a href="{{ route('servers.edit', $server) }}" class="text-link hover:underline">Back to server settings</a>
    </p>
</x-layouts.auth>
