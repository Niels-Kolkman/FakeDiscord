{{--
    One sidebar row. Expects $server, $channel, $activeChannel, $canManage
    and $unread (App\Support\UnreadState, ISSUE-32).

    An unread channel carries data-unread: the name turns bright and bold
    and a dot shows on the left (CSS only, so resources/js/unread.js just
    toggles the attribute). Unread mentions show as a red badge
    ([data-mention-badge], always rendered for the script). The open
    channel is never unread.
--}}
@php
    $active = $activeChannel?->is($channel) ?? false;
    $isUnread = ! $active && $unread->isUnread($channel);
    $mentionCount = $active ? 0 : $unread->mentions($channel);
@endphp

<li class="group relative flex items-center rounded {{ $active ? 'bg-[#404249]' : 'hover:bg-[#35373c]' }}"
    data-channel data-channel-id="{{ $channel->id }}"
    data-channel-type="{{ $channel->type }}"
    @if ($active) data-active @endif
    @if ($isUnread) data-unread @endif
    @if ($canManage) draggable="true" @endif
>
    <span aria-hidden="true" class="absolute -left-2 top-1/2 hidden h-2 w-1 -translate-y-1/2 rounded-r bg-white group-data-unread:block" data-unread-dot></span>
    <a href="{{ route('channels.show', [$server, $channel]) }}"
        class="flex min-w-0 flex-1 items-center gap-1.5 px-2 py-1 {{ $active ? 'text-white' : 'text-[#949ba4] group-hover:text-[#dbdee1] group-data-unread:font-semibold group-data-unread:text-white' }}"
        @if ($active) aria-current="page" @endif
        @if ($canManage) draggable="false" @endif
    >
        <span aria-hidden="true" class="w-4 shrink-0 text-center text-lg leading-none text-[#80848e]">{{ $channel->isVoice() ? '🔊' : '#' }}</span>
        <span class="truncate">{{ $channel->name }}</span>
        <span class="sr-only hidden group-data-unread:inline">(unread)</span>
        @if ($channel->is_private)
            <span class="shrink-0 text-xs" title="Private channel" data-private-channel>🔒<span class="sr-only">(private)</span></span>
        @endif
        <span class="ml-auto shrink-0 rounded-full bg-[#da373c] px-1.5 text-xs font-semibold text-white"
            data-mention-badge
            @if ($mentionCount === 0) hidden @endif
        ><span data-mention-count>{{ $mentionCount }}</span><span class="sr-only" data-mention-label> {{ $mentionCount === 1 ? 'mention' : 'mentions' }}</span></span>
    </a>
    @if ($canManage)
        <a href="{{ route('channels.edit', [$server, $channel]) }}" draggable="false"
            class="px-2 text-sm text-[#949ba4] opacity-0 hover:text-white focus:opacity-100 group-hover:opacity-100"
            title="Edit channel" aria-label="Edit channel {{ $channel->name }}">⚙</a>
    @endif
</li>
