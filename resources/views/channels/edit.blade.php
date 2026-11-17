<x-layouts.auth title="Channel settings">
    <form method="POST" action="{{ route('channels.update', [$server, $channel]) }}">
        @csrf
        @method('PATCH')

        @include('channels.fields', ['selectedCategory' => $channel->category_id])

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Save changes</button>
    </form>

    <form method="POST" action="{{ route('channels.destroy', [$server, $channel]) }}" class="mt-8 border-t border-[#3f4147] pt-6"
        data-confirm="Delete #{{ $channel->name }}? Its messages are deleted too. This cannot be undone.">
        @csrf
        @method('DELETE')

        <h2 class="mb-2 font-semibold text-white">Delete channel</h2>
        <p class="mb-4 text-sm text-[#b5bac1]">
            This deletes <strong class="text-white">#{{ $channel->name }}</strong> and all its messages. It cannot be undone.
        </p>

        <button type="submit" class="w-full rounded bg-[#da373c] py-2 font-medium text-white hover:bg-[#a12828]">Delete channel</button>
    </form>

    <p class="mt-4 text-sm text-[#949ba4]">
        <a href="{{ route('channels.show', [$server, $channel]) }}" class="text-[#00a8fc] hover:underline">Back to #{{ $channel->name }}</a>
    </p>
</x-layouts.auth>
