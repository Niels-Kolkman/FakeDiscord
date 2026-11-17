{{--
    One message in a channel. resources/js/channel-messages.js builds the
    same markup for messages that arrive live; keep the two in sync.

    @var \App\Models\Message $message
--}}
<li class="flex gap-4 px-4 py-1 hover:bg-[#2e3035]" data-message data-message-id="{{ $message->id }}">
    @if ($message->author)
        <x-user-avatar :user="$message->author" :size="40" class="mt-0.5" />
    @else
        <span aria-hidden="true" class="mt-0.5 h-10 w-10 shrink-0 rounded-full bg-[#4e5058]"></span>
    @endif

    <div class="min-w-0 flex-1">
        <p class="flex items-baseline gap-2">
            <span class="truncate font-medium text-white" data-message-author>{{ $message->authorName() }}</span>
            <time
                class="shrink-0 text-xs text-[#949ba4]"
                datetime="{{ $message->created_at?->toIso8601String() }}"
                data-local-time
            >{{ $message->created_at?->format('Y-m-d H:i') }}</time>
        </p>
        <p class="whitespace-pre-wrap break-words text-[#dbdee1]" data-message-content>{{ $message->content }}</p>
    </div>
</li>
