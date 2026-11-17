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

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="w-full rounded bg-[#da373c] py-2 font-medium text-white hover:bg-[#a12828]">Log Out</button>
    </form>
</x-layouts.auth>
