{{--
    Shared role form fields (ISSUE-26). @everyone ($role->is_default) only
    shows the permission checkboxes.

    @var \App\Models\Role|null $role
    @var list<\App\Enums\Permission> $permissions
--}}
@php
    $role ??= null;
    $isDefault = $role?->is_default ?? false;
    // After a failed submit the old input wins, also for boxes that were unticked.
    $submitted = session()->hasOldInput();
    $checked = $submitted
        ? array_map('intval', array_filter((array) old('permissions', []), 'is_numeric'))
        : array_map(fn (\App\Enums\Permission $p): int => $p->value, $role?->permissionList() ?? []);
    $color = old('color', $role?->displayColor() ?? '#99aab5');
    $color = is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#99aab5';
    $noColor = $submitted ? (bool) old('no_color') : ($role !== null && $role->displayColor() === null);
@endphp

@unless ($isDefault)
    <x-auth.field name="name" label="Role name" required autocomplete="off"
        maxlength="{{ \App\Models\Role::MAX_NAME_LENGTH }}"
        :value="old('name', $role?->name)" />

    <div class="mb-4">
        <label for="color" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-[#b5bac1]">Role colour</label>
        <div class="flex items-center gap-3">
            <input id="color" name="color" type="color" value="{{ $color }}"
                @error('color') aria-invalid="true" aria-describedby="color-error" @enderror
                class="h-9 w-14 cursor-pointer rounded bg-[#1e1f22] p-1">
            <label class="flex items-center gap-2 text-sm text-[#b5bac1]">
                <input type="checkbox" name="no_color" value="1" class="accent-[#5865f2]" @checked($noColor)>
                No colour
            </label>
        </div>
        @error('color')
            <p id="color-error" class="mt-1 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>
@endunless

<fieldset class="mb-6">
    <legend class="mb-2 text-xs font-semibold uppercase tracking-wide text-[#b5bac1]">Permissions</legend>
    @if ($isDefault)
        <p class="mb-3 text-sm text-[#b5bac1]">Every member of the server has these permissions.</p>
    @endif
    @error('permissions')
        <p class="mb-2 text-sm text-red-400">{{ $message }}</p>
    @enderror
    @error('permissions.*')
        <p class="mb-2 text-sm text-red-400">{{ $message }}</p>
    @enderror

    <ul class="divide-y divide-[#3f4147]">
        @foreach ($permissions as $permission)
            <li class="py-2">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="permissions[]" value="{{ $permission->value }}"
                        class="mt-1 accent-[#5865f2]" data-permission="{{ $permission->name }}"
                        @checked(in_array($permission->value, $checked, true))>
                    <span>
                        <span class="block text-sm font-medium text-white">{{ $permission->label() }}</span>
                        <span class="block text-xs text-[#949ba4]">{{ $permission->description() }}</span>
                    </span>
                </label>
            </li>
        @endforeach
    </ul>
</fieldset>
