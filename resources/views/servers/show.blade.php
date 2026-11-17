{{-- Placeholder server page until the app shell and channel views arrive (ISSUE-12). --}}
<x-layouts.auth :title="$server->name">
    <div class="mb-6 flex justify-center">
        <x-server-icon :server="$server" :size="80" />
    </div>

    <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-[#949ba4]">Text channels</h2>
    <ul class="mb-6 space-y-1">
        @foreach ($channels as $channel)
            <li class="rounded px-2 py-1 text-[#dbdee1] hover:bg-[#35373c]"><span class="text-[#80848e]">#</span> {{ $channel->name }}</li>
        @endforeach
    </ul>

    @can('update', $server)
        <a href="{{ route('servers.edit', $server) }}" class="mb-3 block w-full rounded bg-[#4e5058] py-2 text-center font-medium text-white hover:bg-[#6d6f78]">Server settings</a>
    @endcan

    <p class="text-sm text-[#949ba4]">
        <a href="{{ route('dashboard') }}" class="text-[#00a8fc] hover:underline">Back</a>
    </p>
</x-layouts.auth>
