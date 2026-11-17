{{-- One sidebar row. Expects $server, $channel, $activeChannel and $canManage. --}}
@php($active = $activeChannel?->is($channel) ?? false)

<li class="group flex items-center rounded {{ $active ? 'bg-[#404249]' : 'hover:bg-[#35373c]' }}"
    data-channel data-channel-id="{{ $channel->id }}"
    @if ($active) data-active @endif
    @if ($canManage) draggable="true" @endif
>
    <a href="{{ route('channels.show', [$server, $channel]) }}"
        class="flex min-w-0 flex-1 items-center gap-1.5 px-2 py-1 {{ $active ? 'text-white' : 'text-[#949ba4] group-hover:text-[#dbdee1]' }}"
        @if ($active) aria-current="page" @endif
        @if ($canManage) draggable="false" @endif
    >
        <span aria-hidden="true" class="w-4 shrink-0 text-center text-lg leading-none text-[#80848e]">{{ $channel->isVoice() ? '🔊' : '#' }}</span>
        <span class="truncate">{{ $channel->name }}</span>
    </a>
    @if ($canManage)
        <a href="{{ route('channels.edit', [$server, $channel]) }}" draggable="false"
            class="px-2 text-sm text-[#949ba4] opacity-0 hover:text-white focus:opacity-100 group-hover:opacity-100"
            title="Edit channel" aria-label="Edit channel {{ $channel->name }}">⚙</a>
    @endif
</li>
