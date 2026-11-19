<x-layouts.auth title="You've been invited">
    <div class="mb-4 flex justify-center">
        <x-server-icon :server="$server" :size="80" />
    </div>

    <p class="mb-1 text-center text-sm text-fg-muted">
        {{ $invite->inviter ? '@'.$invite->inviter->username.' invited you to join' : 'You have been invited to join' }}
    </p>
    <p class="mb-2 text-center text-xl font-semibold text-fg-strong">{{ $server->name }}</p>
    <p class="mb-6 text-center text-sm text-fg-subtle">{{ $memberCount }} {{ \Illuminate\Support\Str::plural('member', $memberCount) }}</p>

    @if ($isMember)
        <a href="{{ route('servers.show', $server) }}" class="block w-full rounded bg-[#5865f2] py-2 text-center font-medium text-white hover:bg-[#4752c4]">You're already a member. Open server</a>
    @else
        <form method="POST" action="{{ route('invites.accept', $invite->code) }}">
            @csrf
            <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Accept invite</button>
        </form>
    @endif

    <p class="mt-4 text-sm text-fg-subtle">
        <a href="{{ route('dashboard') }}" class="text-link hover:underline">No thanks</a>
    </p>
</x-layouts.auth>
