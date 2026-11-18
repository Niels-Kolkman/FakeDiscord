{{--
    A server with one channel open. Used by /servers/{id} (the first text
    channel) and /servers/{id}/channels/{channel}.

    Text channels show their newest messages ($feed, App\Support\MessageFeed)
    and a composer. resources/js/channel-messages.js posts new messages,
    appends the ones others send (received over Reverb on "channel.{id}"),
    loads older pages from data-history-url while you scroll up and shows
    the "jump to latest" button when you are far from the bottom.
    It also shows who else is typing in [data-typing-indicator] (ISSUE-17,
    resources/js/typing-indicator.js), also for members who cannot send.

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
            <div class="ml-auto flex shrink-0 items-center gap-2">
                @unless ($channel->isVoice())
                    {{-- Pinned messages (ISSUE-20): the script opens [data-pins-panel]; without it this is the pins page. --}}
                    <a
                        href="{{ route('pins.index', [$server, $channel]) }}"
                        class="shrink-0 rounded px-2 py-1 text-sm text-[#b5bac1] hover:bg-[#404249] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2] aria-expanded:bg-[#404249] aria-expanded:text-white"
                        aria-controls="pins-panel"
                        data-pins-toggle
                    ><span aria-hidden="true">📌</span> Pinned<span class="sr-only"> messages</span></a>
                @endunless
                {{-- Message search (ISSUE-21) over the whole server; filters are on the results page. --}}
                <form method="GET" action="{{ route('servers.search', $server) }}" role="search" class="shrink-0" data-header-search>
                    <label for="header-search" class="sr-only">Search {{ $server->name }}</label>
                    <input
                        id="header-search"
                        type="search"
                        name="q"
                        maxlength="{{ \App\Support\MessageSearch::MAX_QUERY_LENGTH }}"
                        placeholder="Search"
                        autocomplete="off"
                        class="w-36 rounded bg-[#1e1f22] px-2 py-1 text-sm text-white placeholder-[#949ba4] outline-none transition-[width] focus:w-56 focus:ring-2 focus:ring-[#5865f2]"
                    >
                </form>
            </div>
        </header>

        @php
            // Worked out once for every message (messages/item.blade.php)
            // and for the script, which shows Reply / Pin / Edit / Delete
            // the same way.
            $viewerId = (int) auth()->id();
            $canManageMessages = (bool) auth()->user()?->can('manageMessages', $server);
            $canSendMessages = ! $channel->isVoice() && (bool) auth()->user()?->can('sendMessages', $server);
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
                @if ($canSendMessages) data-can-send-messages @endif
                data-pins-url="{{ route('pins.index', [$server, $channel]) }}"
            @endunless
        >
            @unless ($channel->isVoice())
                {{--
                    Pinned messages panel (ISSUE-20), filled by the script from
                    data-pins-url (PinController::index), newest pin first.
                    "Jump" scrolls to the message in the channel.
                --}}
                <div
                    id="pins-panel"
                    role="dialog"
                    aria-labelledby="pins-panel-title"
                    class="absolute right-4 top-2 z-30 flex max-h-[70vh] w-[min(28rem,calc(100%-2rem))] flex-col rounded-lg border border-[#1f2023] bg-[#2b2d31] shadow-xl"
                    data-pins-panel
                    hidden
                >
                    <div class="flex items-center justify-between border-b border-[#1f2023] px-4 py-2">
                        <h2 id="pins-panel-title" class="font-semibold text-white">Pinned messages</h2>
                        <button type="button" class="rounded px-1 text-[#b5bac1] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]" aria-label="Close pinned messages" data-pins-close>✕</button>
                    </div>
                    <p role="status" class="px-4 py-3 text-sm text-[#949ba4]" data-pins-status>Loading…</p>
                    <ul class="min-h-0 flex-1 overflow-y-auto" aria-label="Pinned messages in #{{ $channel->name }}" data-pins-list></ul>
                </div>
            @endunless

            @if ($errors->has('message'))
                <p role="alert" class="mx-4 mt-2 rounded bg-red-500/10 px-3 py-2 text-sm text-red-300">{{ $errors->first('message') }}</p>
            @endif
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

            @if ($canSendMessages)
                {{--
                    Attachments (ISSUE-19): up to MAX_FILES files in attachments[].
                    With files the text is optional, so the textarea is not
                    `required`; StoreMessageRequest checks it. The script lists
                    the chosen files ([data-attachment-list]) and lets you
                    remove them; without it the form still posts multipart.
                --}}
                @php($messageError = $errors->first('content') ?: ($errors->first('attachments') ?: $errors->first('attachments.*')))
                <form
                    method="POST"
                    action="{{ route('messages.store', [$server, $channel]) }}"
                    enctype="multipart/form-data"
                    class="px-4"
                    data-message-form
                    data-max-files="{{ \App\Models\MessageAttachment::MAX_FILES }}"
                    data-max-file-bytes="{{ \App\Models\MessageAttachment::MAX_KILOBYTES * 1024 }}"
                >
                    @csrf
                    {{-- Replies (ISSUE-20): "Reply" on a message fills reply_to_id and shows this bar. --}}
                    <input type="hidden" name="reply_to_id" value="" data-reply-input>
                    <div class="flex items-center justify-between gap-2 rounded-t-lg bg-[#2b2d31] px-4 py-1.5 text-sm text-[#b5bac1]" data-reply-bar hidden>
                        <span class="truncate">Replying to <span class="font-semibold text-white" data-reply-bar-author></span></span>
                        <button type="button" class="shrink-0 rounded px-1 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]" aria-label="Cancel reply" data-reply-cancel>✕</button>
                    </div>
                    <ul class="mb-1 flex flex-wrap gap-2" aria-label="Files to upload" data-attachment-list hidden></ul>
                    <label for="message-content" class="sr-only">Message #{{ $channel->name }}</label>
                    <div class="flex items-end gap-2 rounded-lg bg-[#383a40] px-4 py-2">
                        <label
                            class="shrink-0 cursor-pointer rounded-full px-1 py-0.5 text-xl leading-none text-[#b5bac1] hover:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[#5865f2]"
                            title="Attach files (up to {{ \App\Models\MessageAttachment::MAX_FILES }}, {{ \App\Models\MessageAttachment::MAX_KILOBYTES / 1024 }} MB each)"
                        >
                            <span aria-hidden="true">＋</span>
                            <span class="sr-only">Attach files (up to {{ \App\Models\MessageAttachment::MAX_FILES }}, {{ \App\Models\MessageAttachment::MAX_KILOBYTES / 1024 }} MB each)</span>
                            <input
                                type="file"
                                name="attachments[]"
                                multiple
                                accept="{{ collect(\App\Models\MessageAttachment::ALLOWED_EXTENSIONS)->map(fn ($extension) => '.'.$extension)->implode(',') }}"
                                class="sr-only"
                                aria-describedby="message-error"
                                data-attachment-input
                            >
                        </label>
                        <textarea
                            id="message-content"
                            name="content"
                            rows="1"
                            maxlength="{{ \App\Models\Message::MAX_CONTENT_LENGTH }}"
                            placeholder="Message #{{ $channel->name }}"
                            aria-describedby="message-error"
                            class="max-h-64 min-h-6 flex-1 resize-none bg-transparent py-1 text-[#dbdee1] placeholder-[#6d6f78] focus:outline-none"
                            data-message-input
                        >{{ old('content') }}</textarea>
                        <button type="submit" class="shrink-0 rounded px-2 py-1 text-sm font-medium text-[#b5bac1] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]">Send</button>
                    </div>
                    <p id="message-error" role="alert" class="mt-1 text-sm text-red-400 @unless ($messageError) hidden @endunless" data-message-error>{{ $messageError }}</p>
                </form>
            @endif

            @unless ($channel->isVoice())
                {{-- Filled by typing-indicator.js; empty (but keeping its height) while nobody types. --}}
                <p class="h-6 truncate px-4 pb-1 text-xs leading-5 text-[#b5bac1]" aria-live="polite" data-typing-indicator></p>
            @endunless
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
