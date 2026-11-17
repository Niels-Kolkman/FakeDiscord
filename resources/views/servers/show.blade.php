{{--
    A server with one channel open. Used by /servers/{id} (the first text
    channel) and /servers/{id}/channels/{channel}. Messages arrive in a later story.
--}}
<x-layouts.app :title="$channel ? '#'.$channel->name.' · '.$server->name : $server->name">
    <x-slot:sidebar>
        @include('servers.partials.sidebar', ['activeChannel' => $channel])
    </x-slot:sidebar>

    @if ($channel)
        <header class="flex min-h-12 items-center gap-2 border-b border-[#1f2023] px-4 py-2 shadow-sm">
            <span aria-hidden="true" class="text-xl text-[#80848e]">{{ $channel->isVoice() ? '🔊' : '#' }}</span>
            <h1 class="shrink-0 font-semibold text-white">{{ $channel->name }}</h1>
            @if ($channel->topic)
                <span aria-hidden="true" class="h-6 w-px shrink-0 bg-[#3f4147]"></span>
                <p class="truncate text-sm text-[#b5bac1]" title="{{ $channel->topic }}" data-channel-topic>{{ $channel->topic }}</p>
            @endif
        </header>

        <section class="flex flex-1 flex-col justify-end p-4">
            <div class="mb-4">
                <div aria-hidden="true" class="mb-2 flex h-16 w-16 items-center justify-center rounded-full bg-[#41434a] text-4xl text-white">{{ $channel->isVoice() ? '🔊' : '#' }}</div>
                <h2 class="text-3xl font-bold text-white">Welcome to #{{ $channel->name }}!</h2>
                <p class="text-[#b5bac1]">This is the start of the #{{ $channel->name }} channel.</p>
                @if ($channel->topic)
                    <p class="mt-1 whitespace-pre-line break-words text-[#b5bac1]">{{ $channel->topic }}</p>
                @endif
            </div>
        </section>
    @else
        <section class="flex flex-1 flex-col items-center justify-center p-8 text-center">
            <h1 class="mb-2 text-xl font-semibold text-white">No text channels</h1>
            <p class="text-[#b5bac1]">
                This server has no text channels yet.
                @can('manageChannels', $server)
                    <a href="{{ route('channels.create', $server) }}" class="text-[#00a8fc] hover:underline">Create one</a>.
                @endcan
            </p>
        </section>
    @endif
</x-layouts.app>
