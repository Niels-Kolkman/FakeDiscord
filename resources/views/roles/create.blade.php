<x-layouts.auth title="Create role">
    <form method="POST" action="{{ route('roles.store', $server) }}">
        @csrf

        @include('roles.fields', ['role' => null])

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Create role</button>
    </form>

    <p class="mt-4 text-sm text-fg-subtle">
        <a href="{{ route('roles.index', $server) }}" class="text-link hover:underline">Back to roles</a>
    </p>
</x-layouts.auth>
