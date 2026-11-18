{{--
    Friends (ISSUE-22, App\Http\Controllers\FriendController). The tabs are
    links (?tab=all|pending|blocked) and every action is a plain form, so
    the page works without JavaScript.

    resources/js/friends.js reloads [data-friends-panel] (the tabs and the
    list) when App\Events\FriendshipChanged arrives, so a request, an
    accept or a removal by the other person shows up at once. The add
    friend form sits outside the panel, so what you are typing survives.

    @var string $tab
    @var int $incomingCount
    @var \Illuminate\Support\Collection<int, array{friendship: \App\Models\Friendship, user: \App\Models\User}> $friends
    @var \Illuminate\Support\Collection<int, array{friendship: \App\Models\Friendship, user: \App\Models\User}> $incoming
    @var \Illuminate\Support\Collection<int, array{friendship: \App\Models\Friendship, user: \App\Models\User}> $outgoing
    @var \Illuminate\Support\Collection<int, array{friendship: \App\Models\Friendship, user: \App\Models\User}> $blocked
--}}
<x-layouts.app title="Friends">
    <x-slot:sidebar>
        @include('friends.partials.sidebar')
    </x-slot:sidebar>

    <header class="flex min-h-12 items-center gap-2 border-b border-[#1f2023] px-4 py-2 shadow-sm">
        <span aria-hidden="true" class="text-xl">👋</span>
        <h1 class="font-semibold text-white">Friends</h1>
    </header>

    <div class="flex-1 overflow-y-auto px-4 py-4 md:px-8">
        <section aria-labelledby="add-friend-heading" class="mb-6 border-b border-[#3f4147] pb-6">
            <h2 id="add-friend-heading" class="mb-1 font-semibold uppercase tracking-wide text-white">Add friend</h2>
            <p class="mb-3 text-sm text-[#b5bac1]">You can add friends with their username.</p>

            <form method="POST" action="{{ route('friends.store') }}" data-add-friend-form>
                @csrf
                <label for="friend-username" class="sr-only">Username</label>
                <div class="flex flex-col gap-2 rounded-lg bg-[#1e1f22] p-2 focus-within:ring-2 focus-within:ring-[#5865f2] sm:flex-row">
                    <input
                        id="friend-username"
                        type="text"
                        name="username"
                        value="{{ old('username') }}"
                        maxlength="64"
                        required
                        autocomplete="off"
                        autocapitalize="none"
                        spellcheck="false"
                        placeholder="Enter a username"
                        @error('username') aria-invalid="true" aria-describedby="friend-username-error" @enderror
                        class="min-w-0 flex-1 bg-transparent px-2 py-1 text-white placeholder-[#949ba4] outline-none"
                    >
                    <button type="submit" class="rounded bg-[#5865f2] px-4 py-1.5 text-sm font-medium text-white hover:bg-[#4752c4] focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                        Send Friend Request
                    </button>
                </div>
                @error('username')
                    <p id="friend-username-error" role="alert" class="mt-2 text-sm text-[#fa777c]">{{ $message }}</p>
                @enderror
            </form>
        </section>

        @error('user')
            {{-- "Message" could not open a DM (ISSUE-23). --}}
            <p role="alert" class="mb-4 rounded bg-red-500/10 px-3 py-2 text-sm text-red-300" data-dm-error>{{ $message }}</p>
        @enderror

        {{-- Group DMs (ISSUE-24): pick up to MAX_PARTICIPANTS - 1 friends. Only on the All tab, next to the friends list. --}}
        @php($groupFriends = $tab === 'all' ? \App\Actions\Conversations\ManageGroupConversation::invitableFriends(auth()->user()) : collect())
        @if ($groupFriends->isNotEmpty())
            <details class="mb-6 border-b border-[#3f4147] pb-6" @if ($errors->has('user_ids') || $errors->has('name')) open @endif data-create-group>
                <summary class="cursor-pointer font-semibold uppercase tracking-wide text-white">Create group DM</summary>
                <form method="POST" action="{{ route('dms.groups.store') }}" class="mt-3 max-w-xl space-y-3">
                    @csrf
                    <p class="text-sm text-[#b5bac1]">Pick friends to talk to together. A group can have up to {{ \App\Models\Conversation::MAX_PARTICIPANTS }} people, you included.</p>
                    <div>
                        <label for="new-group-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-[#949ba4]">Group name (optional)</label>
                        <input
                            id="new-group-name"
                            type="text"
                            name="name"
                            value="{{ old('name') }}"
                            maxlength="{{ \App\Models\Conversation::NAME_MAX_LENGTH }}"
                            autocomplete="off"
                            @error('name') aria-invalid="true" aria-describedby="new-group-name-error" @enderror
                            class="w-full rounded bg-[#1e1f22] px-2 py-1.5 text-white placeholder-[#949ba4] outline-none focus:ring-2 focus:ring-[#5865f2]"
                        >
                        @error('name')
                            <p id="new-group-name-error" role="alert" class="mt-2 text-sm text-[#fa777c]">{{ $message }}</p>
                        @enderror
                    </div>
                    @include('conversations.partials.friend-picker', ['friends' => $groupFriends, 'idPrefix' => 'new-group-friend'])
                    @error('user_ids')
                        <p role="alert" class="text-sm text-[#fa777c]">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="rounded bg-[#5865f2] px-4 py-1.5 text-sm font-medium text-white hover:bg-[#4752c4] focus:outline-none focus-visible:ring-2 focus-visible:ring-white">Create group DM</button>
                </form>
            </details>
        @endif

        <div data-friends-panel>
            @php($tabs = ['all' => 'All', 'pending' => 'Pending', 'blocked' => 'Blocked'])
            <nav aria-label="Friends lists" class="mb-4">
                <ul class="flex flex-wrap gap-2">
                    @foreach ($tabs as $key => $label)
                        <li>
                            <a
                                href="{{ route('friends.index', ['tab' => $key]) }}"
                                @if ($tab === $key) aria-current="page" @endif
                                class="inline-flex items-center gap-1.5 rounded px-3 py-1 text-sm font-medium text-[#b5bac1] hover:bg-[#404249] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2] aria-[current=page]:bg-[#404249] aria-[current=page]:text-white"
                            >
                                {{ $label }}
                                @if ($key === 'pending' && $incomingCount > 0)
                                    <span class="rounded-full bg-[#da373c] px-1.5 text-xs font-semibold text-white">{{ $incomingCount }}<span class="sr-only"> incoming</span></span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            @if ($tab === 'all')
                @include('friends.partials.list', [
                    'heading' => 'All friends',
                    'rows' => $friends,
                    'kind' => 'friend',
                    'empty' => 'No friends yet. Add someone with their username above.',
                ])
            @elseif ($tab === 'pending')
                @if ($incoming->isEmpty() && $outgoing->isEmpty())
                    <p class="py-8 text-center text-[#949ba4]">There are no pending friend requests.</p>
                @else
                    @if ($incoming->isNotEmpty())
                        @include('friends.partials.list', ['heading' => 'Incoming', 'rows' => $incoming, 'kind' => 'incoming', 'empty' => ''])
                    @endif
                    @if ($outgoing->isNotEmpty())
                        @include('friends.partials.list', ['heading' => 'Outgoing', 'rows' => $outgoing, 'kind' => 'outgoing', 'empty' => ''])
                    @endif
                @endif
            @else
                @include('friends.partials.list', [
                    'heading' => 'Blocked',
                    'rows' => $blocked,
                    'kind' => 'blocked',
                    'empty' => 'You have not blocked anyone.',
                ])
            @endif
        </div>
    </div>
</x-layouts.app>
