{{--
    The server's roles (ISSUE-26), highest first; @everyone is always last.

    @var \App\Models\Server $server
    @var \Illuminate\Support\Collection<int, \App\Models\Role> $roles  with users_count
--}}
<x-layouts.auth :title="'Roles of '.$server->name">
    <p class="mb-4 text-sm text-[#b5bac1]">
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
            <li class="flex items-center gap-3 rounded bg-[#1e1f22] px-3 py-2 text-sm" data-role="{{ $role->id }}">
                <span class="h-3 w-3 shrink-0 rounded-full" aria-hidden="true"
                    style="background-color: {{ $role->displayColor() ?? '#99aab5' }}"></span>
                <a href="{{ route('roles.edit', [$server, $role]) }}" class="min-w-0 flex-1 hover:underline">
                    <span class="block truncate font-medium text-white">{{ $role->name }}</span>
                    <span class="block text-xs text-[#949ba4]">
                        @if ($role->is_default)
                            Every member
                        @else
                            {{ $role->users_count }} {{ \Illuminate\Support\Str::plural('member', $role->users_count) }}
                        @endif
                    </span>
                </a>

                @unless ($role->is_default)
                    @foreach ([['up', '↑', 'Move up', $index === 0], ['down', '↓', 'Move down', $index === $movable->count() - 1]] as [$direction, $arrow, $label, $disabled])
                        <form method="POST" action="{{ route('roles.move', [$server, $role]) }}">
                            @csrf
                            <input type="hidden" name="direction" value="{{ $direction }}">
                            <button type="submit" @disabled($disabled) aria-label="{{ $label }}: {{ $role->name }}" title="{{ $label }}"
                                class="rounded px-2 py-1 text-[#b5bac1] hover:bg-[#35373c] hover:text-white disabled:opacity-30 disabled:hover:bg-transparent">{{ $arrow }}</button>
                        </form>
                    @endforeach
                @endunless
            </li>
        @endforeach
    </ul>

    <p class="text-sm text-[#949ba4]">
        <a href="{{ route('servers.edit', $server) }}" class="text-[#00a8fc] hover:underline">Back to server settings</a>
    </p>
</x-layouts.auth>
