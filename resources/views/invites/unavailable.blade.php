{{-- Expired or used-up invite (410), or the user is banned (403). --}}
<x-layouts.auth title="Invite invalid">
    <div role="alert" class="mb-6 rounded bg-red-900/40 px-3 py-2 text-center text-sm text-red-300">
        {{ $message }}
    </div>

    @unless ($reason === \App\Exceptions\InviteUnavailableException::BANNED)
        <p class="mb-6 text-center text-sm text-fg-muted">
            Ask someone in <strong class="text-fg-strong">{{ $server->name }}</strong> for a new invite.
        </p>
    @endunless

    <a href="{{ route('dashboard') }}" class="block w-full rounded bg-[#4e5058] py-2 text-center font-medium text-white hover:bg-[#6d6f78]">Back to dashboard</a>
</x-layouts.auth>
