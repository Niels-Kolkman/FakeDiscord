{{--
    A server with one channel open. Used by /servers/{id} (the first text
    channel) and /servers/{id}/channels/{channel}.

    Text channels show their newest messages ($feed, App\Support\MessageFeed)
    and a composer. resources/js/channel-messages.js posts new messages,
    appends the ones others send (received over Reverb on "channel.{id}"),
    loads older pages from data-history-url while you scroll up and shows
    the "jump to latest" button when you are far from the bottom.

    The "start of the channel" header is always there but hidden while
    older pages exist; the script shows it once the last page is loaded.
--}}
<x-layouts.app :title="$channel ? '#'.$channel->name.' · '.$server->name : $server->name">
    <x-slot:sidebar>
        @include('servers.partials.sidebar', ['activeChannel' => $channel])
    </x-slot:sidebar>

    <x-slot:aside>
        @include('servers.partials.member-list')
    </x-slot:aside>

    @if ($channel)
        <header class="flex min-h-12 items-center gap-2 border-b border-[#1f2023] px-4 py-2 shadow-sm">
            <span aria-hidden="true" class="text-xl text-[#80848e]">{{ $channel->isVoice() ? '🔊' : '#' }}</span>
            <h1 class="shrink-0 font-semibold text-white">{{ $channel->name }}</h1>
            @if ($channel->topic)
                <span aria-hidden="true" class="h-6 w-px shrink-0 bg-[#3f4147]"></span>
                <p class="truncate text-sm text-[#b5bac1]" title="{{ $channel->topic }}" data-channel-topic>{{ $channel->topic }}</p>
            @endif
        </header>

        @php
            // Worked out once for every message (messages/item.blade.php)
            // and for the script, which shows Edit / Delete the same way.
            $viewerId = (int) auth()->id();
            $canManageMessages = (bool) auth()->user()?->can('manageMessages', $server);
        @endphp
        <section
            class="relative flex min-h-0 flex-1 flex-col"
            @unless ($channel->isVoice())
                data-channel-messages
                data-channel-id="{{ $channel->id }}"
                data-history-url="{{ route('messages.index', [$server, $channel]) }}"
                data-older-cursor="{{ $feed->olderCursor }}"
                data-viewer-id="{{ $viewerId }}"
                data-reaction-emoji="{{ json_encode(\App\Support\Reactions::QUICK, JSON_UNESCAPED_UNICODE) }}"
                @if ($canManageMessages) data-can-manage-messages @endif
            @endunless
        >
            {{-- overflow-anchor: none, the script keeps the scroll position itself when it prepends a page. --}}
            <div class="min-h-0 flex-1 overflow-y-auto [overflow-anchor:none]" data-message-scroller>
                <div class="flex min-h-full flex-col justify-end pt-4 pb-2">
                    <div class="mb-4 px-4" data-channel-start @if ($feed->hasOlder) hidden @endif>
                        <div aria-hidden="true" class="mb-2 flex h-16 w-16 items-center justify-center rounded-full bg-[#41434a] text-4xl text-white">{{ $channel->isVoice() ? '🔊' : '#' }}</div>
                        <h2 class="text-3xl font-bold text-white">Welcome to #{{ $channel->name }}!</h2>
                        <p class="text-[#b5bac1]">This is the start of the #{{ $channel->name }} channel.</p>
                        @if ($channel->topic)
                            <p class="mt-1 whitespace-pre-line break-words text-[#b5bac1]">{{ $channel->topic }}</p>
                        @endif
                    </div>

                    @unless ($channel->isVoice())
                        <div role="status" class="px-4 py-2 text-center text-sm text-[#949ba4]" data-history-status hidden>
                            <span data-history-loading>Loading older messages…</span>
                            <span data-history-error hidden>
                                Older messages could not be loaded.
                                <button type="button" class="text-[#00a8fc] hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]" data-history-retry>Try again</button>
                            </span>
                        </div>

                        <ol role="log" aria-label="Messages in #{{ $channel->name }}" data-message-list>
                            @foreach ($feed->messages as $message)
                                @include('messages.item')
                            @endforeach
                        </ol>
                    @endunless
                </div>
            </div>

            @unless ($channel->isVoice())
                <button
                    type="button"
                    class="absolute left-1/2 z-10 -translate-x-1/2 rounded-full bg-[#5865f2] px-4 py-1.5 text-sm font-medium text-white shadow-lg hover:bg-[#4752c4] focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                    style="bottom: 6rem"
                    data-jump-latest
                    hidden
                >Jump to latest <span data-jump-latest-count></span></button>
            @endunless

            @if (! $channel->isVoice() && auth()->user()?->can('sendMessages', $server))
                <form method="POST" action="{{ route('messages.store', [$server, $channel]) }}" class="px-4 pb-6" data-message-form>
                    @csrf
                    <label for="message-content" class="sr-only">Message #{{ $channel->name }}</label>
                    <div class="flex items-end gap-2 rounded-lg bg-[#383a40] px-4 py-2">
                        <textarea
                            id="message-content"
                            name="content"
                            rows="1"
                            maxlength="{{ \App\Models\Message::MAX_CONTENT_LENGTH }}"
                            required
                            placeholder="Message #{{ $channel->name }}"
                            aria-describedby="message-error"
                            class="max-h-64 min-h-6 flex-1 resize-none bg-transparent py-1 text-[#dbdee1] placeholder-[#6d6f78] focus:outline-none"
                            data-message-input
                        >{{ old('content') }}</textarea>
                        <button type="submit" class="shrink-0 rounded px-2 py-1 text-sm font-medium text-[#b5bac1] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]">Send</button>
                    </div>
                    <p id="message-error" role="alert" class="mt-1 text-sm text-red-400 @unless ($errors->has('content')) hidden @endunless" data-message-error>{{ $errors->first('content') }}</p>
                </form>
            @endif
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
