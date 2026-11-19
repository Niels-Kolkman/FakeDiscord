{{--
    Full-screen app shell (ISSUE-35), four panes from left to right:
    the server rail (components/server-rail.blade.php, for verified users,
    who are the only ones who can open servers), the sidebar slot (a
    server's channels, or friends and DMs), the page content, and the
    optional aside slot (a server's member list).

    Small screens (ISSUE-37, below md / 768px): the rail and the sidebar
    are one drawer ([data-drawer="nav"]) that slides in from the left, the
    member list another ([data-drawer="members"]) from the right. The bar
    on top of the page ([data-mobile-bar]) opens them, and so do swipes
    (resources/js/mobile-nav.js); the drawer CSS is in resources/css/app.css.
    Both only apply with JavaScript (the .js class on <html>); without it
    the panes stack, the rail first.

    <html data-theme> is the signed-in user's theme (ISSUE-37, users.theme),
    dark for guests.
--}}
@props(['title'])

@php
    $hasRail = (bool) auth()->user()?->hasVerifiedEmail();
    $hasNav = $hasRail || isset($sidebar);
    $hasMembers = isset($aside);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ auth()->user()?->preferredTheme() ?? 'dark' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @include('partials.user-meta')

        <title>{{ $title }} · {{ config('app.name', 'Laravel') }}</title>

        {{-- Before any CSS applies, so small screens get the drawers without a flash of stacked panes. --}}
        <script>document.documentElement.classList.add('js');</script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @if (file_exists(public_path('build/manifest.json')) || Vite::isRunningHot())
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="flex min-h-screen flex-col bg-surface font-sans text-fg antialiased md:h-screen md:flex-row md:overflow-hidden" data-app-shell>
        {{-- Skip link (ISSUE-38): the first Tab stop, past the rail and the sidebar. --}}
        <a href="#main-content"
            class="sr-only rounded bg-[#5865f2] px-3 py-2 text-sm font-medium text-white focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
            data-skip-link
        >Skip to content</a>

        @if ($hasNav)
            <div id="app-nav-drawer" class="flex shrink-0 bg-surface-2" data-drawer="nav">
                @if ($hasRail)
                    <x-server-rail />
                @endif

                @isset($sidebar)
                    {{ $sidebar }}
                @endisset
            </div>
        @endif

        {{-- tabindex="-1": the skip link and a channel switch (resources/js/keyboard-shortcuts.js) can focus it. --}}
        <main id="main-content" tabindex="-1" class="flex min-w-0 flex-1 flex-col focus:outline-none" data-app-main>
            @if ($hasNav || $hasMembers)
                {{-- Small screens with JavaScript only (resources/css/app.css). --}}
                <div class="hidden min-h-11 shrink-0 items-center gap-1 border-b border-edge bg-surface-2 px-1" data-mobile-bar>
                    @if ($hasNav)
                        <button type="button"
                            class="rounded p-2 text-lg leading-none text-fg-muted hover:bg-hover hover:text-fg-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]"
                            aria-controls="app-nav-drawer" aria-expanded="false"
                            data-drawer-toggle="nav"
                        ><span aria-hidden="true">☰</span><span class="sr-only">Open navigation</span></button>
                    @endif
                    <span class="min-w-0 flex-1 truncate px-1 text-sm font-semibold text-fg-strong">{{ $title }}</span>
                    @if ($hasMembers)
                        <button type="button"
                            class="rounded p-2 text-lg leading-none text-fg-muted hover:bg-hover hover:text-fg-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]"
                            aria-controls="app-members-drawer" aria-expanded="false"
                            data-drawer-toggle="members"
                        ><span aria-hidden="true">👥</span><span class="sr-only">Open member list</span></button>
                    @endif
                </div>
            @endif

            @if (session('status'))
                <div role="status" class="mx-4 mt-3 rounded bg-green-900/40 px-3 py-2 text-sm text-green-300 in-data-[theme=light]:bg-green-100 in-data-[theme=light]:text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </main>

        @if ($hasMembers)
            <div id="app-members-drawer" class="flex shrink-0 bg-surface-2" data-drawer="members">
                {{ $aside }}
            </div>
        @endif

        @if ($hasNav || $hasMembers)
            {{-- Behind an open drawer on small screens; a tap closes it. --}}
            <div class="fixed inset-0 z-30 bg-black/60 md:hidden" hidden data-drawer-backdrop></div>
        @endif
    </body>
</html>
