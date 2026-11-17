<x-layouts.auth title="Create category">
    <form method="POST" action="{{ route('categories.store', $server) }}">
        @csrf

        <x-auth.field name="name" label="Category name" required autocomplete="off"
            maxlength="{{ \App\Models\ChannelCategory::MAX_NAME_LENGTH }}" />

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Create category</button>
    </form>

    <p class="mt-4 text-sm text-[#949ba4]">
        <a href="{{ route('servers.show', $server) }}" class="text-[#00a8fc] hover:underline">Back to {{ $server->name }}</a>
    </p>
</x-layouts.auth>
