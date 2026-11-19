{{--
    Confirm leaving a server (ISSUE-13). The owner is told to transfer
    ownership first instead.

    @var \App\Models\Server $server
    @var \Illuminate\Auth\Access\Response $decision
--}}
<x-layouts.auth title="Leave '{{ $server->name }}'">
    <div class="mb-6 flex justify-center">
        <x-server-icon :server="$server" :size="80" />
    </div>

    @if ($decision->allowed())
        <p class="mb-6 text-sm text-fg-muted">
            Are you sure you want to leave <strong class="text-fg-strong">{{ $server->name }}</strong>?
            You won't be able to rejoin unless you are re-invited.
        </p>

        <form method="POST" action="{{ route('servers.leave', $server) }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="w-full rounded bg-[#da373c] py-2 font-medium text-white hover:bg-[#a12828]">Leave server</button>
        </form>
    @else
        <p class="mb-6 rounded bg-yellow-900/40 px-3 py-2 text-sm text-yellow-200 in-data-[theme=light]:bg-yellow-100 in-data-[theme=light]:text-yellow-900" role="alert">
            {{ $decision->message() }}
        </p>

        @can('transferOwnership', $server)
            <a href="{{ route('servers.edit', $server) }}#transfer-ownership" class="block w-full rounded bg-[#5865f2] py-2 text-center font-medium text-white hover:bg-[#4752c4]">Transfer ownership</a>
        @endcan
    @endif

    <p class="mt-4 text-sm text-fg-subtle">
        <a href="{{ route('servers.show', $server) }}" class="text-link hover:underline">Cancel</a>
    </p>
</x-layouts.auth>
