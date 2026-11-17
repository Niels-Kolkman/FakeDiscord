{{--
    Right-hand member sidebar (ISSUE-13): online members under their highest
    role or "Online", everyone else under "Offline". See App\Support\MemberList.

    @var \App\Models\Server $server
    @var \App\Support\MemberList $members
--}}
@php($statusColors = ['online' => '#23a55a', 'idle' => '#f0b232', 'dnd' => '#f23f43'])
@php($statusLabels = ['online' => 'Online', 'idle' => 'Idle', 'dnd' => 'Do Not Disturb'])

<aside
    aria-label="Members of {{ $server->name }}"
    class="w-full shrink-0 overflow-y-auto bg-[#2b2d31] px-2 py-4 md:w-60"
    data-member-list
>
    <h2 class="sr-only">Members ({{ $members->online }} online of {{ $members->total }})</h2>

    @foreach ($members->groups as $group)
        <section class="mb-4" data-member-group="{{ $group->key }}" aria-labelledby="member-group-{{ $group->key }}">
            <h3 id="member-group-{{ $group->key }}" class="mb-1 px-2 text-xs font-semibold uppercase tracking-wide text-[#949ba4]">
                {{ $group->label }} — {{ $group->members->count() }}
            </h3>

            <ul class="space-y-0.5">
                @foreach ($group->members as $member)
                    @php($user = $member->user)
                    @php($online = $user->isOnline())
                    {{-- Only a #rrggbb value goes into the style attribute. --}}
                    @php($color = $online ? $member->highestRole()?->color : null)
                    @php($color = is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : null)
                    <li
                        class="flex items-center gap-3 rounded px-2 py-1 hover:bg-[#35373c] {{ $online ? '' : 'opacity-40 hover:opacity-100' }}"
                        data-member="{{ $user->username }}"
                    >
                        <span class="relative shrink-0">
                            <x-user-avatar :user="$user" :size="32" />
                            <span
                                class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-[3px] border-[#2b2d31]"
                                style="background-color: {{ $statusColors[$user->status] ?? '#80848e' }}"
                                aria-hidden="true"
                            ></span>
                        </span>
                        <span class="flex min-w-0 flex-1 items-center gap-1">
                            <span class="truncate font-medium {{ $color ? '' : 'text-[#dbdee1]' }}" @if ($color) style="color: {{ $color }}" @endif>{{ $member->displayName() }}</span>
                            @if ($server->isOwnedBy($user))
                                <span class="shrink-0 text-xs text-[#f0b232]" title="Server Owner" aria-label="Server Owner" data-owner-badge>👑</span>
                            @endif
                            <span class="sr-only">, {{ $statusLabels[$user->status] ?? 'Offline' }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</aside>
