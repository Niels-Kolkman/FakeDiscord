<x-layouts.auth title="My profile">
    <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PATCH')

        <div class="mb-6 flex items-center gap-4">
            <img
                id="avatar-preview"
                src="{{ $user->avatarUrl() }}"
                alt="Your avatar"
                width="80" height="80"
                class="h-20 w-20 rounded-full object-cover"
            >
            <div class="min-w-0 flex-1">
                <label for="avatar" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-[#b5bac1]">Avatar</label>
                <input
                    id="avatar"
                    name="avatar"
                    type="file"
                    accept="image/png,image/jpeg,image/gif,image/webp"
                    data-avatar-preview="#avatar-preview"
                    @error('avatar') aria-invalid="true" aria-describedby="avatar-error" @enderror
                    class="block w-full text-sm text-[#b5bac1] file:mr-3 file:rounded file:border-0 file:bg-[#4e5058] file:px-3 file:py-1.5 file:text-white hover:file:bg-[#6d6f78]"
                >
                <p class="mt-1 text-xs text-[#949ba4]">PNG, JPG, GIF or WebP, up to 4 MB. It is cropped to a square and resized to 256×256.</p>
                @error('avatar')
                    <p id="avatar-error" class="mt-1 text-sm text-red-400">{{ $message }}</p>
                @enderror

                @if ($user->avatar_path)
                    <label class="mt-2 flex items-center gap-2 text-sm text-[#b5bac1]">
                        <input type="checkbox" name="remove_avatar" value="1" class="accent-[#5865f2]">
                        Remove avatar
                    </label>
                @endif
            </div>
        </div>

        <x-auth.field name="name" label="Display name" :value="old('name', $user->name)" required maxlength="255" autocomplete="name" />

        <div class="mb-4">
            <label for="bio" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-[#b5bac1]">About me</label>
            <textarea
                id="bio"
                name="bio"
                rows="4"
                maxlength="{{ \App\Http\Requests\UpdateProfileRequest::MAX_BIO_LENGTH }}"
                @error('bio') aria-invalid="true" aria-describedby="bio-error" @enderror
                class="w-full resize-none rounded bg-[#1e1f22] px-3 py-2 text-white outline-none focus:ring-2 focus:ring-[#5865f2]"
            >{{ old('bio', $user->bio) }}</textarea>
            @error('bio')
                <p id="bio-error" class="mt-1 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Save changes</button>

        <p class="mt-4 text-sm text-[#949ba4]">
            <a href="{{ route('dashboard') }}" class="text-[#00a8fc] hover:underline">Back</a>
        </p>
    </form>
</x-layouts.auth>
