<x-layouts.auth title="Create an account">
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <x-auth.field name="email" label="Email" type="email" required autofocus autocomplete="email" />
        <x-auth.field name="name" label="Display name" required autocomplete="name" />
        <x-auth.field name="username" label="Username" required autocomplete="username" maxlength="32" />
        <x-auth.field name="password" label="Password" type="password" required autocomplete="new-password" />
        <x-auth.field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Continue</button>

        <p class="mt-4 text-sm text-[#949ba4]">
            <a href="{{ route('login') }}" class="text-[#00a8fc] hover:underline">Already have an account?</a>
        </p>
    </form>
</x-layouts.auth>
