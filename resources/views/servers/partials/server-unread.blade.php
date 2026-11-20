{{--
    A server's unread dot and mention badge in a server list:
    the dashboard (the server rail has its own). Expects $state, the
    server's App\Support\UnreadState (or NULL). Not live: these lists are
    worked out when the page loads.
--}}
@if ($state?->totalMentions())
    <span class="ml-auto shrink-0 rounded-full bg-[#da373c] px-1.5 text-xs font-semibold text-white" data-server-mentions>{{ $state->totalMentions() }}<span class="sr-only"> {{ $state->totalMentions() === 1 ? 'mention' : 'mentions' }}</span></span>
@elseif ($state?->hasUnread())
    <span class="ml-auto h-2 w-2 shrink-0 rounded-full bg-fg-strong" data-server-unread><span class="sr-only">(unread)</span></span>
@endif
