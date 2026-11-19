{{--
    Checkboxes named user_ids[] for picking friends (ISSUE-24): used by
    "Create group DM" on the friends page and "Add friends" on a group page.
    Previously ticked boxes stay ticked after a validation error.

    @var \Illuminate\Support\Collection<int, \App\Models\User> $friends
    @var string $idPrefix  keeps the checkbox ids unique on the page
--}}
@php($picked = collect(old('user_ids', []))->map(fn ($id) => (int) $id)->all())
<fieldset class="max-h-60 overflow-y-auto rounded bg-surface-3 p-2" data-friend-picker>
    <legend class="sr-only">Friends</legend>
    <ul class="space-y-0.5">
        @foreach ($friends as $friend)
            <li data-pick-user="{{ $friend->id }}">
                <label for="{{ $idPrefix }}-{{ $friend->id }}" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-fg hover:bg-hover">
                    <input
                        id="{{ $idPrefix }}-{{ $friend->id }}"
                        type="checkbox"
                        name="user_ids[]"
                        value="{{ $friend->id }}"
                        @checked(in_array((int) $friend->id, $picked, true))
                        class="h-4 w-4 accent-[#5865f2]"
                    >
                    <x-user-avatar :user="$friend" :size="24" />
                    <span class="truncate">{{ $friend->name }}</span>
                    <span class="truncate text-sm text-fg-subtle">{{ '@'.$friend->username }}</span>
                </label>
            </li>
        @endforeach
    </ul>
</fieldset>
