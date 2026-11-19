{{--
    Member moderation (ISSUE-29): kick, ban and time out members. Each
    member shows only the actions the viewer may take on them
    (ServerPolicy::kick / ban / timeout: the right permission and a member
    below the viewer's highest role; never the owner or yourself).

    @var \App\Models\Server $server
    @var \Illuminate\Support\Collection<int, array{member: \App\Models\ServerMember, timedOutUntil: \Carbon\CarbonInterface|null, canKick: bool, canBan: bool, canTimeout: bool}> $members
    @var array<int, string> $durations
    @var array<int, string> $deleteMessageOptions
    @var int $maxReasonLength
--}}
@php($button = 'rounded px-2 py-1 text-xs font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]')
@php($select = 'rounded bg-surface-3 px-2 py-1 text-xs text-fg-strong outline-none focus:ring-2 focus:ring-[#5865f2]')

<x-layouts.auth :title="'Members of '.$server->name">
    @foreach (['reason', 'delete_messages', 'duration'] as $field)
        @error($field)
            <p class="mb-4 text-sm text-danger-fg" role="alert">{{ $message }}</p>
        @enderror
    @endforeach

    <ul class="mb-6 space-y-2" aria-label="Members">
        @foreach ($members as $row)
            @php($member = $row['member'])
            @php($user = $member->user)
            <li class="rounded bg-surface-3 px-3 py-2 text-sm" data-moderation-member="{{ $user->id }}">
                <div class="flex items-center gap-3">
                    <x-user-avatar :user="$user" :size="32" />
                    <div class="min-w-0 flex-1">
                        <span class="block truncate font-medium text-fg-strong">
                            {{ $member->displayName() }}
                            @if ($server->isOwnedBy($user))
                                <span class="text-xs text-warn-fg" title="Server Owner">👑</span>
                            @endif
                        </span>
                        <span class="block truncate text-xs text-fg-subtle">{{ '@'.$user->username }}</span>
                        @if ($row['timedOutUntil'])
                            <span class="block text-xs text-warn-fg" data-timed-out>
                                Timed out until
                                <time datetime="{{ $row['timedOutUntil']->toIso8601String() }}">{{ $row['timedOutUntil']->toDayDateTimeString() }}</time>
                            </span>
                        @endif
                    </div>
                </div>

                @if ($row['canKick'] || $row['canBan'] || $row['canTimeout'])
                    <div class="mt-2 space-y-2 border-t border-surface-2 pt-2">
                        @if ($row['canTimeout'])
                            @if ($row['timedOutUntil'])
                                <form method="POST" action="{{ route('members.timeout.destroy', [$server, $user]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="{{ $button }} bg-[#4e5058] text-white hover:bg-[#6d6f78]">Remove timeout</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('members.timeout', [$server, $user]) }}" class="flex items-center gap-2">
                                    @csrf
                                    @method('PUT')
                                    <label for="timeout-{{ $user->id }}" class="sr-only">Timeout length for {{ $member->displayName() }}</label>
                                    <select id="timeout-{{ $user->id }}" name="duration" class="{{ $select }}">
                                        @foreach ($durations as $seconds => $label)
                                            <option value="{{ $seconds }}" @selected($seconds === 600)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="{{ $button }} bg-[#4e5058] text-white hover:bg-[#6d6f78]">Time out</button>
                                </form>
                            @endif
                        @endif

                        @if ($row['canKick'])
                            <form method="POST" action="{{ route('members.kick', [$server, $user]) }}">
                                @csrf
                                <button type="submit" class="{{ $button }} bg-[#4e5058] text-white hover:bg-[#da373c]">Kick</button>
                            </form>
                        @endif

                        @if ($row['canBan'])
                            <details>
                                <summary class="cursor-pointer text-xs font-medium text-danger-fg">Ban…</summary>
                                <form method="POST" action="{{ route('members.ban', [$server, $user]) }}" class="mt-2 space-y-2">
                                    @csrf
                                    <label for="ban-reason-{{ $user->id }}" class="block text-xs text-fg-muted">Reason (only moderators see this)</label>
                                    <input id="ban-reason-{{ $user->id }}" type="text" name="reason" maxlength="{{ $maxReasonLength }}"
                                        class="w-full rounded bg-surface-2 px-2 py-1 text-xs text-fg-strong outline-none focus:ring-2 focus:ring-[#5865f2]">
                                    <label for="ban-delete-{{ $user->id }}" class="block text-xs text-fg-muted">Delete message history</label>
                                    <select id="ban-delete-{{ $user->id }}" name="delete_messages" class="{{ $select }} w-full">
                                        @foreach ($deleteMessageOptions as $seconds => $label)
                                            <option value="{{ $seconds }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="{{ $button }} w-full bg-[#da373c] text-white hover:bg-[#a12828]">Ban {{ $member->displayName() }}</button>
                                </form>
                            </details>
                        @endif
                    </div>
                @endif
            </li>
        @endforeach
    </ul>

    <p class="space-x-3 text-sm text-fg-subtle">
        @can('manageBans', $server)
            <a href="{{ route('bans.index', $server) }}" class="text-link hover:underline">Bans</a>
        @endcan
        <a href="{{ route('servers.show', $server) }}" class="text-link hover:underline">Back to server</a>
    </p>
</x-layouts.auth>
