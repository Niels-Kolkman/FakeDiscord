<x-layouts.auth title="Forgot your password?">
    <p class="mb-4 text-sm text-fg-muted">
        Enter your email address and we'll send you a link to choose a new password.
    </p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <x-auth.field name="email" label="Email" type="email" required autofocus autocomplete="email" />

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Email reset link</button>

        <p class="mt-4 text-sm text-fg-subtle">
            <a href="{{ route('login') }}" class="text-link hover:underline">Back to login</a>
        </p>
    </form>
</x-layouts.auth>
