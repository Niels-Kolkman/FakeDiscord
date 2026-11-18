@props(['title'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @include('partials.user-meta')

        <title>{{ $title }} · {{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @if (file_exists(public_path('build/manifest.json')) || Vite::isRunningHot())
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-[#313338] text-[#dbdee1] font-sans antialiased flex items-center justify-center p-6">
        <main class="w-full max-w-md rounded-md bg-[#2b2d31] p-8 shadow-xl">
            <h1 class="mb-6 text-center text-2xl font-semibold text-white">{{ $title }}</h1>

            @if (session('status'))
                <div role="status" class="mb-4 rounded bg-green-900/40 px-3 py-2 text-sm text-green-300">
                    {{-- Fortify and the account settings flash status keys, not sentences. --}}
                    @php($statusMessages = [
                        'verification-link-sent' => 'A new verification link has been sent to your email address.',
                        'password-updated' => 'Your password has been changed.',
                        'email-updated' => 'Your email address has been changed. Check your inbox to verify it.',
                        'email-unchanged' => 'That is already your email address.',
                        'account-deleted' => 'Your account has been deleted.',
                    ])
                    {{ $statusMessages[session('status')] ?? session('status') }}
                </div>
            @endif

            {{ $slot }}
        </main>
    </body>
</html>
