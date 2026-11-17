{{-- Placeholder landing page after login; the real app shell comes in later stories. --}}
<x-layouts.auth title="You're logged in">
    <p class="mb-6 text-center">
        Signed in as <strong class="text-white">{{ auth()->user()?->name }}</strong>
        <span class="text-[#949ba4]">{{ '@'.auth()->user()?->username }}</span>
    </p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="w-full rounded bg-[#da373c] py-2 font-medium text-white hover:bg-[#a12828]">Log Out</button>
    </form>
</x-layouts.auth>
