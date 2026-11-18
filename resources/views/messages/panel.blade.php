{{--
    The message panel of a text channel (servers/show.blade.php) or a 1:1
    DM (conversations/show.blade.php, ISSUE-23): the history, the "jump to
    latest" button, the composer and the typing indicator.
    resources/js/channel-messages.js drives it the same way for both; only
    the URLs and broadcast channels differ.

    $panel:
        target            'channel' | 'conversation' (data-channel-id or data-conversation-id)
        id                the channel / conversation id
        enabled           false for a voice channel: only the start header shows
        label             '#general' or '@username', for labels and the placeholder
        historyUrl        GET history (?before=); messages are {historyUrl}/{id}
        storeUrl          POST a new message
        pinsUrl           the pinned messages list, or NULL (no pins panel)
        broadcastChannel  private channel the message events go out on
        typingChannel     presence channel for typing indicators
        start             view for the "start of the conversation" header
        readOnlyNotice    shown instead of the composer when you cannot send (optional)

    Also $feed (App\Support\MessageFeed), $viewerId, $canManageMessages and
    $canSendMessages, worked out once by the page for every message
    (messages/item.blade.php) and for the script.

    The "start" header is always there but hidden while older pages exist;
    the script shows it once the last page is loaded.
--}}
@php
    $messagesEnabled = $panel['enabled'] ?? true;
    $messagesUrl = $panel['historyUrl'];
@endphp
<section
    class="relative flex min-h-0 flex-1 flex-col"
    @if ($messagesEnabled)
        data-channel-messages
        @if ($panel['target'] === 'conversation') data-conversation-id="{{ $panel['id'] }}" @else data-channel-id="{{ $panel['id'] }}" @endif
        data-history-url="{{ $panel['historyUrl'] }}"
        data-older-cursor="{{ $feed->olderCursor }}"
        data-viewer-id="{{ $viewerId }}"
        data-reaction-emoji="{{ json_encode(\App\Support\Reactions::QUICK, JSON_UNESCAPED_UNICODE) }}"
        @if ($canManageMessages) data-can-manage-messages @endif
        @if ($canSendMessages) data-can-send-messages @endif
        @if ($panel['pinsUrl']) data-pins-url="{{ $panel['pinsUrl'] }}" @endif
        data-broadcast-channel="{{ $panel['broadcastChannel'] }}"
        data-typing-channel="{{ $panel['typingChannel'] }}"
    @endif
>
    @if ($messagesEnabled && $panel['pinsUrl'])
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
            <ul class="min-h-0 flex-1 overflow-y-auto" aria-label="Pinned messages in {{ $panel['label'] }}" data-pins-list></ul>
        </div>
    @endif

    @if ($errors->has('message'))
        <p role="alert" class="mx-4 mt-2 rounded bg-red-500/10 px-3 py-2 text-sm text-red-300">{{ $errors->first('message') }}</p>
    @endif
    {{-- overflow-anchor: none, the script keeps the scroll position itself when it prepends a page. --}}
    <div class="min-h-0 flex-1 overflow-y-auto [overflow-anchor:none]" data-message-scroller>
        <div class="flex min-h-full flex-col justify-end pt-4 pb-2">
            <div class="mb-4 px-4" data-channel-start @if ($feed->hasOlder) hidden @endif>
                @include($panel['start'])
            </div>

            @if ($messagesEnabled)
                <div role="status" class="px-4 py-2 text-center text-sm text-[#949ba4]" data-history-status hidden>
                    <span data-history-loading>Loading older messages…</span>
                    <span data-history-error hidden>
                        Older messages could not be loaded.
                        <button type="button" class="text-[#00a8fc] hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]" data-history-retry>Try again</button>
                    </span>
                </div>

                <ol role="log" aria-label="Messages in {{ $panel['label'] }}" data-message-list>
                    @foreach ($feed->messages as $message)
                        @include('messages.item')
                    @endforeach
                </ol>
            @endif
        </div>
    </div>

    @if ($messagesEnabled)
        <button
            type="button"
            class="absolute left-1/2 z-10 -translate-x-1/2 rounded-full bg-[#5865f2] px-4 py-1.5 text-sm font-medium text-white shadow-lg hover:bg-[#4752c4] focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
            style="bottom: 6rem"
            data-jump-latest
            hidden
        >Jump to latest <span data-jump-latest-count></span></button>
    @endif

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
            action="{{ $panel['storeUrl'] }}"
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
            <label for="message-content" class="sr-only">Message {{ $panel['label'] }}</label>
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
                    placeholder="Message {{ $panel['label'] }}"
                    aria-describedby="message-error"
                    class="max-h-64 min-h-6 flex-1 resize-none bg-transparent py-1 text-[#dbdee1] placeholder-[#6d6f78] focus:outline-none"
                    data-message-input
                >{{ old('content') }}</textarea>
                <button type="submit" class="shrink-0 rounded px-2 py-1 text-sm font-medium text-[#b5bac1] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]">Send</button>
            </div>
            <p id="message-error" role="alert" class="mt-1 text-sm text-red-400 @unless ($messageError) hidden @endunless" data-message-error>{{ $messageError }}</p>
        </form>
    @elseif ($messagesEnabled && ($panel['readOnlyNotice'] ?? null))
        <p class="mx-4 mb-1 rounded-lg bg-[#2b2d31] px-4 py-3 text-sm text-[#949ba4]" data-read-only-notice>{{ $panel['readOnlyNotice'] }}</p>
    @endif

    @if ($messagesEnabled)
        {{-- Filled by typing-indicator.js; empty (but keeping its height) while nobody types. --}}
        <p class="h-6 truncate px-4 pb-1 text-xs leading-5 text-[#b5bac1]" aria-live="polite" data-typing-indicator></p>
    @endif
</section>
