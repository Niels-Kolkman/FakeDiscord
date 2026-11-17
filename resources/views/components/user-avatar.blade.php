{{-- A user's avatar. With :popover="true" (the default) clicking it opens the profile popover (resources/js/profile-popover.js). --}}
@props(['user', 'size' => 40, 'popover' => true])

@if ($popover)
    <button
        type="button"
        data-profile-url="{{ route('users.profile', $user) }}"
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-label="View profile of {{ $user->name }}"
        {{ $attributes->merge(['class' => 'inline-block shrink-0 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]']) }}
    >
        <img src="{{ $user->avatarUrl() }}" alt="" width="{{ (int) $size }}" height="{{ (int) $size }}" class="rounded-full object-cover" style="width: {{ (int) $size }}px; height: {{ (int) $size }}px">
    </button>
@else
    <img src="{{ $user->avatarUrl() }}" alt="{{ $user->name }}" width="{{ (int) $size }}" height="{{ (int) $size }}" {{ $attributes->merge(['class' => 'inline-block shrink-0 rounded-full object-cover']) }} style="width: {{ (int) $size }}px; height: {{ (int) $size }}px">
@endif
