{{--
    The inbox bell (ISSUE-33) for page headers: the unread mention count
    (App\Support\NotificationInbox) and, with resources/js/notifications.js,
    a panel that loads the inbox from data-inbox-url (JSON) and keeps the
    count live from App\Notifications\MentionedInMessage broadcasts.
    Without JavaScript the bell is a link to the inbox page.

    Only for verified users: everything with mentions needs a verified
    address.
--}}
@auth
    @if (auth()->user()->hasVerifiedEmail())
        @php($inboxUnread = \App\Support\NotificationInbox::unreadCount(auth()->user()))
        <div
            class="relative shrink-0"
            data-inbox
            data-inbox-url="{{ route('notifications.index') }}"
            data-read-all-url="{{ route('notifications.read-all') }}"
            data-unread-count="{{ $inboxUnread }}"
        >
            <a
                href="{{ route('notifications.index') }}"
                class="relative block rounded px-2 py-1 text-sm text-[#b5bac1] hover:bg-[#404249] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2] aria-expanded:bg-[#404249] aria-expanded:text-white"
                aria-controls="inbox-panel"
                title="Inbox"
                data-inbox-toggle
            ><span aria-hidden="true">🔔</span><span class="sr-only">Inbox</span><span
                class="absolute -right-1 -top-1 min-w-4 rounded-full bg-[#da373c] px-1 text-center text-[10px] font-semibold leading-4 text-white"
                data-inbox-badge
                @if ($inboxUnread === 0) hidden @endif
            ><span data-inbox-count>{{ \App\Support\NotificationInbox::badge($inboxUnread) }}</span><span class="sr-only"> unread</span></span></a>

            <div
                id="inbox-panel"
                role="dialog"
                aria-labelledby="inbox-panel-title"
                class="absolute right-0 top-full z-40 mt-2 flex max-h-[70vh] w-[min(26rem,calc(100vw-2rem))] flex-col rounded-lg border border-[#1f2023] bg-[#2b2d31] shadow-xl"
                data-inbox-panel
                hidden
            >
                <div class="flex items-center gap-2 border-b border-[#1f2023] px-4 py-2">
                    <h2 id="inbox-panel-title" class="font-semibold text-white">Inbox</h2>
                    <button type="button" class="ml-auto rounded px-2 py-0.5 text-xs text-[#b5bac1] hover:bg-[#404249] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]" data-inbox-read-all>Mark all read</button>
                    <button type="button" class="rounded px-1 text-[#b5bac1] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]" aria-label="Close inbox" data-inbox-close>✕</button>
                </div>
                <p role="status" class="px-4 py-3 text-sm text-[#949ba4]" data-inbox-status>Loading…</p>
                <ul class="min-h-0 flex-1 overflow-y-auto" aria-label="Mentions" data-inbox-list></ul>
                <a href="{{ route('notifications.index') }}" class="border-t border-[#1f2023] px-4 py-2 text-center text-xs text-[#00a8fc] hover:underline">Open the inbox page</a>
            </div>
        </div>
    @endif
@endauth
