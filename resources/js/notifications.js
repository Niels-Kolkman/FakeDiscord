/**
 * The notification inbox (ISSUE-33): the bell in the page header
 * (components/inbox.blade.php) and its panel.
 *
 * - The bell opens the panel instead of the inbox page; the first time it
 *   loads the inbox from data-inbox-url (JSON, NotificationController::index).
 *   Each entry links to the message (NotificationController::open marks it
 *   read on the way). "Mark all read" posts to data-read-all-url.
 * - App\Notifications\MentionedInMessage arrives live on the user's private
 *   "App.Models.User.{id}" channel (Echo's .notification()): the count goes
 *   up and, once loaded, the entry is added to the top of the list. A
 *   mention in the channel you are looking at (visible tab) does not count:
 *   you see it, and marking the channel read marks it read on the server
 *   too (App\Actions\Channels\MarkChannelRead).
 *
 * initInbox() takes its echo, document and request functions as options so
 * tests/js/notifications.test.js can drive it.
 */

export const NOTIFICATION_TYPE = 'mention.created';

/** Like App\Support\NotificationInbox::badge(): "", "3" or "99+". */
export function badgeText(count) {
    const n = Number(count) || 0;
    if (n <= 0) return '';
    return n > 99 ? '99+' : String(n);
}

