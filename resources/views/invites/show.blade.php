<x-layouts.auth title="You've been invited">
    <div class="mb-4 flex justify-center">
        <x-server-icon :server="$server" :size="80" />
    </div>

    <p class="mb-1 text-center text-sm text-[#b5bac1]">
        {{ $invite->inviter ? '@'.$invite->inviter->username.' invited you to join' : 'You have been invited to join' }}
    </p>
    <p class="mb-2 text-center text-xl font-semibold text-white">{{ $server->name }}</p>
    <p class="mb-6 text-center text-sm text-[#949ba4]">{{ $memberCount }} {{ \Illuminate\Support\Str::plural('member', $memberCount) }}</p>

    @if ($isMember)
        <a href="{{ route('servers.show', $server) }}" class="block w-full rounded bg-[#5865f2] py-2 text-center font-medium text-white hover:bg-[#4752c4]">You're already a member. Open server</a>
    @else
        <form method="POST" action="{{ route('invites.accept', $invite->code) }}">
            @csrf
            <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Accept invite</button>
        </form>
    @endif

    <p class="mt-4 text-sm text-[#949ba4]">
        <a href="{{ route('dashboard') }}" class="text-[#00a8fc] hover:underline">No thanks</a>
    </p>
</x-layouts.auth>
