<x-layouts.auth title="Create text channel">
    <form method="POST" action="{{ route('channels.store', $server) }}">
        @csrf

        @include('channels.fields')

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Create channel</button>
    </form>

    <p class="mt-4 text-sm text-fg-subtle">
        <a href="{{ route('servers.show', $server) }}" class="text-link hover:underline">Back to {{ $server->name }}</a>
    </p>
</x-layouts.auth>
