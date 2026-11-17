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
            <label class="-mt-2 mb-4 flex items-center gap-2 text-sm text-[#b5bac1]">
                <input type="checkbox" name="remove_icon" value="1" class="accent-[#5865f2]">
                Remove icon
            </label>
        @endif

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Save changes</button>
    </form>

    @can('delete', $server)
        <form method="POST" action="{{ route('servers.destroy', $server) }}" class="mt-8 border-t border-[#3f4147] pt-6">
            @csrf
            @method('DELETE')

            <h2 class="mb-2 font-semibold text-white">Delete server</h2>
            <p class="mb-4 text-sm text-[#b5bac1]">
                This deletes <strong class="text-white">{{ $server->name }}</strong> with all its channels and messages for every member. It cannot be undone.
            </p>

            <x-auth.field name="confirm_name" label="Type the server name to confirm" value="" autocomplete="off" required />

            <button type="submit" class="w-full rounded bg-[#da373c] py-2 font-medium text-white hover:bg-[#a12828]">Delete server</button>
        </form>
    @endcan

    <p class="mt-4 text-sm text-[#949ba4]">
        <a href="{{ route('servers.show', $server) }}" class="text-[#00a8fc] hover:underline">Back to server</a>
    </p>
</x-layouts.auth>
