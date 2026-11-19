{{--
    The theme toggle (ISSUE-37): switches between the dark and the light
    theme and saves the choice on the user (PUT /settings/theme), so every
    browser they sign in on gets it. A plain form that sends the other
    theme; resources/js/theme.js switches the page at once instead of
    reloading it, and keeps every toggle on the page (and the user's other
    tabs) in step.

    variant: "rail", a round button for the server rail; "button", a
    full-width one (the dashboard).
--}}
@props(['variant' => 'button'])

@auth
    @php
        $current = auth()->user()->preferredTheme();
        $next = $current === 'dark' ? 'light' : 'dark';
        $label = 'Switch to '.$next.' theme';
        $classes = $variant === 'rail'
            ? 'flex h-12 w-12 items-center justify-center rounded-full bg-surface text-xl leading-none text-fg-muted transition-colors hover:bg-[#5865f2] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2] focus-visible:ring-offset-2 focus-visible:ring-offset-surface-3'
            : 'flex w-full items-center justify-center gap-2 rounded bg-[#4e5058] py-2 font-medium text-white hover:bg-[#6d6f78] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]';
    @endphp
    <form method="POST" action="{{ route('theme.update') }}" {{ $attributes->merge(['class' => $variant === 'rail' ? 'flex shrink-0 justify-center' : '']) }} data-theme-toggle>
        @csrf
        @method('PUT')
        <input type="hidden" name="theme" value="{{ $next }}" data-theme-next>
        <button type="submit" class="{{ $classes }}" title="{{ $label }}" data-theme-button>
            {{-- The icon shows what you switch to. --}}
            <span aria-hidden="true" data-theme-icon>{{ $next === 'light' ? '☀️' : '🌙' }}</span>
            <span class="{{ $variant === 'rail' ? 'sr-only' : '' }}" data-theme-label>{{ $label }}</span>
        </button>
    </form>
@endauth
