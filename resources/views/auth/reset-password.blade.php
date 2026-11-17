<x-layouts.auth title="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-auth.field name="email" label="Email" type="email" :value="old('email', $request->string('email')->value())" required autocomplete="email" />
        <x-auth.field name="password" label="New password" type="password" required autofocus autocomplete="new-password" />
        <x-auth.field name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Reset password</button>
    </form>
</x-layouts.auth>
