@props(['name', 'label', 'type' => 'text', 'value' => null, 'bag' => 'default', 'id' => null])

@php($fieldId = $id ?? $name)

<div class="mb-4">
    <label for="{{ $fieldId }}" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-[#b5bac1]">
        {{ $label }}
    </label>
    <input
        id="{{ $fieldId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type !== 'password') value="{{ $value ?? old($name) }}" @endif
        @error($name, $bag) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror
        {{ $attributes->merge(['class' => 'w-full rounded bg-[#1e1f22] px-3 py-2 text-white outline-none focus:ring-2 focus:ring-[#5865f2]']) }}
    >
    @error($name, $bag)
        <p id="{{ $fieldId }}-error" class="mt-1 text-sm text-red-400">{{ $message }}</p>
    @enderror
</div>
