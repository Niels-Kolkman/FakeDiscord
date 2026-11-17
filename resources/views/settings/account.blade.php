{{-- Account settings (ISSUE-9). Every form re-asks for the current password. --}}
@php($user = auth()->user())

<x-layouts.auth title="My Account">
    <section aria-labelledby="email-heading" class="mb-8">
        <h2 id="email-heading" class="mb-1 text-lg font-semibold text-white">Email</h2>
        <p class="mb-4 text-sm text-[#949ba4]">
            Currently <strong class="text-[#dbdee1]">{{ $user?->email }}</strong>
            ({{ $user?->hasVerifiedEmail() ? 'verified' : 'not verified' }}).
            A new address has to be verified again.
        </p>

        <form method="POST" action="{{ route('settings.account.email') }}">
            @csrf
            @method('PUT')

            <x-auth.field name="email" label="New email" type="email" bag="updateEmail" :value="old('email', $user?->email)" required autocomplete="email" />
            <x-auth.field name="current_password" id="email_current_password" label="Current password" type="password" bag="updateEmail" required autocomplete="current-password" />

            <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Change email</button>
        </form>
    </section>

    <section aria-labelledby="password-heading" class="mb-8">
        <h2 id="password-heading" class="mb-4 text-lg font-semibold text-white">Password</h2>

        <form method="POST" action="{{ route('user-password.update') }}">
            @csrf
            @method('PUT')

            <x-auth.field name="current_password" id="password_current_password" label="Current password" type="password" bag="updatePassword" required autocomplete="current-password" />
            <x-auth.field name="password" label="New password" type="password" bag="updatePassword" required autocomplete="new-password" />
            <x-auth.field name="password_confirmation" label="Confirm new password" type="password" bag="updatePassword" required autocomplete="new-password" />

            <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Change password</button>
        </form>
    </section>

    <section aria-labelledby="delete-heading" class="rounded border border-[#da373c]/60 p-4">
        <h2 id="delete-heading" class="mb-1 text-lg font-semibold text-white">Delete account</h2>
        <p class="mb-4 text-sm text-[#949ba4]">
            This cannot be undone. Your messages stay in their channels and DMs, shown as from "Deleted User".
            If you own servers, transfer or delete them first.
        </p>

        <form method="POST" action="{{ route('settings.account.destroy') }}">
            @csrf
            @method('DELETE')

            <x-auth.field name="password" id="delete_password" label="Password" type="password" bag="deleteAccount" required autocomplete="current-password" />

            <button type="submit" class="w-full rounded bg-[#da373c] py-2 font-medium text-white hover:bg-[#a12828]">Delete account</button>
        </form>
    </section>

    <p class="mt-6 text-center">
        <a href="{{ route('dashboard') }}" class="text-sm font-medium text-[#00a8fc] hover:underline">Back</a>
    </p>
</x-layouts.auth>
