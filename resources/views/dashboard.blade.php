{{-- Placeholder landing page after login; the real app shell comes in later stories. --}}
<x-layouts.auth title="You're logged in">
    <div class="mb-6 flex items-center justify-center gap-3">
        <x-user-avatar :user="auth()->user()" :size="48" />
        <p>
            Signed in as <strong class="text-white">{{ auth()->user()?->name }}</strong>
            <span class="text-[#949ba4]">{{ '@'.auth()->user()?->username }}</span>
        </p>
    </div>

    <a href="{{ route('profile.edit') }}" class="mb-3 block w-full rounded bg-[#4e5058] py-2 text-center font-medium text-white hover:bg-[#6d6f78]">Edit profile</a>

    @if (request()->query('verified'))
        <div role="status" class="mb-4 rounded bg-green-900/40 px-3 py-2 text-sm text-green-300">
            Your email address is verified.
        </div>
    @endif

    @unless (auth()->user()?->hasVerifiedEmail())
        <div class="mb-6 rounded bg-yellow-900/40 px-3 py-2 text-sm text-yellow-200">
            <p class="mb-2">Verify your email address before you join servers. Check your inbox for the link.</p>
            <a href="{{ route('verification.notice') }}" class="font-medium text-[#00a8fc] hover:underline">Resend verification email</a>
        </div>
    @endunless

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="w-full rounded bg-[#da373c] py-2 font-medium text-white hover:bg-[#a12828]">Log Out</button>
    </form>
</x-layouts.auth>
