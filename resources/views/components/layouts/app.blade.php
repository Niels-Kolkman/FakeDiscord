{{--
    Full-screen app shell (ISSUE-35), four panes from left to right:
    the server rail (components/server-rail.blade.php, for verified users,
    who are the only ones who can open servers), the sidebar slot (a
    server's channels, or friends and DMs), the page content, and the
    optional aside slot (a server's member list). On small screens they
    stack, the rail becoming a row on top.
--}}
@props(['title'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @include('partials.user-meta')

        <title>{{ $title }} · {{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @if (file_exists(public_path('build/manifest.json')) || Vite::isRunningHot())
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="flex min-h-screen flex-col bg-[#313338] font-sans text-[#dbdee1] antialiased md:h-screen md:flex-row md:overflow-hidden">
        @if (auth()->user()?->hasVerifiedEmail())
            <x-server-rail />
        @endif

        @isset($sidebar)
            {{ $sidebar }}
        @endisset

        <main class="flex min-w-0 flex-1 flex-col">
            @if (session('status'))
                <div role="status" class="mx-4 mt-3 rounded bg-green-900/40 px-3 py-2 text-sm text-green-300">
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </main>

        @isset($aside)
            {{ $aside }}
        @endisset
    </body>
</html>
