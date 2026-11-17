<x-layouts.auth title="Welcome back!">
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <x-auth.field name="email" label="Email" type="email" required autofocus autocomplete="email" />
        <x-auth.field name="password" label="Password" type="password" required autocomplete="current-password" />

        <div class="mb-4 flex items-center justify-between text-sm">
            <label class="flex items-center gap-2">
                <input type="checkbox" name="remember" @checked(old('remember'))>
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="text-[#00a8fc] hover:underline">Forgot your password?</a>
        </div>

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Log In</button>

        <p class="mt-4 text-sm text-[#949ba4]">
            Need an account? <a href="{{ route('register') }}" class="text-[#00a8fc] hover:underline">Register</a>
        </p>
    </form>
</x-layouts.auth>
