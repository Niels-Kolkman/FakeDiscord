{{--
    The app's own logo mark: two overlapping speech bubbles.
    Drawn for this project; it is not based on any other chat app's logo.
    Decorative: whatever wraps it gives the accessible name.
--}}
@props(['size' => 28])

<svg
    aria-hidden="true"
    focusable="false"
    width="{{ (int) $size }}"
    height="{{ (int) $size }}"
    viewBox="0 0 32 32"
    fill="none"
    {{ $attributes->merge(['class' => 'shrink-0']) }}
    data-brand-mark
>
    {{-- Back bubble, tail to the bottom right. --}}
    <path d="M13 4h11a5 5 0 0 1 5 5v7a5 5 0 0 1-5 5h-1v4l-5-4h-5a5 5 0 0 1-5-5V9a5 5 0 0 1 5-5Z" fill="currentColor" opacity=".45"/>
    {{-- Front bubble, tail to the bottom left. --}}
    <path d="M7 10h11a4 4 0 0 1 4 4v6a4 4 0 0 1-4 4h-6l-5 4v-4a4 4 0 0 1-4-4v-6a4 4 0 0 1 4-4Z" fill="currentColor"/>
    <circle cx="9.5" cy="17" r="1.5" fill="#1e1f22"/>
    <circle cx="14.5" cy="17" r="1.5" fill="#1e1f22"/>
</svg>