function el(doc, tag, className, text) {
    const node = doc.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function formatWhen(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleString([], { dateStyle: 'short', timeStyle: 'short' });
}

/**
 * One inbox entry, the same markup as notifications/item.blade.php.
 * `item` is App\Support\NotificationInbox::present() (the JSON and the
 * broadcast); every text goes in as text.
 */
export function renderNotification(item, doc = globalThis.document) {
    const row = el(doc, 'li', 'border-b border-edge last:border-b-0 data-[unread]:bg-[#5865f2]/10');
    row.dataset.notification = '';
    row.dataset.notificationId = String(item.id ?? '');
    if (!item.read) row.dataset.unread = '';

    const link = el(doc, 'a', 'flex items-start gap-3 px-4 py-3 hover:bg-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#5865f2]');
    link.href = item.open_url ?? item.url ?? '#';
    link.dataset.notificationLink = '';

    if (item.avatar_url) {
        const avatar = el(doc, 'img', 'h-8 w-8 shrink-0 rounded-full object-cover');
        avatar.src = item.avatar_url;
        avatar.alt = '';
        avatar.width = 32;
        avatar.height = 32;
        link.appendChild(avatar);
    } else {
        const blank = el(doc, 'span', 'h-8 w-8 shrink-0 rounded-full bg-[#4e5058]');
        blank.setAttribute('aria-hidden', 'true');
        link.appendChild(blank);
    }

    const text = el(doc, 'span', 'min-w-0 flex-1');

    const title = el(doc, 'span', 'block text-sm text-fg');
    const author = el(doc, 'span', 'font-semibold text-fg-strong', item.author_name ?? 'Deleted User');
    author.dataset.notificationAuthor = '';
    const label = el(doc, 'span', null, item.label ?? 'mentioned you');
    label.dataset.notificationLabel = '';
    title.append(author, doc.createTextNode(' '), label);

    const when = formatWhen(item.created_at);
    const where = el(doc, 'span', 'block truncate text-xs text-fg-subtle', `${item.server_name ?? ''} · #${item.channel_name ?? ''}${when ? ` · ${when}` : ''}`);
    where.dataset.notificationWhere = '';

    const excerpt = el(doc, 'span', 'mt-0.5 block truncate text-sm text-fg-muted', item.excerpt ?? '');
    excerpt.dataset.notificationExcerpt = '';

    text.append(title, where, excerpt);
    link.appendChild(text);

    if (!item.read) {
        const dot = el(doc, 'span', 'mt-1.5 h-2 w-2 shrink-0 rounded-full bg-[#5865f2]');
        dot.dataset.notificationDot = '';
        dot.appendChild(el(doc, 'span', 'sr-only', '(unread)'));
        link.appendChild(dot);
    }

    row.appendChild(link);
    return row;
}

/** Shows an entry as read: no highlight, no dot. */
export function markRowRead(row) {
    delete row.dataset.unread;
    row.querySelector('[data-notification-dot]')?.remove();
}

/**
 * options: { echo, document, userId, get(url) => Promise<data>,
 * post(url) => Promise<data>, openChannelId, isVisible() }. Returns a
 * handle for tests, or null without the bell.
 */
export function initInbox({
    echo,
    document: doc,
    userId,
    get,
    post,
    openChannelId = null,
    isVisible = () => doc.visibilityState !== 'hidden',
}) {
    const root = doc.querySelector('[data-inbox]');
    if (!root) return null;

    const toggle = root.querySelector('[data-inbox-toggle]');
    const badge = root.querySelector('[data-inbox-badge]');
    const countText = root.querySelector('[data-inbox-count]');
    const panel = root.querySelector('[data-inbox-panel]');
    const list = root.querySelector('[data-inbox-list]');
    const status = root.querySelector('[data-inbox-status]');
    const readAll = root.querySelector('[data-inbox-read-all]');

    let count = Number.parseInt(root.dataset.unreadCount ?? '0', 10) || 0;
    let loaded = false;
    let loading = null;

    const setCount = (value) => {
        count = Math.max(0, Number(value) || 0);
        if (countText) countText.textContent = badgeText(count);
        if (badge) badge.hidden = count === 0;
    };

    const setStatus = (text) => {
        if (!status) return;
        status.textContent = text;
        status.hidden = text === '';
    };

    const showEmpty = () => {
        if (list && list.children.length === 0) setStatus('No mentions yet. When someone mentions you, it shows up here.');
        else setStatus('');
    };

    const load = () => {
        if (loading) return loading;
        setStatus('Loading…');

        loading = Promise.resolve(get(root.dataset.inboxUrl))
            .then((data) => {
                const items = Array.isArray(data?.notifications) ? data.notifications : [];
                list?.replaceChildren(...items.map((item) => renderNotification(item, doc)));
                if (data?.unread_count !== undefined) setCount(data.unread_count);
                loaded = true;
                showEmpty();
            })
            .catch(() => {
                setStatus('The inbox could not be loaded. Close it and try again.');
            })
            .finally(() => {
                loading = null;
            });

        return loading;
    };

    const isOpen = () => panel !== null && !panel.hidden;

    const open = () => {
        if (!panel) return null;
        panel.hidden = false;
        toggle?.setAttribute('aria-expanded', 'true');
        return loaded ? Promise.resolve() : load();
    };

    const close = () => {
        if (!panel) return;
        panel.hidden = true;
        toggle?.setAttribute('aria-expanded', 'false');
    };

    toggle?.setAttribute('aria-expanded', 'false');
    toggle?.addEventListener('click', (event) => {
        // Ctrl/Cmd-click still opens the inbox page in a new tab.
        if (event.ctrlKey || event.metaKey || event.shiftKey) return;
        event.preventDefault();
        if (isOpen()) close();
        else open();
    });

    root.querySelector('[data-inbox-close]')?.addEventListener('click', () => {
        close();
        toggle?.focus?.();
    });

    panel?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            toggle?.focus?.();
        }
    });

    doc.addEventListener?.('click', (event) => {
        if (isOpen() && !root.contains(event.target)) close();
    });

    const markAllRead = () => Promise.resolve(post(root.dataset.readAllUrl))
        .then((data) => {
            setCount(data?.unread_count ?? 0);
            list?.querySelectorAll('[data-notification][data-unread]').forEach(markRowRead);
        })
        // Nothing changes on screen; the next load shows the real state.
        .catch(() => {});

    readAll?.addEventListener('click', () => {
        markAllRead();
    });

    const receive = (notification) => {
        if (!notification || notification.type !== NOTIFICATION_TYPE) return;

        const seenHere = openChannelId !== null
            && String(notification.channel_id) === String(openChannelId)
            && isVisible();
        const item = seenHere ? { ...notification, read: true } : notification;

        if (!seenHere) setCount(count + 1);

        if (loaded && list) {
            list.prepend(renderNotification(item, doc));
            showEmpty();
        }
    };

    if (echo && userId) {
        echo.private(`App.Models.User.${userId}`).notification(receive);
    }

    return { open, close, load, markAllRead, receive, setCount, count: () => count, isOpen };
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    initInbox({
        echo: window.Echo,
        document,
        userId: document.querySelector('meta[name="user-id"]')?.getAttribute('content') || null,
        get: (url) => window.axios.get(url, { headers: { Accept: 'application/json' } }).then((response) => response.data),
        post: (url) => window.axios.post(url, {}, { headers: { Accept: 'application/json' } }).then((response) => response.data),
        openChannelId: document.querySelector('[data-channel-messages][data-channel-id]')?.dataset.channelId ?? null,
    });
}
