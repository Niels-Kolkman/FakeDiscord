{{--
    Right-hand member sidebar (ISSUE-13): online members under their highest
    role or "Online", everyone else under "Offline". See App\Support\MemberList.

    Online presence (ISSUE-31): who is online comes from the server's Reverb
    presence channel, and resources/js/presence.js keeps the list live. For
    that every possible group is rendered (empty ones hidden), each role
    group carries its colour in data-role-color, and each member carries
    their id, the status they picked (data-user-status), the status shown
    (data-presence), their role group (data-role-group, "online" without a
    role) and their sort key.

    @var \App\Models\Server $server
    @var \App\Support\MemberList $members
--}}
@php($statusColors = ['online' => '#23a55a', 'idle' => '#f0b232', 'dnd' => '#f23f43'])
@php($statusLabels = \App\Models\User::STATUS_LABELS)

<aside
    aria-label="Members of {{ $server->name }}"
    class="w-full shrink-0 overflow-y-auto bg-[#2b2d31] px-2 py-4 md:w-60"
    data-member-list
    data-server-id="{{ $server->id }}"
>
    <h2 class="sr-only" data-member-summary>Members ({{ $members->online }} online of {{ $members->total }})</h2>

    @foreach ($members->allGroups as $group)
        <section
            class="mb-4"
            data-member-group="{{ $group->key }}"
            aria-labelledby="member-group-{{ $group->key }}"
            @if ($group->color) data-role-color="{{ $group->color }}" @endif
            @if ($group->members->isEmpty()) hidden @endif
        >
            <h3 id="member-group-{{ $group->key }}" class="mb-1 px-2 text-xs font-semibold uppercase tracking-wide text-[#949ba4]" data-member-heading data-label="{{ $group->label }}">
                {{ $group->label }} — {{ $group->members->count() }}
            </h3>

            <ul class="space-y-0.5" data-member-items>
                @foreach ($group->members as $member)
                    @php($user = $member->user)
                    @php($status = $members->statusOf($member))
                    @php($online = $status !== 'offline')
                    @php($color = $online ? $group->color : null)
                    @php($roleGroup = $member->highestRole() ? 'role-'.$member->highestRole()->id : \App\Support\MemberGroup::ONLINE)
                    <li
                        class="flex items-center gap-3 rounded px-2 py-1 hover:bg-[#35373c] {{ $online ? '' : 'opacity-40 hover:opacity-100' }}"
                        data-member="{{ $user->username }}"
                        data-user-id="{{ $user->id }}"
                        data-user-status="{{ $user->chosenStatus() }}"
                        data-presence="{{ $status }}"
                        data-role-group="{{ $roleGroup }}"
                        data-sort-name="{{ mb_strtolower($member->displayName()) }}"
                    >
                        <span class="relative shrink-0">
                            <x-user-avatar :user="$user" :size="32" />
                            <span
                                class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-[3px] border-[#2b2d31]"
                                style="background-color: {{ $statusColors[$status] ?? '#80848e' }}"
                                aria-hidden="true"
                                data-status-dot
                            ></span>
                        </span>
                        <span class="flex min-w-0 flex-1 items-center gap-1">
                            <span class="truncate font-medium {{ $color ? '' : 'text-[#dbdee1]' }}" @if ($color) style="color: {{ $color }}" @endif data-member-name>{{ $member->displayName() }}</span>
                            @if ($server->isOwnedBy($user))
                                <span class="shrink-0 text-xs text-[#f0b232]" title="Server Owner" aria-label="Server Owner" data-owner-badge>👑</span>
                            @endif
                            <span class="sr-only" data-status-label>, {{ $statusLabels[$status] ?? 'Offline' }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</aside>
