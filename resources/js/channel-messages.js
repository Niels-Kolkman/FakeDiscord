/**
 * Channel messages (resources/views/servers/show.blade.php).
 *
 * - The composer posts JSON to POST /servers/{id}/channels/{channel}/messages
 *   and appends the stored message from the response. Enter sends,
 *   Shift+Enter adds a new line.
 * - Messages from others arrive over Reverb: App\Events\MessageSent on the
 *   private "channel.{id}" channel (authorised in routes/channels.php).
 *   The server skips the sender's socket (X-Socket-ID), and messages are
 *   de-duplicated by id in case one arrives both ways.
 * - The list sticks to the bottom when a message arrives, unless you have
 *   scrolled up to read older ones.
 * - History (ISSUE-15): close to the top, the next older page is fetched
 *   from GET data-history-url?before={cursor} (MessageController::index)
 *   and prepended. The scroll position is kept by restoring the distance
 *   from the bottom, so what you were reading stays where it was. When
 *   next_cursor is null the "start of the channel" header is shown.
 * - "Jump to latest" appears once you are more than a screen away from the
 *   bottom, and counts the messages that arrived meanwhile.
 * - Edit and delete (ISSUE-16): authors get "Edit" (inline, Enter saves,
 *   Escape cancels, PATCH data-message-url); authors and moderators
 *   (data-can-manage-messages) get "Delete" (asks first, DELETE
 *   data-message-url). Edits from others arrive as ".message.updated" and
 *   replace the content and show "(edited)"; ".message.deleted" removes
 *   the message. The buttons are only a hint, MessagePolicy decides.
 *
 *  - Typing indicators (ISSUE-17): see typing-indicator.js. The composer
 *   whispers while you type; [data-typing-indicator] shows who else is.
 * 
 *  * - Reactions (ISSUE-18): "Add reaction" (🙂) in the hover toolbar opens a
 *   picker of data-reaction-emoji; clicking a reaction adds yours or takes
 *   it back (POST / DELETE data-reactions-url, field emoji). The response
 *   and ".reactions.updated" carry the grouped reactions; the tooltip
 *   names the first reactors (reactionTooltip()). Broadcasts have no `me`,
 *   so the viewer's own state is kept from the page. The buttons are plain
 *   forms, so they also work without this script.
 * - Attachments (ISSUE-19): ＋ in the composer, pasting or dropping files
 *   queues up to data-max-files files (data-max-file-bytes each), listed in
 *   [data-attachment-list] with a remove button. With files the composer
 *   posts multipart (attachments[]) and the text is optional. Messages
 *   show image previews (sized by previewSize(), like
 *   MessageAttachment::previewSize()) and file cards; every link is a
 *   temporary signed URL from the payload.
 * - Replies (ISSUE-20): "Reply" fills the composer's reply_to_id and shows
 *   "Replying to …" (✕ or Escape cancels). A reply quotes its original
 *   (payload `reply_to`); clicking the quote scrolls to the original and
 *   highlights it, loading older pages (at most MAX_JUMP_PAGES) when it is
 *   not on screen. Quotes follow edits and deletes of the original.
 * - Pins (ISSUE-20): moderators get Pin / Unpin (POST / DELETE
 *   data-pin-url); 📌 marks pinned messages. "📌 Pinned" in the header
 *   opens [data-pins-panel] with the list from data-pins-url, newest pin
 *   first, with Jump (and Unpin for moderators). ".message.pinned" updates
 *   the marker and the open panel.
 *
 * - Direct messages (ISSUE-23): the same panel (messages/panel.blade.php)
 *   drives a 1:1 DM. It carries data-conversation-id instead of
 *   data-channel-id, so payloads are matched on conversation_id
 *   (belongsToTarget()); it subscribes to data-broadcast-channel
 *   ("conversation.{id}") and types on data-typing-channel. A DM has no
 *   data-pins-url and no moderators, so no pins and no Pin buttons.
 *
 * - Blocking (ISSUE-25): a server channel carries data-blocked-user-ids,
 *   the people the viewer blocked. The history already leaves their
 *   messages out (Message::visibleTo()); this skips the ones that arrive
 *   live and shows "Blocked message" instead of quoting them in replies.
 *
 * - Markdown and emoji (ISSUE-36): message text is formatted by markdown.js
 *   (the same rules as App\Support\Markdown), which builds DOM nodes; the raw
 *   text stays in data-source for editing. emoji-picker.js adds the 😀 picker
 *   to the composer.
 *
 * All user-provided text is inserted with textContent / text nodes, never
 * parsed as HTML.
 * renderMessage() must match resources/views/messages/item.blade.php.
 */

import { initTypingIndicator } from './typing-indicator.js';
import { initMentionAutocomplete } from './mention-autocomplete.js';
import { mentionsViewer } from './unread.js';
import { fillMessageContent } from './markdown.js';
import { initEmojiPicker } from './emoji-picker.js';

const NEAR_BOTTOM_PX = 120;
const LOAD_OLDER_PX = 400;
const MAX_CONTENT_LENGTH = 2000;
// Like Message::REPLY_PREVIEW_LENGTH.
const REPLY_PREVIEW_LENGTH = 100;
// Older pages loaded at most to find a reply's original (50 messages each).
const MAX_JUMP_PAGES = 20;
const HIGHLIGHT = ['bg-[#5865f2]/20', 'transition-colors'];

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
}

export function formatTime(iso) {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
}

function renderAvatar(author) {
    if (!author) {
        const placeholder = el('span', 'mt-0.5 h-10 w-10 shrink-0 rounded-full bg-[#4e5058]');
        placeholder.setAttribute('aria-hidden', 'true');
        return placeholder;
    }

    // Same as <x-user-avatar>; profile-popover.js handles the click.
    const button = el('button', 'mt-0.5 inline-block shrink-0 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]');
    button.type = 'button';
    button.dataset.profileUrl = author.profile_url;
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-label', `View profile of ${author.name}`);

    const img = el('img', 'rounded-full object-cover');
    img.src = author.avatar_url;
    img.alt = '';
    img.width = 40;
    img.height = 40;
    img.style.width = '40px';
    img.style.height = '40px';
    button.appendChild(img);

    return button;
}

/**
 * Show or hide "(edited)" for a message's edited_at.
 */
export function applyEdited(marker, editedAt) {
    if (!marker) return;
    marker.hidden = !editedAt;
    if (editedAt) {
        marker.title = `Edited ${formatTime(editedAt) || editedAt}`;
    } else {
        marker.removeAttribute('title');
    }
}

const TOOL_BUTTON = 'rounded px-2 py-0.5 text-xs text-fg-muted hover:bg-active hover:text-fg-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]';
const REACTION_BUTTON = 'flex items-center gap-1 rounded-lg border px-1.5 py-0.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]';
const REACTION_MINE = 'border-[#5865f2] bg-[#5865f2]/25';
const REACTION_OTHERS = 'border-transparent bg-surface-2 hover:border-[#4e5058]';

/**
 * "Alice, Bob and 3 others reacted with 👍", like App\Support\Reactions::tooltip().
 */
