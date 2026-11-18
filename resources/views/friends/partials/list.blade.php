{{--
    One list on the friends page with its count, and the actions for its
    kind: friend (Remove), incoming (Accept / Decline), outgoing (Cancel),
    blocked (Unblock). Removing a friend asks for confirmation first
    (data-confirm, handled in resources/js/channel-sidebar.js).

    @var string $heading
    @var string $kind
    @var string $empty
    @var \Illuminate\Support\Collection<int, array{friendship: \App\Models\Friendship, user: \App\Models\User}> $rows
--}}
@php($button = 'rounded px-3 py-1 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]')
<section aria-labelledby="friends-{{ $kind }}-heading" class="mb-6" data-friends-list="{{ $kind }}">
    <h2 id="friends-{{ $kind }}-heading" class="mb-2 text-xs font-semibold uppercase tracking-wide text-[#949ba4]">
        {{ $heading }} — {{ $rows->count() }}
    </h2>

    @if ($rows->isEmpty())
        <p class="py-8 text-center text-[#949ba4]">{{ $empty }}</p>
    @else
        <ul class="divide-y divide-[#3f4147] border-t border-[#3f4147]">
            @foreach ($rows as $row)
                @php($person = $row['user'])
                @php($friendship = $row['friendship'])
                <li class="flex items-center gap-3 py-3" data-friendship-id="{{ $friendship->id }}">
                    <x-user-avatar :user="$person" :size="32" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-white">{{ $person->name }}</p>
                        <p class="truncate text-sm text-[#b5bac1]">
                            {{ '@'.$person->username }}
                            @if ($kind === 'friend')
                                · {{ $person->isOnline() ? 'Online' : 'Offline' }}
                            @elseif ($kind === 'incoming')
                                · Incoming friend request
                            @elseif ($kind === 'outgoing')
                                · Outgoing friend request
                            @endif
                        </p>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        @if ($kind === 'incoming')
                            <form method="POST" action="{{ route('friends.accept', $friendship) }}">
                                @csrf
                                <button type="submit" class="{{ $button }} bg-[#248046] text-white hover:bg-[#1a6334]" aria-label="Accept friend request from {{ $person->name }}">Accept</button>
                            </form>
                            <form method="POST" action="{{ route('friends.decline', $friendship) }}">
                                @csrf
                                <button type="submit" class="{{ $button }} bg-[#4e5058] text-white hover:bg-[#6d6f78]" aria-label="Decline friend request from {{ $person->name }}">Decline</button>
                            </form>
                        @elseif ($kind === 'outgoing')
                            <form method="POST" action="{{ route('friends.cancel', $friendship) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="{{ $button }} bg-[#4e5058] text-white hover:bg-[#6d6f78]" aria-label="Cancel friend request to {{ $person->name }}">Cancel</button>
                            </form>
                        @elseif ($kind === 'friend')
                            {{-- Opens (or creates) the 1:1 DM (ISSUE-23). --}}
                            <form method="POST" action="{{ route('dms.store') }}">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $person->id }}">
                                <button type="submit" class="{{ $button }} bg-[#4e5058] text-white hover:bg-[#6d6f78]" aria-label="Message {{ $person->name }}" data-message-friend>Message</button>
                            </form>
                            <form method="POST" action="{{ route('friends.destroy', $friendship) }}" data-confirm="Remove {{ $person->name }} from your friends?">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="{{ $button }} text-[#fa777c] hover:bg-[#da373c] hover:text-white" aria-label="Remove {{ $person->name }} from your friends">Remove</button>
                            </form>
                        @elseif ($kind === 'blocked')
                            <form method="POST" action="{{ route('friends.unblock', $friendship) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="{{ $button }} bg-[#4e5058] text-white hover:bg-[#6d6f78]" aria-label="Unblock {{ $person->name }}">Unblock</button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
