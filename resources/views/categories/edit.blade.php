<x-layouts.auth title="Category settings">
    <form method="POST" action="{{ route('categories.update', [$server, $category]) }}">
        @csrf
        @method('PATCH')

        <x-auth.field name="name" label="Category name" required autocomplete="off"
            maxlength="{{ \App\Models\ChannelCategory::MAX_NAME_LENGTH }}"
            :value="old('name', $category->name)" />

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Save changes</button>
    </form>

    <form method="POST" action="{{ route('categories.destroy', [$server, $category]) }}" class="mt-8 border-t border-[#3f4147] pt-6"
        data-confirm="Delete the category {{ $category->name }}? Its channels are kept.">
        @csrf
        @method('DELETE')

        <h2 class="mb-2 font-semibold text-white">Delete category</h2>
        <p class="mb-4 text-sm text-[#b5bac1]">
            The channels in <strong class="text-white">{{ $category->name }}</strong> are kept and move out of the category.
        </p>

        <button type="submit" class="w-full rounded bg-[#da373c] py-2 font-medium text-white hover:bg-[#a12828]">Delete category</button>
    </form>

    <p class="mt-4 text-sm text-[#949ba4]">
        <a href="{{ route('servers.show', $server) }}" class="text-[#00a8fc] hover:underline">Back to {{ $server->name }}</a>
    </p>
</x-layouts.auth>
