{{--
    One message in a channel. resources/js/channel-messages.js builds the
    same markup for messages that arrive live; keep the two in sync.

    The Edit / Delete buttons (ISSUE-16) are only a hint: MessagePolicy
    decides on the server. They use values worked out once per page by
    servers/show.blade.php, so the list costs no query per message:
    $viewerId (the signed-in user) and $canManageMessages (moderator).
    Edit needs JavaScript (the script unhides it); Delete is a form that
    also works without it.

    @var \App\Models\Message $message
    @var \App\Models\Server $server
    @var \App\Models\Channel $channel
--}}
@php
    $isOwn = $message->user_id !== null && (int) $message->user_id === (int) ($viewerId ?? 0);
    $canDelete = $isOwn || ($canManageMessages ?? false);
    $messageUrl = route('messages.update', [$server, $channel, $message]);
@endphp
<li
    class="group relative flex gap-4 px-4 py-1 hover:bg-[#2e3035] focus-within:bg-[#2e3035]"
    data-message
    data-message-id="{{ $message->id }}"
    data-message-url="{{ $messageUrl }}"
>
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
        <p class="whitespace-pre-wrap break-words text-[#dbdee1]" data-message-body><span data-message-content>{{ $message->content }}</span><span
            class="ml-1 text-xs text-[#949ba4]"
            data-message-edited
            @if ($message->isEdited()) title="Edited {{ $message->edited_at?->toIso8601String() }}" @else hidden @endif
        >(edited)</span></p>
    </div>

    @if ($canDelete)
        <div
            class="absolute -top-3 right-4 hidden gap-1 rounded border border-[#1f2023] bg-[#313338] p-0.5 shadow group-hover:flex group-focus-within:flex"
            data-message-actions
        >
            @if ($isOwn)
                <button
                    type="button"
                    class="rounded px-2 py-0.5 text-xs text-[#b5bac1] hover:bg-[#404249] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]"
                    data-message-edit
                    hidden
                >Edit</button>
            @endif
            <form method="POST" action="{{ $messageUrl }}" data-message-delete-form>
                @csrf
                @method('DELETE')
                <button
                    type="submit"
                    class="rounded px-2 py-0.5 text-xs text-red-400 hover:bg-[#404249] hover:text-red-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]"
                >Delete</button>
            </form>
        </div>
    @endif
</li>
