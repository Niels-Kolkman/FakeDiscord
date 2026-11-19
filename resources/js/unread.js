/**
 * Unread channels and mention badges, live (ISSUE-32).
 *
 * The server renders the state when the page loads (App\Support\UnreadState,
 * servers/partials/channel-item.blade.php); this keeps the channel sidebar
 * up to date while the page stays open:
 *
 * - Every text channel in [data-channel-sidebar] is subscribed to on its
 *   private "channel.{id}" channel (routes/channels.php only lets in
 *   members who can see it). When App\Events\MessageSent (".message.sent")
 *   arrives in another channel, the row gets data-unread (the CSS shows
 *   the dot and the bold name) and, if the message mentions you
 *   (mentionsViewer(): by name, a role in data-viewer-role-ids or
 *   @everyone, ISSUE-33), its badge counts up.
 * - In the open channel ([data-channel-messages][data-read-url]) a message
 *   from someone else is marked read (POST data-read-url, field
 *   message_id, ChannelReadController) once you have seen it: right away
 *   when the tab is visible (batched over MARK_READ_DELAY_MS), otherwise
 *   when you come back to it.
 *
 * Your own messages and those of people you blocked (data-blocked-user-ids)
 * never count. The server lists (dashboard, friends sidebar) are not live.
 *
 * initUnread() takes its echo, document, post and timer functions as
 * options so tests/js/unread.test.js can drive it.
 */

export const MESSAGE_SENT = '.message.sent';

export const MARK_READ_DELAY_MS = 1000;

const escapeRegExp = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * Whether content mentions @username. Must match App\Support\Mentions::pattern().
 * Only a fallback now: the payload says who a message mentions (mentionsViewer()).
 */
export function mentionsUser(content, username) {
    if (typeof content !== 'string' || typeof username !== 'string' || username === '') return false;

    const pattern = new RegExp(`(?<![a-z0-9_.@])@${escapeRegExp(username.toLowerCase())}(?![a-z0-9_]|\\.[a-z0-9_])`, 'iu');

    return pattern.test(content);
}

/**
 * Whether a message payload mentions the viewer (ISSUE-33). The server
 * works out who a message mentions when it is saved (App\Support\Mentions,
 * `mentions`: { users, roles, everyone }): by id, a role they hold
 * (roleIds) or @everyone. A payload without `mentions` (an older server)
 * falls back to looking for @username in the content.
 */
export function mentionsViewer(message, { userId = null, username = '', roleIds = new Set() } = {}) {
    if (!message) return false;

    const mentions = message.mentions;
    if (mentions === undefined) return mentionsUser(message.content, username);
    if (!mentions || typeof mentions !== 'object') return false;

    if (mentions.everyone === true) return true;
    if (userId !== null && (mentions.users ?? []).some((id) => String(id) === String(userId))) return true;

    return (mentions.roles ?? []).some((id) => roleIds.has(String(id)));
}

/** Whether a message from the broadcast should count as unread for the viewer. */
export function countsAsUnread(message, { userId, blockedUserIds = new Set() }) {
    if (!message || message.id === undefined) return false;

    const authorId = message.author?.id;
    if (authorId === undefined || authorId === null) return true;

    return String(authorId) !== String(userId) && !blockedUserIds.has(String(authorId));
}

/** Mark a sidebar row unread; `mention` adds one to its badge. */
export function applyUnread(item, { mention = false } = {}) {
    item.dataset.unread = '';

    if (!mention) return;

    const badge = item.querySelector('[data-mention-badge]');
    const count = badge?.querySelector('[data-mention-count]');
    if (!badge || !count) return;

    const total = (Number.parseInt(count.textContent, 10) || 0) + 1;
    count.textContent = String(total);
    const label = badge.querySelector('[data-mention-label]');
    if (label) label.textContent = total === 1 ? ' mention' : ' mentions';
    badge.hidden = false;
}

const parseIds = (json) => {
    try {
        const ids = JSON.parse(json ?? '[]');
        return new Set(Array.isArray(ids) ? ids.map(String) : []);
    } catch {
        return new Set();
    }
};

/**
 * options: { echo, document, userId, username, post, setTimeout, clearTimeout }
 * `post(url, data)` returns a promise. Returns { markRead, flush } for
 * tests, or null without Echo or a sidebar.
 */
export function initUnread({
    echo,
    document,
    userId,
    username,
    post,
    setTimeout: schedule = globalThis.setTimeout,
    clearTimeout: cancel = globalThis.clearTimeout,
}) {
    const sidebar = document.querySelector('[data-channel-sidebar]');
    if (!echo || !sidebar) return null;

    const panel = document.querySelector('[data-channel-messages][data-read-url]');
    const openChannelId = panel?.dataset.channelId ?? null;
    const readUrl = panel?.dataset.readUrl ?? null;
    const blockedUserIds = parseIds(sidebar.dataset.blockedUserIds);
    const roleIds = parseIds(sidebar.dataset.viewerRoleIds);
    const visible = () => document.visibilityState !== 'hidden';

    // Highest message id seen in the open channel but not sent yet.
    let pending = 0;
    let timer = null;

    const flush = () => {
        timer = null;
        if (!readUrl || pending === 0) return null;

        const messageId = pending;
        pending = 0;

        // A failed request only means the channel shows unread on the next load.
        return Promise.resolve(post(readUrl, { message_id: messageId })).catch(() => {});
    };

    const markRead = (messageId) => {
        pending = Math.max(pending, Number(messageId) || 0);
        if (!visible() || timer !== null) return;
        timer = schedule(flush, MARK_READ_DELAY_MS);
    };

    document.addEventListener?.('visibilitychange', () => {
        if (visible() && pending > 0 && timer === null) {
            timer = schedule(flush, MARK_READ_DELAY_MS);
        } else if (!visible() && timer !== null) {
            cancel(timer);
            timer = null;
        }
    });

    sidebar.querySelectorAll('[data-channel]').forEach((item) => {
        const channelId = item.dataset.channelId;
        if (!channelId || item.dataset.channelType === 'voice') return;

        echo.private(`channel.${channelId}`).listen(MESSAGE_SENT, (message) => {
            if (!countsAsUnread(message, { userId, blockedUserIds })) return;

            if (channelId === openChannelId) {
                markRead(message.id);
            } else {
                applyUnread(item, { mention: mentionsViewer(message, { userId, username, roleIds }) });
            }
        });
    });

    return { markRead, flush };
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    initUnread({
        echo: window.Echo,
        document,
        userId: document.querySelector('meta[name="user-id"]')?.getAttribute('content') || null,
        username: document.querySelector('meta[name="username"]')?.getAttribute('content') || '',
        post: (url, data) => window.axios.post(url, data),
    });
}
