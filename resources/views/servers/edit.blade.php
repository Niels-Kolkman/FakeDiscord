<x-layouts.auth title="Server settings">
    <form method="POST" action="{{ route('servers.update', $server) }}" enctype="multipart/form-data">
        @csrf
        @method('PATCH')

        <div class="mb-6 flex justify-center">
            <x-server-icon :server="$server" :size="80" />
        </div>

        <x-auth.field name="name" label="Server name" required
            minlength="{{ \App\Http\Requests\StoreServerRequest::MIN_NAME_LENGTH }}"
            maxlength="{{ \App\Http\Requests\StoreServerRequest::MAX_NAME_LENGTH }}"
            :value="old('name', $server->name)" />

        @include('servers.icon-field')

        @if ($server->icon_path)
            <label class="-mt-2 mb-4 flex items-center gap-2 text-sm text-fg-muted">
                <input type="checkbox" name="remove_icon" value="1" class="accent-[#5865f2]">
                Remove icon
            </label>
        @endif

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Save changes</button>
    </form>

    @can('manageRoles', $server)
        <section class="mt-8 border-t border-line pt-6">
            <h2 class="mb-2 font-semibold text-fg-strong">Roles</h2>
            <p class="mb-4 text-sm text-fg-muted">Create roles with their own colour and permissions, and give them to members.</p>
            <a href="{{ route('roles.index', $server) }}"
                class="block w-full rounded bg-[#4e5058] py-2 text-center font-medium text-white hover:bg-[#6d6f78]">Manage roles</a>
        </section>
    @endcan

    @can('transferOwnership', $server)
        @php($bag = \App\Http\Requests\TransferServerOwnershipRequest::ERROR_BAG)
        <form method="POST" action="{{ route('servers.owner.update', $server) }}" id="transfer-ownership" class="mt-8 border-t border-line pt-6">
            @csrf
            @method('PATCH')

            <h2 class="mb-2 font-semibold text-fg-strong">Transfer ownership</h2>

            @if ($transferCandidates->isEmpty())
                <p class="text-sm text-fg-muted">There are no other members to transfer this server to. Invite someone first.</p>
            @else
                <p class="mb-4 text-sm text-fg-muted">
                    The new owner gets full control of <strong class="text-fg-strong">{{ $server->name }}</strong>. You stay a member and can then leave.
                </p>

                <div class="mb-4">
                    <label for="new_owner_id" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-fg-muted">New owner</label>
                    <select id="new_owner_id" name="new_owner_id" required
                        class="w-full rounded bg-surface-3 px-3 py-2 text-fg-strong outline-none focus:ring-2 focus:ring-[#5865f2]"
                        @error('new_owner_id', $bag) aria-invalid="true" aria-describedby="new_owner_id-error" @enderror>
                        <option value="">Choose a member</option>
                        @foreach ($transferCandidates as $member)
                            <option value="{{ $member->user_id }}" @selected((string) old('new_owner_id') === (string) $member->user_id)>
                                {{ $member->displayName() }} ({{ '@'.$member->user->username }})
                            </option>
                        @endforeach
                    </select>
                    @error('new_owner_id', $bag)
                        <p id="new_owner_id-error" class="mt-1 text-sm text-danger-fg">{{ $message }}</p>
                    @enderror
                </div>

                <x-auth.field name="confirm_name" id="transfer_confirm_name" :bag="$bag" label="Type the server name to confirm" value="" autocomplete="off" required />

                <button type="submit" class="w-full rounded bg-[#da373c] py-2 font-medium text-white hover:bg-[#a12828]">Transfer ownership</button>
            @endif
        </form>
    @endcan

    @can('delete', $server)
        <form method="POST" action="{{ route('servers.destroy', $server) }}" class="mt-8 border-t border-line pt-6">
            @csrf
            @method('DELETE')

            <h2 class="mb-2 font-semibold text-fg-strong">Delete server</h2>
            <p class="mb-4 text-sm text-fg-muted">
                This deletes <strong class="text-fg-strong">{{ $server->name }}</strong> with all its channels and messages for every member. It cannot be undone.
            </p>

            <x-auth.field name="confirm_name" label="Type the server name to confirm" value="" autocomplete="off" required />

            <button type="submit" class="w-full rounded bg-[#da373c] py-2 font-medium text-white hover:bg-[#a12828]">Delete server</button>
        </form>
    @endcan

    <p class="mt-4 text-sm text-fg-subtle">
        <a href="{{ route('servers.show', $server) }}" class="text-link hover:underline">Back to server</a>
    </p>
</x-layouts.auth>
