/**
 * Screen reader announcements for new messages (ISSUE-38).
 *
 * The message list (messages/panel.blade.php) is role="log" but
 * aria-live="off": as a live region it would also read every page of
 * older messages loaded on scroll, and each row in full (avatar button,
 * time, Reply / Edit / Delete / Pin, reactions). Instead,
 * resources/js/channel-messages.js passes messages that arrive from other
 * people to an announcer on the panel's [data-message-announcer], a
 * visually hidden aria-live="polite" region, as one short line each:
 *
 *   "New message from Alice: see you at 8"
 *   "Alice mentioned you: @bob are you coming?"
 *   "New message from Alice: 2 attachments"
 *
 * The region keeps the last few lines only; new ones are additions, so
 * screen readers read just those.
 */
import { parseMarkdown } from './markdown.js';

/** Longer text is cut (with "…"): the full message is in the log. */
export const MAX_ANNOUNCED_LENGTH = 200;
/** Lines kept in the region. */
export const KEEP_ANNOUNCEMENTS = 5;

// Blocks end a line, so their words must not run into the next ones.
const BLOCKS = ['quote', 'codeblock'];

/** The text of a markdown tree, without its formatting characters. */
export function plainText(nodes) {
    return (nodes ?? [])
        .map((node) => {
            const text = node.children ? plainText(node.children) : (node.text ?? '');
            return BLOCKS.includes(node.type) ? ` ${text} ` : text;
        })
        .join('');
}

function attachmentsText(count) {
    return count === 1 ? '1 attachment' : `${count} attachments`;
}

/**
 * One line for a message payload (App\Http\Resources\MessageResource).
 * `mention`: the message mentions the viewer.
 */
export function announcementText(message, { mention = false } = {}) {
    if (!message) return '';
    const name = message.author?.name ?? 'Deleted User';

    let text = plainText(parseMarkdown(message.content)).replace(/\s+/g, ' ').trim();
    if (text.length > MAX_ANNOUNCED_LENGTH) text = `${text.slice(0, MAX_ANNOUNCED_LENGTH - 1).trimEnd()}…`;

    const files = message.attachments?.length ?? 0;
    let body = text;
    if (files > 0) body = text ? `${text}, with ${attachmentsText(files)}` : attachmentsText(files);

    const prefix = mention ? `${name} mentioned you` : `New message from ${name}`;
    return body ? `${prefix}: ${body}` : prefix;
}

/**
 * An announcer on `region` (null: announce() does nothing).
 * Returns { announce(text) }.
 */
export function createAnnouncer(region, { keep = KEEP_ANNOUNCEMENTS, doc = globalThis.document } = {}) {
    return {
        announce(text) {
            if (!region || !text) return;
            const line = doc.createElement('p');
            line.textContent = text;
            region.appendChild(line);
            while (region.children.length > keep) region.children[0].remove();
        },
    };
}
