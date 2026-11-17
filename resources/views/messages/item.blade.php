{{--
    One message in a channel. resources/js/channel-messages.js builds the
    same markup for messages that arrive live; keep the two in sync.

    The Edit / Delete buttons (ISSUE-16) are only a hint: MessagePolicy
    decides on the server. They use values worked out once per page by
    servers/show.blade.php, so the list costs no query per message:
    $viewerId (the signed-in user) and $canManageMessages (moderator).
    Edit needs JavaScript (the script unhides it); Delete is a form that
    also works without it.

    Reactions (ISSUE-18): every member can react, so every message gets
    the "Add reaction" picker (a <details>, works without JavaScript) and
    one toggle form per emoji: POST adds the viewer's reaction, DELETE
    (when they already reacted, aria-pressed) takes it back. The tooltip
    names the first reactors (App\Support\Reactions::tooltip()). The
    reactions are eager loaded by App\Support\MessageFeed.

    @var \App\Models\Message $message
    @var \App\Models\Server $server
    @var \App\Models\Channel $channel
--}}
@php
    $isOwn = $message->user_id !== null && (int) $message->user_id === (int) ($viewerId ?? 0);
    $canDelete = $isOwn || ($canManageMessages ?? false);
    $messageUrl = route('messages.update', [$server, $channel, $message]);
    $reactionsUrl = route('reactions.store', [$server, $channel, $message]);
    $reactionGroups = \App\Support\Reactions::groups($message, (int) ($viewerId ?? 0));
    $toolButton = 'rounded px-2 py-0.5 text-xs text-[#b5bac1] hover:bg-[#404249] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]';
@endphp
<li
    class="group relative flex gap-4 px-4 py-1 hover:bg-[#2e3035] focus-within:bg-[#2e3035]"
    data-message
    data-message-id="{{ $message->id }}"
    data-message-url="{{ $messageUrl }}"
    data-reactions-url="{{ $reactionsUrl }}"
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

        <div class="mt-1 flex flex-wrap gap-1" role="group" aria-label="Reactions" data-message-reactions @if ($reactionGroups === []) hidden @endif>
            @foreach ($reactionGroups as $group)
                @php($me = $group['me'] ?? false)
                <form method="POST" action="{{ $reactionsUrl }}" data-reaction-form>
                    @csrf
                    @if ($me) @method('DELETE') @endif
                    <input type="hidden" name="emoji" value="{{ $group['emoji'] }}">
                    <button
                        type="submit"
                        @class([
                            'flex items-center gap-1 rounded-lg border px-1.5 py-0.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]',
                            'border-[#5865f2] bg-[#5865f2]/25' => $me,
                            'border-transparent bg-[#2b2d31] hover:border-[#4e5058]' => ! $me,
                        ])
                        aria-pressed="{{ $me ? 'true' : 'false' }}"
                        aria-label="{{ \App\Support\Reactions::label($group) }}"
                        title="{{ \App\Support\Reactions::tooltip($group) }}"
                        data-reaction
                        data-emoji="{{ $group['emoji'] }}"
                    ><span aria-hidden="true">{{ $group['emoji'] }}</span><span class="text-xs font-medium text-[#dbdee1]" aria-hidden="true" data-reaction-count>{{ $group['count'] }}</span></button>
                </form>
            @endforeach
        </div>
    </div>

    <div
        class="absolute -top-3 right-4 hidden gap-1 rounded border border-[#1f2023] bg-[#313338] p-0.5 shadow group-hover:flex group-focus-within:flex has-[[open]]:flex"
        data-message-actions
    >
        <details class="relative" data-reaction-picker>
            <summary class="{{ $toolButton }} cursor-pointer list-none [&::-webkit-details-marker]:hidden" title="Add reaction">
                <span aria-hidden="true">🙂</span><span class="sr-only">Add reaction</span>
            </summary>
            <div class="absolute right-0 top-full z-20 mt-1 flex gap-0.5 rounded-lg border border-[#1f2023] bg-[#2b2d31] p-1 shadow-lg" role="group" aria-label="Choose a reaction">
                @foreach (\App\Support\Reactions::QUICK as $emoji)
                    <form method="POST" action="{{ $reactionsUrl }}" data-reaction-form>
                        @csrf
                        <input type="hidden" name="emoji" value="{{ $emoji }}">
                        <button
                            type="submit"
                            class="rounded p-1 text-lg leading-none hover:bg-[#404249] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]"
                            aria-label="React with {{ $emoji }}"
                            title="React with {{ $emoji }}"
                            data-reaction-choice
                            data-emoji="{{ $emoji }}"
                        >{{ $emoji }}</button>
                    </form>
                @endforeach
            </div>
        </details>

        @if ($isOwn)
            <button
                type="button"
                class="{{ $toolButton }}"
                data-message-edit
                hidden
            >Edit</button>
        @endif
        @if ($canDelete)
            <form method="POST" action="{{ $messageUrl }}" data-message-delete-form>
                @csrf
                @method('DELETE')
                <button
                    type="submit"
                    class="rounded px-2 py-0.5 text-xs text-red-400 hover:bg-[#404249] hover:text-red-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]"
                >Delete</button>
            </form>
        @endif
    </div>
</li>