export function reactionTooltip(group) {
    const names = (group.users ?? []).map((user) => user.name);
    const others = group.count - names.length;
    if (others > 0) names.push(others === 1 ? '1 other' : `${others} others`);
    if (names.length === 0) return `Reacted with ${group.emoji}`;

    const last = names.pop();
    const who = names.length === 0 ? last : `${names.join(', ')} and ${last}`;
    return `${who} reacted with ${group.emoji}`;
}

/**
 * "👍, 3 reactions", like App\Support\Reactions::label().
 */
export function reactionLabel(group) {
    return `${group.emoji}, ${group.count} ${group.count === 1 ? 'reaction' : 'reactions'}`;
}

/**
 * A POST form with the CSRF token, an optional _method and an emoji.
 */
function reactionForm(url, emoji, csrfToken, method = null) {
    const form = el('form');
    form.method = 'POST';
    form.action = url;
    form.dataset.reactionForm = '';
    const fields = [['_token', csrfToken ?? '']];
    if (method) fields.push(['_method', method]);
    fields.push(['emoji', emoji]);
    fields.forEach(([name, value]) => {
        const input = el('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    });
    return form;
}

/**
 * The reaction buttons of a message, like messages/item.blade.php. Each
 * is a form: POST adds the viewer's reaction, DELETE (me) takes it back.
 */
export function renderReactions(groups, url, csrfToken) {
    const row = el('div', 'mt-1 flex flex-wrap gap-1');
    row.setAttribute('role', 'group');
    row.setAttribute('aria-label', 'Reactions');
    row.dataset.messageReactions = '';
    fillReactions(row, groups, url, csrfToken);
    return row;
}

function fillReactions(row, groups, url, csrfToken) {
    row.replaceChildren();
    (groups ?? []).forEach((group) => {
        const me = Boolean(group.me);
        const form = reactionForm(url, group.emoji, csrfToken, me ? 'DELETE' : null);
        const button = el('button', `${REACTION_BUTTON} ${me ? REACTION_MINE : REACTION_OTHERS}`);
        button.type = 'submit';
        button.setAttribute('aria-pressed', me ? 'true' : 'false');
        button.setAttribute('aria-label', reactionLabel(group));
        button.title = reactionTooltip(group);
        button.dataset.reaction = '';
        button.dataset.emoji = group.emoji;
        const emoji = el('span', null, group.emoji);
        emoji.setAttribute('aria-hidden', 'true');
        const count = el('span', 'text-xs font-medium text-fg', String(group.count));
        count.setAttribute('aria-hidden', 'true');
        count.dataset.reactionCount = '';
        button.append(emoji, count);
        form.appendChild(button);
        row.appendChild(form);
    });
    row.hidden = row.childElementCount === 0;
}

const PREVIEW_WIDTH = 400;
const PREVIEW_HEIGHT = 300;

/**
 * [width, height] of an image preview: scaled down (never up) to fit
 * 400x300, like MessageAttachment::previewSize(). null when unknown.
 */
export function previewSize(width, height) {
    if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1) return null;
    const scale = Math.min(1, PREVIEW_WIDTH / width, PREVIEW_HEIGHT / height);
    return [Math.max(1, Math.round(width * scale)), Math.max(1, Math.round(height * scale))];
}

/**
 * "1.5 MB", like MessageAttachment::formatSize().
 */
export function formatSize(bytes) {
    if (!Number.isFinite(bytes) || bytes < 1024) return `${Math.max(0, bytes || 0)} B`;
    const units = ['KB', 'MB', 'GB'];
    let size = bytes / 1024;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit += 1;
    }
    return `${size.toFixed(1).replace(/\.0$/, '')} ${units[unit]}`;
}

/**
 * Image previews and file cards, like the attachments in
 * messages/item.blade.php. Returns null when there are none.
 */
export function renderAttachments(attachments) {
    if (!Array.isArray(attachments) || attachments.length === 0) return null;

    const list = el('ul', 'mt-1 flex flex-col items-start gap-1');
    list.setAttribute('aria-label', 'Attachments');
    list.dataset.messageAttachments = '';

    attachments.forEach((attachment) => {
        if (attachment.is_image) {
            const item = el('li', 'relative max-w-full');
            item.dataset.attachment = '';
            item.dataset.attachmentImage = '';

            const link = el('a', 'block rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]');
            link.href = attachment.url;
            link.target = '_blank';
            link.rel = 'noopener';
            const img = el('img', 'h-auto max-h-[300px] max-w-full rounded bg-surface-2 object-contain');
            img.src = attachment.url;
            img.alt = attachment.name ?? '';
            img.loading = 'lazy';
            const size = previewSize(attachment.width, attachment.height);
            if (size) {
                img.width = size[0];
                img.height = size[1];
            }
            link.appendChild(img);

            const download = el('a', 'absolute right-1 top-1 rounded bg-black/60 px-2 py-0.5 text-xs text-white opacity-0 hover:bg-black/80 focus:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2] [li:hover>&]:opacity-100', 'Download');
            download.href = attachment.download_url;
            download.dataset.attachmentDownload = '';
            download.appendChild(el('span', 'sr-only', ` ${attachment.name ?? ''}`));

            item.append(link, download);
            list.appendChild(item);
            return;
        }

        const item = el('li', 'flex w-full max-w-md items-center gap-3 rounded-lg border border-edge bg-surface-2 p-3');
        item.dataset.attachment = '';
        item.dataset.attachmentFile = '';
        const icon = el('span', 'text-3xl', '📄');
        icon.setAttribute('aria-hidden', 'true');
        const details = el('span', 'min-w-0 flex-1');
        const download = el('a', 'block truncate text-link hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]', attachment.name);
        download.href = attachment.download_url;
        download.dataset.attachmentDownload = '';
        details.append(download, el('span', 'block text-xs text-fg-subtle', formatSize(attachment.size)));
        item.append(icon, details);
        list.appendChild(item);
    });

    return list;
}

/**
 * One line of at most REPLY_PREVIEW_LENGTH characters, like Message::excerpt().
 */
export function excerpt(content) {
    const line = String(content ?? '').replace(/\s+/gu, ' ').trim();
    const chars = [...line];
    if (chars.length <= REPLY_PREVIEW_LENGTH) return line;
    return `${chars.slice(0, REPLY_PREVIEW_LENGTH - 1).join('').trimEnd()}…`;
}

/**
 * The quoted text of a reply preview, like Message::previewText().
 */
export function previewText(reply) {
    if (reply?.content) return reply.content;
    return reply?.has_attachments ? 'Click to see attachment' : '';
}

/**
 * Whether a payload's author is someone the viewer blocked (ISSUE-25).
 * blockedUserIds is a Set of id strings (data-blocked-user-ids); messages
 * of deleted accounts (no author) are never blocked.
 */
export function isBlockedAuthor(author, blockedUserIds) {
    return Boolean(author && blockedUserIds?.has(String(author.id)));
}

/**
 * Fill a [data-reply-preview] row: a link to the original, "deleted", or
 * "Blocked message" when the original is by someone the viewer blocked.
 */
function fillReplyPreview(row, reply, blockedUserIds = null) {
    row.replaceChildren();
    const arrow = el('span', 'shrink-0 text-fg-faint', '↱');
    arrow.setAttribute('aria-hidden', 'true');
    row.appendChild(arrow);

    if (reply.deleted) {
        const gone = el('span', 'italic text-fg-subtle', 'Original message was deleted');
        gone.dataset.replyDeleted = '';
        row.appendChild(gone);
        return;
    }

    if (isBlockedAuthor(reply.author, blockedUserIds)) {
        const blocked = el('span', 'italic text-fg-subtle', 'Blocked message');
        blocked.dataset.replyBlocked = '';
        row.appendChild(blocked);
        return;
    }

    const link = el('a', 'flex min-w-0 items-center gap-1 rounded hover:text-fg-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]');
    link.href = `#message-${reply.id}`;
    link.dataset.replyJump = '';
    const author = el('span', 'shrink-0 font-medium text-fg-strong', reply.author?.name ?? 'Deleted User');
    author.dataset.replyAuthor = '';
    const content = el('span', 'truncate', previewText(reply));
    content.dataset.replyContent = '';
    link.append(el('span', 'sr-only', 'Replying to'), author, content);
    row.appendChild(link);
}

/**
 * The quote above a reply, like messages/item.blade.php. null when the
 * message is not a reply. context: { blockedUserIds }.
 */
export function renderReplyPreview(reply, context = {}) {
    if (!reply) return null;
    const row = el('div', 'mb-0.5 flex w-full min-w-0 items-center gap-1 pl-14 text-sm text-fg-muted');
    row.dataset.replyPreview = '';
    row.dataset.replyToId = String(reply.id);
    fillReplyPreview(row, reply, context.blockedUserIds);
    return row;
}

/**
 * One entry of the pinned messages panel, like channels/pins.blade.php.
 * context: { canManageMessages }.
 */
export function renderPin(pin, context = {}) {
    const item = el('li', 'border-b border-edge px-4 py-3 last:border-b-0');
    item.dataset.pin = '';
    item.dataset.messageId = String(pin.id);

    const header = el('p', 'flex items-baseline gap-2');
    header.appendChild(el('span', 'truncate font-medium text-fg-strong', pin.author?.name ?? 'Deleted User'));
    const time = el('time', 'shrink-0 text-xs text-fg-subtle', formatTime(pin.created_at));
    time.dateTime = pin.created_at ?? '';
    header.appendChild(time);
    item.appendChild(header);

    if (pin.content) item.appendChild(el('p', 'line-clamp-6 whitespace-pre-wrap break-words text-sm text-fg', pin.content));
    const files = pin.attachments?.length ?? 0;
    if (files > 0) item.appendChild(el('p', 'text-xs text-fg-subtle', files === 1 ? '1 attachment' : `${files} attachments`));

    const footer = el('div', 'mt-1 flex items-center gap-3 text-xs text-fg-subtle');
    footer.appendChild(el('span', 'min-w-0 truncate', pin.pinned_by ? `Pinned by ${pin.pinned_by.name}` : 'Pinned'));
    const jump = el('button', 'ml-auto shrink-0 rounded text-link hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]', 'Jump');
    jump.type = 'button';
    jump.dataset.pinJump = '';
    footer.appendChild(jump);
    if (context.canManageMessages) {
        const unpin = el('button', 'shrink-0 rounded text-danger-fg hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]', 'Unpin');
        unpin.type = 'button';
        unpin.dataset.pinUnpin = '';
        footer.appendChild(unpin);
    }
    item.appendChild(footer);

    return item;
}

/**
 * Show a message as pinned or not: the 📌 marker and the Pin / Unpin form.
 */
export function applyPinned(item, pinnedAt) {
    if (!item) return;
    const pinned = Boolean(pinnedAt);
    const marker = item.querySelector('[data-message-pinned]');
    if (marker) marker.hidden = !pinned;
    const form = item.querySelector('[data-message-pin-form]');
    if (!form) return;
    let method = form.querySelector('input[name="_method"]');
    if (pinned && !method) {
        method = el('input');
        method.type = 'hidden';
        method.name = '_method';
        form.appendChild(method);
    }
    if (method) {
        if (pinned) method.value = 'DELETE';
        else method.remove();
    }
    const button = form.querySelector('[data-message-pin]');
    if (button) button.textContent = pinned ? 'Unpin' : 'Pin';
}

function renderReactionPicker(url, context) {
    const picker = el('details', 'relative');
    picker.dataset.reactionPicker = '';

    const summary = el('summary', `${TOOL_BUTTON} cursor-pointer list-none [&::-webkit-details-marker]:hidden`);
    summary.title = 'Add reaction';
    const icon = el('span', null, '🙂');
    icon.setAttribute('aria-hidden', 'true');
    summary.append(icon, el('span', 'sr-only', 'Add reaction'));
    picker.appendChild(summary);

    const choices = el('div', 'absolute right-0 top-full z-20 mt-1 flex gap-0.5 rounded-lg border border-edge bg-surface-2 p-1 shadow-lg');
    choices.setAttribute('role', 'group');
    choices.setAttribute('aria-label', 'Choose a reaction');
    (context.quickEmoji ?? []).forEach((emoji) => {
        const form = reactionForm(url, emoji, context.csrfToken);
        const button = el('button', 'rounded p-1 text-lg leading-none hover:bg-active focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]', emoji);
        button.type = 'submit';
        button.setAttribute('aria-label', `React with ${emoji}`);
        button.title = `React with ${emoji}`;
        button.dataset.reactionChoice = '';
        button.dataset.emoji = emoji;
        form.appendChild(button);
        choices.appendChild(form);
    });
    picker.appendChild(choices);

    return picker;
}

function hiddenInputs(form, fields) {
    fields.forEach(([name, value]) => {
        const input = el('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    });
}

/**
 * Add reaction / Reply / Pin / Edit / Delete, like the actions in
 * messages/item.blade.php.
 * context: { viewerId, canManageMessages, canSendMessages, messagesUrl, csrfToken, quickEmoji }.
 */
function renderActions(message, url, context) {
    const own = message.author != null && String(message.author.id) === String(context.viewerId);

    const actions = el('div', 'absolute -top-3 right-4 hidden gap-1 rounded border border-edge bg-surface p-0.5 shadow group-hover:flex group-focus-within:flex has-[[open]]:flex');
    actions.dataset.messageActions = '';

    actions.appendChild(renderReactionPicker(`${url}/reactions`, context));

    if (context.canSendMessages) {
        const reply = el('button', TOOL_BUTTON, 'Reply');
        reply.type = 'button';
        reply.dataset.messageReply = '';
        actions.appendChild(reply);
    }

    if (context.canManageMessages) {
        const form = el('form');
        form.method = 'POST';
        form.action = `${url}/pin`;
        form.dataset.messagePinForm = '';
        hiddenInputs(form, message.pinned_at
            ? [['_token', context.csrfToken ?? ''], ['_method', 'DELETE']]
            : [['_token', context.csrfToken ?? '']]);
        const pin = el('button', TOOL_BUTTON, message.pinned_at ? 'Unpin' : 'Pin');
        pin.type = 'submit';
        pin.dataset.messagePin = '';
        form.appendChild(pin);
        actions.appendChild(form);
    }

    if (own) {
        const edit = el('button', TOOL_BUTTON, 'Edit');
        edit.type = 'button';
        edit.dataset.messageEdit = '';
        actions.appendChild(edit);
    }

    if (own || context.canManageMessages) {
        const form = el('form');
        form.method = 'POST';
        form.action = url;
        form.dataset.messageDeleteForm = '';
        hiddenInputs(form, [['_token', context.csrfToken ?? ''], ['_method', 'DELETE']]);
        const remove = el('button', 'rounded px-2 py-0.5 text-xs text-danger-fg hover:bg-active focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]', 'Delete');
        remove.type = 'submit';
        form.appendChild(remove);
        actions.appendChild(form);
    }

    return actions;
}

export function renderMessage(message, context = {}) {
    const url = context.messagesUrl ? `${context.messagesUrl}/${message.id}` : '';

    const item = el('li', 'group relative flex scroll-mt-16 flex-wrap gap-x-4 px-4 py-1 hover:bg-hover-soft focus-within:bg-hover-soft');
    item.id = `message-${message.id}`;
    item.dataset.message = '';
    item.dataset.messageId = String(message.id);
    item.dataset.messageUrl = url;
    item.dataset.reactionsUrl = url ? `${url}/reactions` : '';
    item.dataset.pinUrl = url ? `${url}/pin` : '';

    // Mentions (ISSUE-33), like $mentionsViewer in messages/item.blade.php.
    // Only from the payload's `mentions`: no guessing from the content here.
    const own = message.author && String(message.author.id) === String(context.viewerId ?? '');
    if (!own && message.mentions !== undefined && mentionsViewer(message, { userId: context.viewerId ?? null, roleIds: context.viewerRoleIds ?? new Set() })) {
        item.className += ' border-l-2 border-[#f0b232] bg-[#f0b232]/10';
        item.dataset.mentionsViewer = '';
    }

    const reply = renderReplyPreview(message.reply_to, context);
    if (reply) item.appendChild(reply);

    item.appendChild(renderAvatar(message.author));

    const body = el('div', 'min-w-0 flex-1');
    const header = el('p', 'flex items-baseline gap-2');
    const author = el('span', 'truncate font-medium text-fg-strong', message.author?.name ?? 'Deleted User');
    author.dataset.messageAuthor = '';
    header.appendChild(author);

    const time = el('time', 'shrink-0 text-xs text-fg-subtle', formatTime(message.created_at));
    time.dateTime = message.created_at ?? '';
    time.dataset.localTime = '';
    header.appendChild(time);
    const pinned = el('span', 'shrink-0 text-xs');
    pinned.title = 'Pinned';
    pinned.dataset.messagePinned = '';
    const pinIcon = el('span', null, '📌');
    pinIcon.setAttribute('aria-hidden', 'true');
    pinned.append(pinIcon, el('span', 'sr-only', 'Pinned'));
    pinned.hidden = !message.pinned_at;
    header.appendChild(pinned);
    body.appendChild(header);

    const text = el('div', 'message-markdown whitespace-pre-wrap break-words text-fg');
    text.dataset.messageBody = '';
    const content = el('span');
    content.dataset.messageContent = '';
    fillMessageContent(content, message.content);
    text.appendChild(content);
    const edited = el('span', 'ml-1 text-xs text-fg-subtle', '(edited)');
    edited.dataset.messageEdited = '';
    applyEdited(edited, message.edited_at);
    text.appendChild(edited);
    body.appendChild(text);
    const attachments = renderAttachments(message.attachments);
    if (attachments) body.appendChild(attachments);
    body.appendChild(renderReactions(message.reactions, item.dataset.reactionsUrl, context.csrfToken));

    item.appendChild(body);

    const actions = url ? renderActions(message, url, context) : null;
    if (actions) item.appendChild(actions);

    return item;
}

/**
 * The message to show when sending fails: the first validation error,
 * text first, then the files (attachments, attachments.0, ...).
 */
export function uploadErrorMessage(error) {
    const response = error?.response;
    if (response?.status === 429) return 'You are sending messages too quickly. Wait a moment and try again.';
    if (response?.status === 403) return 'You cannot send messages in this channel.';
    if (response?.status === 413) return 'Your files are too large to upload together. Send fewer at a time.';
    const errors = response?.data?.errors ?? {};
    if (errors.content?.[0]) return errors.content[0];
    if (errors.reply_to_id?.[0]) return errors.reply_to_id[0];
    const fileErrors = Object.keys(errors).filter((key) => key === 'attachments' || key.startsWith('attachments.')).sort();
    if (fileErrors.length > 0) return errors[fileErrors[0]][0];
    return 'Your message could not be sent. Try again.';
}

const errorMessage = uploadErrorMessage;

function editErrorMessage(error) {
    const response = error.response;
    if (response?.status === 429) return 'You are editing too quickly. Wait a moment and try again.';
    if (response?.status === 403) return 'You cannot edit this message.';
    if (response?.status === 404) return 'This message was deleted.';
    return response?.data?.errors?.content?.[0] ?? 'Your changes could not be saved. Try again.';
}

function parseJson(text, fallback) {
    try {
        return text ? JSON.parse(text) : fallback;
    } catch {
        return fallback;
    }
}

function reactionErrorMessage(error) {
    const response = error.response;
    if (response?.status === 429) return 'You are reacting too quickly. Wait a moment and try again.';
    if (response?.status === 403) return 'You cannot react to this message.';
    if (response?.status === 404) return 'This message was deleted.';
    return response?.data?.errors?.emoji?.[0] ?? 'Your reaction could not be saved. Try again.';
}

function socketHeaders() {
    const socketId = window.Echo?.socketId();
    return socketId ? { 'X-Socket-ID': socketId } : {};
}

/**
 * Whether a message payload or event belongs to the panel's channel or DM
 * (ISSUE-23): a DM panel (conversationId) compares conversation_id, a
 * channel panel channel_id. Ids are compared as strings.
 */
export function belongsToTarget(payload, { channelId = null, conversationId = null } = {}) {
    if (!payload) return false;
    if (conversationId) return String(payload.conversation_id) === String(conversationId);
    return channelId !== null && channelId !== undefined && String(payload.channel_id) === String(channelId);
}

function initChannel(root) {
    const channelId = root.dataset.channelId;
    const conversationId = root.dataset.conversationId ?? null;
    const target = { channelId, conversationId };
    const belongs = (payload) => belongsToTarget(payload, target);
    const list = root.querySelector('[data-message-list]');
    const scroller = root.querySelector('[data-message-scroller]');
    const form = root.querySelector('[data-message-form]');
    if (!list || !scroller) return;

    const context = {
        viewerId: root.dataset.viewerId ?? '',
        canManageMessages: root.dataset.canManageMessages !== undefined,
        canSendMessages: root.dataset.canSendMessages !== undefined && form !== null,
        messagesUrl: root.dataset.historyUrl ?? '',
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        quickEmoji: parseJson(root.dataset.reactionEmoji, []),
        // People the viewer blocked (ISSUE-25), only on server channels.
        blockedUserIds: new Set(parseJson(root.dataset.blockedUserIds, []).map(String)),
        // Roles the viewer holds (ISSUE-33), for highlighting role mentions.
        viewerRoleIds: new Set(parseJson(root.dataset.viewerRoleIds, []).map(String)),
    };
    const blockedAuthor = (message) => isBlockedAuthor(message?.author, context.blockedUserIds);

    const seen = new Set([...list.querySelectorAll('[data-message]')].map((item) => item.dataset.messageId));

    // Server-rendered times are UTC; show them in the viewer's time zone.
    list.querySelectorAll('time[data-local-time]').forEach((time) => {
        time.textContent = formatTime(time.dateTime) || time.textContent;
    });
    list.querySelectorAll('[data-message-edited]:not([hidden])').forEach((marker) => {
        applyEdited(marker, marker.title.replace(/^Edited /, ''));
    });

    // Editing and replying need this script, so the server renders them hidden.
    list.querySelectorAll('[data-message-edit], [data-message-reply]').forEach((button) => {
        button.hidden = false;
    });

    const distanceFromBottom = () => scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight;
    const nearBottom = () => distanceFromBottom() < NEAR_BOTTOM_PX;
    const scrollToBottom = () => {
        scroller.scrollTop = scroller.scrollHeight;
    };

    // --- Jump to latest ------------------------------------------------
    const jump = root.querySelector('[data-jump-latest]');
    const jumpCount = root.querySelector('[data-jump-latest-count]');
    let unseen = 0;

    const updateJump = () => {
        if (!jump) return;
        const away = distanceFromBottom() > Math.max(scroller.clientHeight, NEAR_BOTTOM_PX);
        if (nearBottom()) unseen = 0;
        jump.hidden = !(away || unseen > 0);
        if (jumpCount) jumpCount.textContent = unseen > 0 ? `(${unseen} new)` : '';
    };

    jump?.addEventListener('click', () => {
        scroller.scrollTo({ top: scroller.scrollHeight, behavior: 'smooth' });
        unseen = 0;
        updateJump();
        root.querySelector('[data-message-input]')?.focus({ preventScroll: true });
    });

    // --- Older messages --------------------------------------------------
    const historyUrl = root.dataset.historyUrl;
    const start = root.querySelector('[data-channel-start]');
    const status = root.querySelector('[data-history-status]');
    const statusLoading = root.querySelector('[data-history-loading]');
    const statusError = root.querySelector('[data-history-error]');
    let olderCursor = root.dataset.olderCursor || null;
    let loadingOlder = null;
    let failed = false;

    // Everything that grows or shrinks above the viewport goes through
    // here: keep the distance from the bottom, so the messages on screen
    // stay where they are (the scroller has overflow-anchor: none).
    const keepPosition = (change) => {
        const fromBottom = scroller.scrollHeight - scroller.scrollTop;
        change();
        scroller.scrollTop = scroller.scrollHeight - fromBottom;
    };

    const showStatus = (state) => {
        if (!status) return;
        status.hidden = state === null;
        if (statusLoading) statusLoading.hidden = state !== 'loading';
        if (statusError) statusError.hidden = state !== 'error';
    };

    const prepend = (messages) => {
        const fragment = document.createDocumentFragment();
        messages.forEach((message) => {
            const id = String(message.id);
            if (seen.has(id) || blockedAuthor(message)) return;
            seen.add(id);
            fragment.appendChild(renderMessage(message, context));
        });
        list.insertBefore(fragment, list.firstChild);
    };

    // Resolves once the page is in (also when one was already loading),
    // or right away when there is nothing to load.
    const loadOlder = () => {
        if (loadingOlder) return loadingOlder;
        if (!historyUrl || !olderCursor || failed) return Promise.resolve();

        keepPosition(() => showStatus('loading'));

        loadingOlder = window.axios
            .get(historyUrl, { params: { before: olderCursor }, headers: { Accept: 'application/json' } })
            .then((response) => {
                olderCursor = response.data.next_cursor ?? null;
                keepPosition(() => {
                    showStatus(null);
                    prepend(response.data.messages ?? []);
                    if (!olderCursor && start) start.hidden = false;
                });
            })
            .catch(() => {
                failed = true;
                keepPosition(() => showStatus('error'));
            })
            .finally(() => {
                loadingOlder = null;
                // A short page may not fill the screen: keep going.
                if (!failed) requestAnimationFrame(maybeLoadOlder);
            });

        return loadingOlder;
    };

    const maybeLoadOlder = () => {
        if (scroller.scrollTop < LOAD_OLDER_PX) loadOlder();
    };

    root.querySelector('[data-history-retry]')?.addEventListener('click', () => {
        failed = false;
        loadOlder();
    });

    scroller.addEventListener('scroll', () => {
        maybeLoadOlder();
        updateJump();
    }, { passive: true });

    scrollToBottom();
    updateJump();
    maybeLoadOlder();

    const typing = initTypingIndicator({
        echo: window.Echo,
        channelId,
        channelName: root.dataset.typingChannel || null,
        viewerId: context.viewerId,
        input: form?.querySelector('[data-message-input]') ?? null,
        output: root.querySelector('[data-typing-indicator]'),
    });

    const append = (message, own = false) => {
        if (!belongs(message)) return;
        if (!own) typing?.messageArrived(message.author?.id);
        const id = String(message.id);
        // The server leaves them out of the history; live ones too (ISSUE-25).
        if (seen.has(id) || (!own && blockedAuthor(message))) return;

        const stick = own || nearBottom();
        seen.add(id);
        list.appendChild(renderMessage(message, context));
        if (stick) {
            scrollToBottom();
        } else {
            unseen += 1;
        }
        updateJump();
    };

    // --- Edit and delete (ISSUE-16) ---------------------------------------
    const findItem = (id) => list.querySelector(`[data-message][data-message-id="${CSS.escape(String(id))}"]`);

    // When you are at the bottom, stay there. Otherwise a message above the
    // viewport changing height must not move what you are reading.
    const changeItem = (item, change) => {
        if (nearBottom()) {
            change();
            scrollToBottom();
        } else if (item.offsetTop + item.offsetHeight <= scroller.scrollTop) {
            keepPosition(change);
        } else {
            change();
        }
        updateJump();
    };

    // Quotes of a message in the replies to it (ISSUE-20).
    const quotesOf = (id) => [...list.querySelectorAll(`[data-reply-preview][data-reply-to-id="${CSS.escape(String(id))}"]`)];

    const applyUpdate = (message) => {
        if (!belongs(message)) return;
        quotesOf(message.id).forEach((row) => {
            const content = row.querySelector('[data-reply-content]');
            if (content) {
                content.textContent = previewText({
                    content: excerpt(message.content),
                    has_attachments: (message.attachments?.length ?? 0) > 0,
                });
            }
        });
        const item = findItem(message.id);
        if (!item) return;
        changeItem(item, () => {
            const content = item.querySelector('[data-message-content]');
            if (content) fillMessageContent(content, message.content);
            applyEdited(item.querySelector('[data-message-edited]'), message.edited_at);
        });
    };

    // Called with the id of every removed message (the pins panel, the reply bar).
    const removedListeners = [];

    const removeItem = (id) => {
        // Stays in `seen`, so a late ".message.sent" cannot bring it back.
        seen.add(String(id));
        quotesOf(id).forEach((row) => fillReplyPreview(row, { id, deleted: true }));
        const item = findItem(id);
        if (item) changeItem(item, () => item.remove());
        removedListeners.forEach((listener) => listener(String(id)));
    };

    // --- Jump to a message (replies and pins, ISSUE-20) -------------------
    const highlight = (item) => {
        item.classList.add(...HIGHLIGHT);
        window.setTimeout(() => item.classList.remove(HIGHLIGHT[0]), 1500);
    };

    // Older messages have smaller ids, so loading older pages finds any
    // message of this channel that still exists.
    const jumpTo = async (id) => {
        let item = findItem(id);
        for (let page = 0; !item && olderCursor && !failed && page < MAX_JUMP_PAGES; page += 1) {
            await loadOlder();
            item = findItem(id);
        }
        if (!item) {
            window.alert('That message could not be found. It may have been deleted, or it is too far back.');
            return false;
        }
        item.scrollIntoView({ block: 'center', behavior: 'smooth' });
        highlight(item);
        updateJump();
        return true;
    };

    list.addEventListener('click', (event) => {
        const link = event.target.closest('[data-reply-jump]');
        if (!link || !list.contains(link)) return;
        event.preventDefault();
        const id = link.closest('[data-reply-preview]')?.dataset.replyToId;
        if (id) jumpTo(id);
    });

    // --- Pins (ISSUE-20) --------------------------------------------------
    const pinsPanel = root.querySelector('[data-pins-panel]');
    const pinsList = pinsPanel?.querySelector('[data-pins-list]');
    const pinsStatus = pinsPanel?.querySelector('[data-pins-status]');
    const pinsToggle = document.querySelector('[data-pins-toggle]');
    const pinsUrl = root.dataset.pinsUrl;
    let pinsRequest = 0;

    const showPinsStatus = (text) => {
        if (!pinsStatus) return;
        pinsStatus.textContent = text ?? '';
        pinsStatus.hidden = !text;
    };

    const loadPins = () => {
        if (!pinsPanel || !pinsList || !pinsUrl) return;
        const request = ++pinsRequest;
        if (pinsList.childElementCount === 0) showPinsStatus('Loading…');
        window.axios
            .get(pinsUrl, { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (request !== pinsRequest) return;
                const pins = response.data.pins ?? [];
                pinsList.replaceChildren(...pins.map((pin) => renderPin(pin, context)));
                showPinsStatus(pins.length === 0 ? 'This channel has no pinned messages yet.' : null);
            })
            .catch(() => {
                if (request === pinsRequest) showPinsStatus('Pinned messages could not be loaded.');
            });
    };

    const pinsOpen = () => pinsPanel !== null && !pinsPanel.hidden;

    const closePins = (focusToggle = false) => {
        if (!pinsOpen()) return;
        pinsPanel.hidden = true;
        pinsToggle?.setAttribute('aria-expanded', 'false');
        if (focusToggle) pinsToggle?.focus({ preventScroll: true });
    };

    const openPins = () => {
        if (!pinsPanel) return;
        pinsPanel.hidden = false;
        pinsToggle?.setAttribute('aria-expanded', 'true');
        loadPins();
        pinsPanel.querySelector('[data-pins-close]')?.focus({ preventScroll: true });
    };

    if (pinsPanel && pinsToggle) {
        pinsToggle.setAttribute('aria-expanded', 'false');
        pinsToggle.setAttribute('aria-haspopup', 'dialog');
        pinsToggle.addEventListener('click', (event) => {
            event.preventDefault();
            if (pinsOpen()) closePins();
            else openPins();
        });
        pinsPanel.querySelector('[data-pins-close]')?.addEventListener('click', () => closePins(true));
        pinsPanel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closePins(true);
            }
        });
        document.addEventListener('click', (event) => {
            if (pinsOpen() && !pinsPanel.contains(event.target) && !pinsToggle.contains(event.target)) closePins();
        });
    }

    const pinErrorMessage = (failure) => {
        const response = failure.response;
        if (response?.status === 429) return 'You are pinning too quickly. Wait a moment and try again.';
        if (response?.status === 403) return 'You cannot pin messages in this channel.';
        if (response?.status === 404) return 'This message was deleted.';
        return response?.data?.errors?.message?.[0] ?? 'The message could not be pinned. Try again.';
    };

    // A deleted message leaves the pinned list too.
    removedListeners.push((id) => {
        pinsList?.querySelector(`[data-pin][data-message-id="${CSS.escape(id)}"]`)?.remove();
        if (pinsOpen() && pinsList?.childElementCount === 0) showPinsStatus('This channel has no pinned messages yet.');
    });

    const pendingPins = new Set();

    const setPin = (id, url, pin) => {
        if (!url || pendingPins.has(id)) return;
        pendingPins.add(id);
        window.axios
            .request({ method: pin ? 'post' : 'delete', url, headers: { Accept: 'application/json', ...socketHeaders() } })
            .then((response) => {
                applyPinned(findItem(id), response.data.message?.pinned_at);
                if (pinsOpen()) loadPins();
            })
            .catch((failure) => {
                if (failure.response?.status === 404) {
                    removeItem(id);
                    if (pinsOpen()) loadPins();
                    return;
                }
                window.alert(pinErrorMessage(failure));
            })
            .finally(() => pendingPins.delete(id));
    };

    list.addEventListener('submit', (event) => {
        const pinForm = event.target.closest('[data-message-pin-form]');
        if (!pinForm) return;
        event.preventDefault();
        const item = pinForm.closest('[data-message]');
        if (!item) return;
        const unpin = pinForm.querySelector('input[name="_method"]')?.value === 'DELETE';
        setPin(item.dataset.messageId, item.dataset.pinUrl, !unpin);
    });

    pinsList?.addEventListener('click', (event) => {
        const entry = event.target.closest('[data-pin]');
        if (!entry) return;
        const id = entry.dataset.messageId;
        if (event.target.closest('[data-pin-jump]')) {
            closePins();
            jumpTo(id);
        } else if (event.target.closest('[data-pin-unpin]')) {
            setPin(id, `${context.messagesUrl}/${id}/pin`, false);
        }
    });

    const stopEditing = (item) => {
        item.querySelector('[data-message-editor]')?.remove();
        const body = item.querySelector('[data-message-body]');
        if (body) body.hidden = false;
        delete item.dataset.editing;
    };

    const startEditing = (item) => {
        if (item.dataset.editing !== undefined) return;
        const body = item.querySelector('[data-message-body]');
        const url = item.dataset.messageUrl;
        if (!body || !url) return;

        item.dataset.editing = '';

        const editor = el('div', 'mt-1');
        editor.dataset.messageEditor = '';
        const input = el('textarea', 'block max-h-60 w-full resize-none rounded-lg bg-field px-4 py-2 text-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]');
        input.id = `message-edit-${item.dataset.messageId}`;
        input.rows = 1;
        input.maxLength = MAX_CONTENT_LENGTH;
        const source = item.querySelector('[data-message-content]');
        input.value = source?.dataset.source ?? source?.textContent ?? '';
        input.setAttribute('aria-label', 'Edit message');
        input.setAttribute('aria-describedby', `${input.id}-hint ${input.id}-error`);
        const hint = el('p', 'mt-1 text-xs text-fg-subtle', 'Escape to cancel · Enter to save');
        hint.id = `${input.id}-hint`;
        const error = el('p', 'mt-1 hidden text-sm text-danger-fg');
        error.id = `${input.id}-error`;
        error.setAttribute('role', 'alert');
        editor.append(input, hint, error);

        const showEditError = (text) => {
            error.textContent = text;
            error.classList.remove('hidden');
        };

        const resize = () => {
            input.style.height = 'auto';
            input.style.height = `${input.scrollHeight}px`;
        };

        const focusEditButton = () => item.querySelector('[data-message-edit]')?.focus({ preventScroll: true });

        let saving = false;
        const save = () => {
            if (saving) return;
            const content = input.value.trim();
            // A message with files may lose its text (UpdateMessageRequest).
            if (!content && !item.querySelector('[data-message-attachments]')) {
                showEditError('A message cannot be empty. Delete it instead.');
                return;
            }

            saving = true;
            window.axios
                .patch(url, { content }, { headers: { Accept: 'application/json', ...socketHeaders() } })
                .then((response) => {
                    applyUpdate(response.data.message);
                    stopEditing(item);
                    focusEditButton();
                })
                .catch((failure) => showEditError(editErrorMessage(failure)))
                .finally(() => {
                    saving = false;
                });
        };

        input.addEventListener('input', resize);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                stopEditing(item);
                focusEditButton();
            } else if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                save();
            }
        });

        changeItem(item, () => {
            body.hidden = true;
            body.after(editor);
            resize();
        });
        input.focus({ preventScroll: true });
        input.setSelectionRange(input.value.length, input.value.length);
    };

    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-message-edit]');
        if (!button || !list.contains(button)) return;
        const item = button.closest('[data-message]');
        if (item) startEditing(item);
    });

    list.addEventListener('submit', (event) => {
        const deleteForm = event.target.closest('[data-message-delete-form]');
        if (!deleteForm) return;
        event.preventDefault();

        const item = deleteForm.closest('[data-message]');
        if (!item || item.dataset.deleting !== undefined) return;
        if (!window.confirm('Delete this message? This cannot be undone.')) return;

        item.dataset.deleting = '';
        window.axios
            .delete(item.dataset.messageUrl, { headers: { Accept: 'application/json', ...socketHeaders() } })
            .then(() => removeItem(item.dataset.messageId))
            .catch((failure) => {
                // Already gone (deleted elsewhere): just take it off the screen.
                if (failure.response?.status === 404) {
                    removeItem(item.dataset.messageId);
                    return;
                }
                window.alert(failure.response?.status === 403
                    ? 'You cannot delete this message.'
                    : 'The message could not be deleted. Try again.');
            })
            .finally(() => {
                delete item.dataset.deleting;
            });
    });

    // --- Reactions (ISSUE-18) -------------------------------------------
    const myReactions = (item) => new Set(
        [...item.querySelectorAll('[data-reaction][aria-pressed="true"]')].map((button) => button.dataset.emoji),
    );

    // Redraw a message's reactions, keeping focus on the same emoji.
    const setReactions = (item, groups) => {
        const row = item.querySelector('[data-message-reactions]');
        if (!row) return;
        const focused = row.contains(document.activeElement)
            ? document.activeElement.closest('[data-reaction]')?.dataset.emoji
            : undefined;
        changeItem(item, () => fillReactions(row, groups, item.dataset.reactionsUrl, context.csrfToken));
        if (focused !== undefined) {
            [...row.querySelectorAll('[data-reaction]')]
                .find((button) => button.dataset.emoji === focused)
                ?.focus({ preventScroll: true });
        }
    };

    // Broadcasts have no `me`: keep ours, unless it was us in another tab.
    const applyReactions = (event) => {
        if (!belongs(event)) return;
        const item = findItem(event.message_id);
        if (!item) return;
        const mine = myReactions(item);
        if (String(event.user_id) === String(context.viewerId)) {
            if (event.added) mine.add(event.emoji);
            else mine.delete(event.emoji);
        }
        setReactions(item, (event.reactions ?? []).map((group) => ({ ...group, me: mine.has(group.emoji) })));
    };

    const closePickers = (except = null) => {
        list.querySelectorAll('[data-reaction-picker][open]').forEach((picker) => {
            if (picker !== except) picker.open = false;
        });
    };

    // "toggle" does not bubble, so listen while capturing: one picker at a time.
    list.addEventListener('toggle', (event) => {
        if (event.target.matches?.('[data-reaction-picker]') && event.target.open) closePickers(event.target);
    }, true);

    document.addEventListener('click', (event) => {
        if (!event.target.closest?.('[data-reaction-picker]')) closePickers();
    });

    list.addEventListener('keydown', (event) => {
        const picker = event.target.closest?.('[data-reaction-picker][open]');
        if (event.key !== 'Escape' || !picker) return;
        event.preventDefault();
        picker.open = false;
        picker.querySelector('summary')?.focus({ preventScroll: true });
    });

    const pendingReactions = new Set();

    list.addEventListener('submit', (event) => {
        const reactionForm = event.target.closest('[data-reaction-form]');
        if (!reactionForm) return;
        event.preventDefault();

        const item = reactionForm.closest('[data-message]');
        const url = item?.dataset.reactionsUrl;
        const emoji = reactionForm.querySelector('input[name="emoji"]')?.value;
        if (!item || !url || !emoji) return;

        const picker = reactionForm.closest('[data-reaction-picker]');
        if (picker) {
            picker.open = false;
            picker.querySelector('summary')?.focus({ preventScroll: true });
        }

        const key = `${item.dataset.messageId}:${emoji}`;
        if (pendingReactions.has(key)) return;
        pendingReactions.add(key);

        const remove = reactionForm.querySelector('input[name="_method"]')?.value === 'DELETE';
        window.axios
            .request({
                method: remove ? 'delete' : 'post',
                url,
                data: { emoji },
                headers: { Accept: 'application/json', ...socketHeaders() },
            })
            .then((response) => setReactions(item, response.data.reactions ?? []))
            .catch((failure) => {
                if (failure.response?.status === 404) {
                    removeItem(item.dataset.messageId);
                    return;
                }
                window.alert(reactionErrorMessage(failure));
            })
            .finally(() => pendingReactions.delete(key));
    });

    // "channel.{id}", or "conversation.{id}" for a DM (ISSUE-23).
    window.Echo?.private(root.dataset.broadcastChannel || `channel.${channelId}`)
        .listen('.message.sent', (message) => append(message))
        .listen('.message.updated', (message) => applyUpdate(message))
        .listen('.message.deleted', (message) => {
            if (belongs(message)) removeItem(message.id);
        })
        .listen('.reactions.updated', (event) => applyReactions(event))
        .listen('.message.pinned', (event) => {
            if (!belongs(event)) return;
            applyPinned(findItem(event.id), event.pinned_at);
            if (pinsOpen()) loadPins();
        });

    // A link such as the pins page's "Jump" (#message-{id}).
    const linked = /^#message-(\d+)$/.exec(window.location.hash);
    if (linked) jumpTo(linked[1]);

    if (!form) return;

    const input = form.querySelector('[data-message-input]');
    const errorBox = form.querySelector('[data-message-error]');
    let sending = false;

    const showError = (text) => {
        errorBox.textContent = text ?? '';
        errorBox.classList.toggle('hidden', !text);
    };

    const resize = () => {
        input.style.height = 'auto';
        input.style.height = `${input.scrollHeight}px`;
    };

    input.addEventListener('input', resize);

    // --- Mention autocomplete (ISSUE-33) ---------------------------------
    // Set up before the Enter / Escape handler below: while the list is
    // open it takes those keys (choose / close) and stops them there.
    initMentionAutocomplete({
        input,
        box: form.querySelector('[data-mention-suggestions]'),
        url: form.dataset.mentionsUrl ?? null,
        fetchSuggestions: (url, query) => window.axios.get(url, { params: { q: query } }).then((response) => response.data),
    });

    // --- Emoji picker (ISSUE-36) -----------------------------------------
    initEmojiPicker({
        toggle: form.querySelector('[data-emoji-picker-toggle]'),
        panel: form.querySelector('[data-emoji-picker]'),
        input,
    });

    // --- Replying (ISSUE-20) ---------------------------------------------
    const replyInput = form.querySelector('[data-reply-input]');
    const replyBar = form.querySelector('[data-reply-bar]');
    const replyAuthor = form.querySelector('[data-reply-bar-author]');

    const replyingTo = () => replyInput?.value || null;

    const clearReply = () => {
        if (replyInput) replyInput.value = '';
        if (replyBar) replyBar.hidden = true;
    };

    const startReply = (item) => {
        if (!replyInput) return;
        replyInput.value = item.dataset.messageId;
        if (replyAuthor) replyAuthor.textContent = item.querySelector('[data-message-author]')?.textContent ?? '';
        if (replyBar) replyBar.hidden = false;
        input.focus();
    };

    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-message-reply]');
        if (!button || !list.contains(button)) return;
        const item = button.closest('[data-message]');
        if (item) startReply(item);
    });

    form.querySelector('[data-reply-cancel]')?.addEventListener('click', () => {
        clearReply();
        input.focus();
    });

    // The message being answered was deleted: nothing to reply to any more.
    removedListeners.push((id) => {
        if (replyingTo() === id) clearReply();
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && replyingTo()) {
            event.preventDefault();
            clearReply();
            return;
        }
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    // --- Attachments (ISSUE-19) -----------------------------------------
    const fileInput = form.querySelector('[data-attachment-input]');
    const fileList = form.querySelector('[data-attachment-list]');
    const limits = {
        maxFiles: Number(form.dataset.maxFiles) || 5,
        maxBytes: Number(form.dataset.maxFileBytes) || 10 * 1024 * 1024,
    };
    let files = [];

    const renderQueue = () => {
        if (!fileList) return;
        fileList.replaceChildren(...files.map((file, index) => {
            const chip = el('li', 'flex max-w-60 items-center gap-2 rounded bg-surface-2 px-2 py-1 text-sm text-fg');
            chip.dataset.attachmentQueued = '';
            chip.append(
                el('span', 'truncate', file.name),
                el('span', 'shrink-0 text-xs text-fg-subtle', formatSize(file.size)),
            );
            const remove = el('button', 'shrink-0 rounded px-1 text-fg-muted hover:text-fg-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]', '✕');
            remove.type = 'button';
            remove.setAttribute('aria-label', `Remove ${file.name}`);
            remove.addEventListener('click', () => {
                files = files.filter((_, i) => i !== index);
                renderQueue();
                showError(null);
                input.focus();
            });
            chip.appendChild(remove);
            return chip;
        }));
        fileList.hidden = files.length === 0;
    };

    const addFiles = (incoming) => {
        const result = queueFiles(files, incoming, limits);
        files = result.files;
        renderQueue();
        showError(result.error);
    };

    // The input only picks files; the queue is sent with FormData, so the
    // input is emptied (and the same file can be picked again).
    fileInput?.addEventListener('change', () => {
        addFiles(fileInput.files);
        fileInput.value = '';
        input.focus();
    });

    input.addEventListener('paste', (event) => {
        const pasted = event.clipboardData?.files;
        if (!pasted || pasted.length === 0) return;
        event.preventDefault();
        addFiles(pasted);
    });

    form.addEventListener('dragover', (event) => {
        if ([...(event.dataTransfer?.types ?? [])].includes('Files')) event.preventDefault();
    });
    form.addEventListener('drop', (event) => {
        const dropped = event.dataTransfer?.files;
        if (!dropped || dropped.length === 0) return;
        event.preventDefault();
        addFiles(dropped);
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const content = input.value.trim();
        if ((!content && files.length === 0) || sending) return;

        sending = true;
        typing?.sent();

        const replyTo = replyingTo();
        let data = replyTo ? { content, reply_to_id: replyTo } : { content };
        if (files.length > 0) {
            data = new FormData();
            data.append('content', content);
            if (replyTo) data.append('reply_to_id', replyTo);
            files.forEach((file) => data.append('attachments[]', file, file.name));
        }

        window.axios
            .post(form.action, data, { headers: socketHeaders() })
            .then((response) => {
                append(response.data.message, true);
                input.value = '';
                files = [];
                renderQueue();
                clearReply();
                resize();
                showError(null);
            })
            .catch((error) => {
                // The original is gone: drop the reply, keep the text.
                if (error?.response?.data?.errors?.reply_to_id) clearReply();
                showError(errorMessage(error));
            })
            .finally(() => {
                sending = false;
                input.focus();
            });
    });
}

/**
 * Add picked / pasted / dropped files to the composer's queue. Files over
 * maxBytes and files past maxFiles are left out, with a message saying so
 * (the server checks again: StoreMessageRequest).
 *
 * @returns {{files: File[], error: string|null}}
 */
export function queueFiles(current, incoming, { maxFiles, maxBytes }) {
    const files = [...current];
    const errors = [];
    const megabytes = Math.round(maxBytes / (1024 * 1024));

    for (const file of incoming ?? []) {
        if (file.size > maxBytes) {
            errors.push(`${file.name} is larger than ${megabytes} MB.`);
        } else if (files.length >= maxFiles) {
            errors.push(`You can attach at most ${maxFiles} files to a message.`);
            break;
        } else {
            files.push(file);
        }
    }

    return { files, error: errors.length > 0 ? errors.join(' ') : null };
}

// Guarded so tests/js can import the helpers under plain Node.
if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-channel-messages]').forEach(initChannel);
}
