<x-layouts.auth title="Create a server">
    <p class="mb-6 text-center text-sm text-fg-muted">Give your server a name and an icon. You can change both later.</p>

    <form method="POST" action="{{ route('servers.store') }}" enctype="multipart/form-data">
        @csrf

        <x-auth.field name="name" label="Server name" required autofocus
            minlength="{{ \App\Http\Requests\StoreServerRequest::MIN_NAME_LENGTH }}"
            maxlength="{{ \App\Http\Requests\StoreServerRequest::MAX_NAME_LENGTH }}"
            :value="old('name', auth()->user()?->name.'\'s server')" />

        @include('servers.icon-field')

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Create</button>

        <p class="mt-4 text-sm text-fg-subtle">
            <a href="{{ route('dashboard') }}" class="text-link hover:underline">Back</a>
        </p>
    </form>
</x-layouts.auth>
