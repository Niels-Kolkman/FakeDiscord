{{--
    Edit a role (ISSUE-26): its fields, who has it, and deleting it.
    @everyone only has its permissions.

    @var \App\Models\Server $server
    @var \App\Models\Role $role
    @var list<\App\Enums\Permission> $permissions
    @var \Illuminate\Support\Collection<int, \App\Models\ServerMember> $holders
    @var \Illuminate\Support\Collection<int, \App\Models\ServerMember> $candidates
--}}
<x-layouts.auth :title="'Role: '.$role->name">
    <form method="POST" action="{{ route('roles.update', [$server, $role]) }}">
        @csrf
        @method('PATCH')

        @include('roles.fields', ['role' => $role])

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Save changes</button>
    </form>

    @unless ($role->is_default)
        <section id="role-members" aria-labelledby="role-members-heading" class="mt-8 border-t border-line pt-6">
            <h2 id="role-members-heading" class="mb-2 font-semibold text-fg-strong">Members ({{ $holders->count() }})</h2>

            @if ($candidates->isNotEmpty())
                <form method="POST" action="{{ route('roles.members.store', [$server, $role]) }}" class="mb-4 flex gap-2">
                    @csrf
                    <label for="user_id" class="sr-only">Member to give this role</label>
                    <select id="user_id" name="user_id" required
                        @error('user_id') aria-invalid="true" aria-describedby="user_id-error" @enderror
                        class="min-w-0 flex-1 rounded bg-surface-3 px-3 py-2 text-fg-strong outline-none focus:ring-2 focus:ring-[#5865f2]">
                        <option value="">Add a member</option>
                        @foreach ($candidates as $member)
                            <option value="{{ $member->user_id }}">{{ $member->displayName() }} ({{ '@'.$member->user->username }})</option>
                        @endforeach
                    </select>
                    <button type="submit" class="rounded bg-[#5865f2] px-3 py-2 text-sm font-medium text-white hover:bg-[#4752c4]">Add</button>
                </form>
            @endif
            @error('user_id')
                <p id="user_id-error" class="mb-4 text-sm text-danger-fg">{{ $message }}</p>
            @enderror

            @if ($holders->isEmpty())
                <p class="text-sm text-fg-muted">Nobody has this role yet.</p>
            @else
                <ul class="space-y-2">
                    @foreach ($holders as $member)
                        <li class="flex items-center justify-between gap-3 rounded bg-surface-3 px-3 py-2 text-sm" data-role-member="{{ $member->user->username }}">
                            <span class="min-w-0 truncate text-fg-strong">
                                {{ $member->displayName() }} <span class="text-fg-subtle">{{ '@'.$member->user->username }}</span>
                            </span>
                            <form method="POST" action="{{ route('roles.members.destroy', [$server, $role, $member->user]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" aria-label="Remove {{ $member->displayName() }} from {{ $role->name }}"
                                    class="rounded bg-[#4e5058] px-2 py-1 text-xs font-medium text-white hover:bg-[#6d6f78]">Remove</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <form method="POST" action="{{ route('roles.destroy', [$server, $role]) }}" class="mt-8 border-t border-line pt-6"
            data-confirm="Delete the role {{ $role->name }}?">
            @csrf
            @method('DELETE')

            <h2 class="mb-2 font-semibold text-fg-strong">Delete role</h2>
            <p class="mb-4 text-sm text-fg-muted">
                Every member with <strong class="text-fg-strong">{{ $role->name }}</strong> loses it. This cannot be undone.
            </p>

            <button type="submit" class="w-full rounded bg-[#da373c] py-2 font-medium text-white hover:bg-[#a12828]">Delete role</button>
        </form>
    @endunless

    <p class="mt-4 text-sm text-fg-subtle">
        <a href="{{ route('roles.index', $server) }}" class="text-link hover:underline">Back to roles</a>
    </p>
</x-layouts.auth>
