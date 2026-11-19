<x-layouts.auth title="Verify your email">
    <p class="mb-4 text-sm text-fg-muted">
        We sent a verification link to <strong class="text-fg-strong">{{ auth()->user()?->email }}</strong>.
        Click the link in that email to verify your address. You need a verified email to join servers.
    </p>

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Resend verification email</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-sm text-link hover:underline">Log out</button>
    </form>
</x-layouts.auth>
