{{--
    One message in a channel or DM. resources/js/channel-messages.js builds the
    same markup for messages that arrive live; keep the two in sync.

    The Edit / Delete buttons are only a hint: MessagePolicy
    decides on the server. They use values worked out once per page by
    servers/show.blade.php, so the list costs no query per message:
    $viewerId (the signed-in user) and $canManageMessages (moderator).
    Edit needs JavaScript (the script unhides it); Delete is a form that
    also works without it.

    Reactions: every member can react, so every message gets
    the "Add reaction" picker (a <details>, works without JavaScript) and
    one toggle form per emoji: POST adds the viewer's reaction, DELETE
    (when they already reacted, aria-pressed) takes it back. The tooltip
    names the first reactors (App\Support\Reactions::tooltip()). The
    reactions are eager loaded by App\Support\MessageFeed.

    Attachments: PNG / JPEG / GIF / WebP show as an inline
    preview sized by MessageAttachment::previewSize() (so the scroll
    position does not jump while it loads); other files as a card with a
    download link. All links are temporary signed URLs. Eager loaded by
    App\Support\MessageFeed too.

    Replies: a reply quotes its original above the header
    (Message::replyPreview()); the quote links to #message-{id}, and the
    script scrolls to it, loading older pages when it is not on screen.
    "Reply" needs the script (it fills the composer's reply_to_id), so it
    is rendered hidden, and only for members who can send ($canSendMessages).
    Pins: 📌 marks a pinned message; moderators ($canManageMessages) get a
    Pin / Unpin form that also works without JavaScript.

    Markdown: the content is rendered by App\Support\Markdown
    (bold, italic, underline, strike, code, code blocks, quotes, links),
    which escapes all text itself, so {{ }} prints its HtmlString as is.
    data-source keeps the raw text for editing.

    Used for channel messages and DMs alike, from
    messages/panel.blade.php; every URL is built from $messagesUrl.

    @var \App\Models\Message $message
    @var string $messagesUrl  the history URL of the channel or DM
--}}
@php
    $isOwn = $message->user_id !== null && (int) $message->user_id === (int) ($viewerId ?? 0);
    $canDelete = $isOwn || ($canManageMessages ?? false);
    // {messagesUrl}/{id}, like renderMessage() in channel-messages.js: the
    // same for a channel (messages.update) and a DM (dms.messages.update).
    $messageUrl = $messagesUrl.'/'.$message->id;
    $reactionsUrl = $messageUrl.'/reactions';
    $reactionGroups = \App\Support\Reactions::groups($message, (int) ($viewerId ?? 0));
    $pinUrl = $messageUrl.'/pin';
    $reply = $message->replyPreview();
    $toolButton = 'rounded px-2 py-0.5 text-xs text-fg-muted hover:bg-active hover:text-fg-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]';
    // Mentions: by name, a role the viewer holds or @everyone.
    $mentionsViewer = ! $isOwn && $message->mentionsUser((int) ($viewerId ?? 0), $viewerRoleIds ?? []);
@endphp
<li
    id="message-{{ $message->id }}"
    @class([
        'group relative flex scroll-mt-16 flex-wrap gap-x-4 px-4 py-1 hover:bg-hover-soft focus-within:bg-hover-soft',
        'border-l-2 border-[#f0b232] bg-[#f0b232]/10' => $mentionsViewer,
    ])
    @if ($mentionsViewer) data-mentions-viewer @endif
    data-message
    data-message-id="{{ $message->id }}"
    data-message-url="{{ $messageUrl }}"
    data-reactions-url="{{ $reactionsUrl }}"
    data-pin-url="{{ $pinUrl }}"
>
    @if ($reply)
        <div class="mb-0.5 flex w-full min-w-0 items-center gap-1 pl-14 text-sm text-fg-muted" data-reply-preview data-reply-to-id="{{ $reply['id'] }}">
            <span aria-hidden="true" class="shrink-0 text-fg-faint">↱</span>
            @if ($reply['deleted'])
                <span class="italic text-fg-subtle" data-reply-deleted>Original message was deleted</span>
            @elseif (in_array($reply['author']['id'] ?? null, $blockedUserIds ?? [], true))
                {{-- The original is by someone the viewer blocked. --}}
                <span class="italic text-fg-subtle" data-reply-blocked>Blocked message</span>
            @else
                <a
                    href="#message-{{ $reply['id'] }}"
                    class="flex min-w-0 items-center gap-1 rounded hover:text-fg-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]"
                    data-reply-jump
                >
                    <span class="sr-only">Replying to</span>
                    <span class="shrink-0 font-medium text-fg-strong" data-reply-author>{{ $reply['author']['name'] ?? __('Deleted User') }}</span>
                    <span class="truncate" data-reply-content>{{ \App\Models\Message::previewText($reply) }}</span>
                </a>
            @endif
        </div>
    @endif

    @if ($message->author)
        <x-user-avatar :user="$message->author" :size="40" class="mt-0.5" />
    @else
        <span aria-hidden="true" class="mt-0.5 h-10 w-10 shrink-0 rounded-full bg-[#4e5058]"></span>
    @endif

    <div class="min-w-0 flex-1">
        <p class="flex items-baseline gap-2">
            <span class="truncate font-medium text-fg-strong" data-message-author>{{ $message->authorName() }}</span>
            <time
                class="shrink-0 text-xs text-fg-subtle"
                datetime="{{ $message->created_at?->toIso8601String() }}"
                data-local-time
            >{{ $message->created_at?->format('Y-m-d H:i') }}</time>
            <span class="shrink-0 text-xs" title="Pinned" data-message-pinned @unless ($message->isPinned()) hidden @endunless><span aria-hidden="true">📌</span><span class="sr-only">Pinned</span></span>
        </p>
        {{-- Markdown: a <div>, since a code block or quote cannot sit in a <p>. --}}
        <div class="message-markdown whitespace-pre-wrap break-words text-fg" data-message-body><span data-message-content data-source="{{ $message->content }}">{{ \App\Support\Markdown::toHtml($message->content) }}</span><span
            class="ml-1 text-xs text-fg-subtle"
            data-message-edited
            @if ($message->isEdited()) title="Edited {{ $message->edited_at?->toIso8601String() }}" @else hidden @endif
        >(edited)</span></div>

        @if ($message->attachments->isNotEmpty())
            <ul class="mt-1 flex flex-col items-start gap-1" aria-label="Attachments" data-message-attachments>
                @foreach ($message->attachments as $attachment)
                    @if ($attachment->isImage())
                        @php($preview = $attachment->previewSize())
                        <li class="relative max-w-full" data-attachment data-attachment-image>
                            <a href="{{ $attachment->url() }}" target="_blank" rel="noopener" class="block rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]">
                                <img
                                    src="{{ $attachment->url() }}"
                                    alt="{{ $attachment->original_name }}"
                                    loading="lazy"
                                    @if ($preview) width="{{ $preview[0] }}" height="{{ $preview[1] }}" @endif
                                    class="h-auto max-h-[300px] max-w-full rounded bg-surface-2 object-contain"
                                >
                            </a>
                            <a
                                href="{{ $attachment->downloadUrl() }}"
                                class="absolute right-1 top-1 rounded bg-black/60 px-2 py-0.5 text-xs text-white opacity-0 hover:bg-black/80 focus:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2] [li:hover>&]:opacity-100"
                                data-attachment-download
                            >Download<span class="sr-only"> {{ $attachment->original_name }}</span></a>
                        </li>
                    @else
                        <li class="flex w-full max-w-md items-center gap-3 rounded-lg border border-edge bg-surface-2 p-3" data-attachment data-attachment-file>
                            <span aria-hidden="true" class="text-3xl">📄</span>
                            <span class="min-w-0 flex-1">
                                <a href="{{ $attachment->downloadUrl() }}" class="block truncate text-link hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]" data-attachment-download>{{ $attachment->original_name }}</a>
                                <span class="block text-xs text-fg-subtle">{{ $attachment->humanSize() }}</span>
                            </span>
                        </li>
                    @endif
                @endforeach
            </ul>
        @endif

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
                            'border-transparent bg-surface-2 hover:border-[#4e5058]' => ! $me,
                        ])
                        aria-pressed="{{ $me ? 'true' : 'false' }}"
                        aria-label="{{ \App\Support\Reactions::label($group) }}"
                        title="{{ \App\Support\Reactions::tooltip($group) }}"
                        data-reaction
                        data-emoji="{{ $group['emoji'] }}"
                    ><span aria-hidden="true">{{ $group['emoji'] }}</span><span class="text-xs font-medium text-fg" aria-hidden="true" data-reaction-count>{{ $group['count'] }}</span></button>
                </form>
            @endforeach
        </div>
    </div>

    <div
        class="absolute -top-3 right-4 hidden gap-1 rounded border border-edge bg-surface p-0.5 shadow group-hover:flex group-focus-within:flex has-[[open]]:flex"
        data-message-actions
    >
        <details class="relative" data-reaction-picker>
            <summary class="{{ $toolButton }} cursor-pointer list-none [&::-webkit-details-marker]:hidden" title="Add reaction">
                <span aria-hidden="true">🙂</span><span class="sr-only">Add reaction</span>
            </summary>
            <div class="absolute right-0 top-full z-20 mt-1 flex gap-0.5 rounded-lg border border-edge bg-surface-2 p-1 shadow-lg" role="group" aria-label="Choose a reaction">
                @foreach (\App\Support\Reactions::QUICK as $emoji)
                    <form method="POST" action="{{ $reactionsUrl }}" data-reaction-form>
                        @csrf
                        <input type="hidden" name="emoji" value="{{ $emoji }}">
                        <button
                            type="submit"
                            class="rounded p-1 text-lg leading-none hover:bg-active focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]"
                            aria-label="React with {{ $emoji }}"
                            title="React with {{ $emoji }}"
                            data-reaction-choice
                            data-emoji="{{ $emoji }}"
                        >{{ $emoji }}</button>
                    </form>
                @endforeach
            </div>
        </details>

        @if ($canSendMessages ?? false)
            <button
                type="button"
                class="{{ $toolButton }}"
                data-message-reply
                hidden
            >Reply</button>
        @endif
        @if ($canManageMessages ?? false)
            <form method="POST" action="{{ $pinUrl }}" data-message-pin-form>
                @csrf
                @if ($message->isPinned()) @method('DELETE') @endif
                <button type="submit" class="{{ $toolButton }}" data-message-pin>{{ $message->isPinned() ? 'Unpin' : 'Pin' }}</button>
            </form>
        @endif
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
                    class="rounded px-2 py-0.5 text-xs text-danger-fg hover:bg-active focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]"
                >Delete</button>
            </form>
        @endif
    </div>
</li>
