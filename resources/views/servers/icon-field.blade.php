{{-- Icon upload input shared by the create and settings forms. --}}
<div class="mb-4">
    <label for="icon" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-fg-muted">Icon <span class="font-normal normal-case text-fg-subtle">(optional)</span></label>
    <input
        id="icon"
        name="icon"
        type="file"
        accept="image/png,image/jpeg,image/gif,image/webp"
        @error('icon') aria-invalid="true" aria-describedby="icon-error" @enderror
        class="block w-full text-sm text-fg-muted file:mr-3 file:rounded file:border-0 file:bg-[#4e5058] file:px-3 file:py-1.5 file:text-white hover:file:bg-[#6d6f78]"
    >
    <p class="mt-1 text-xs text-fg-subtle">PNG, JPG, GIF or WebP, up to 4 MB. It is cropped to a square and resized to 256×256.</p>
    @error('icon')
        <p id="icon-error" class="mt-1 text-sm text-danger-fg">{{ $message }}</p>
    @enderror
</div>
