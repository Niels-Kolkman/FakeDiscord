/**
 * Live friend requests (ISSUE-22).
 *
 * On every page of a signed-in user (the layouts add
 * <meta name="user-id"> and <meta name="friends-url">) this listens on the
 * private "App.Models.User.{id}" channel for App\Events\FriendshipChanged
 * (".friendship.changed"):
 *
 * - "request" and "accepted" show a toast with a link to the friends page.
 *   "removed" (declined, cancelled or unfriended) is silent.
 * - Every [data-friend-request-badge] shows the event's incoming_count
 *   (hidden at 0).
 * - On the friends page, [data-friends-panel] (tabs and lists) is reloaded
 *   from the server, so the lists match what the other person did. When
 *   events arrive quickly, only the newest reload is applied.
 *
 * Names are inserted with textContent, never parsed as HTML. The panel
 * HTML comes from our own server (Blade escapes it).
 *
 * initFriends() takes its echo, document, fetch, parser and timers as
 * options so tests/js/friends.test.js can drive it.
 */

export const FRIENDSHIP_EVENT = '.friendship.changed';
export const TOAST_MS = 8000;

/** The toast text for an event, or null when it shows none. */
export function noticeText(event) {
    const name = event?.user?.name;
    if (typeof name !== 'string' || name === '') return null;

    if (event.type === 'request') return `${name} sent you a friend request.`;
    if (event.type === 'accepted') return `${name} accepted your friend request.`;

    return null;
}

/** The friends tab the toast links to. */
export function noticeTab(event) {
    return event?.type === 'request' ? 'pending' : 'all';
}

export function updateRequestBadges(root, count) {
    const value = Number.isInteger(count) && count > 0 ? count : 0;

    root.querySelectorAll('[data-friend-request-badge]').forEach((badge) => {
        badge.textContent = String(value);
        badge.hidden = value === 0;
    });
}

/**
 * Reload the friends panel from url. isCurrent() is asked before the new
 * content goes in, so a slow older response cannot overwrite a newer one.
 * Returns whether the panel was replaced; on failure the lists stay as they
 * are until the next reload.
 */
export async function refreshPanel({ document, fetch, parse, url, isCurrent = () => true }) {
    if (!document.querySelector('[data-friends-panel]')) return false;

    try {
        const response = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
        if (!response.ok) return false;

        const fresh = parse(await response.text()).querySelector('[data-friends-panel]');
        const panel = document.querySelector('[data-friends-panel]');
        if (!fresh || !panel || !isCurrent()) return false;

        panel.replaceChildren(...fresh.childNodes);
        return true;
    } catch {
        return false;
    }
}

export function showToast(document, { text, href = null, ms = TOAST_MS, setTimer = (callback, delay) => setTimeout(callback, delay) }) {
    let region = document.querySelector('[data-friend-toasts]');
    if (!region) {
        region = document.createElement('div');
        region.dataset.friendToasts = '';
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        region.className = 'pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-end gap-2 sm:left-auto';
        document.body.appendChild(region);
    }

    const toast = document.createElement('div');
    toast.dataset.friendToast = '';
    toast.className = 'pointer-events-auto flex max-w-sm items-center gap-3 rounded-lg bg-[#111214] px-4 py-3 text-sm text-[#dbdee1] shadow-2xl';

    const message = document.createElement('p');
    message.textContent = text;
    toast.appendChild(message);

    if (href) {
        const link = document.createElement('a');
        link.href = href;
        link.className = 'shrink-0 font-medium text-[#00a8fc] hover:underline';
        link.textContent = 'View';
        toast.appendChild(link);
    }

    region.appendChild(toast);
    setTimer(() => toast.remove(), ms);

    return toast;
}

/**
 * options: { echo, userId, friendsUrl, document, fetch, parse,
 *            location (the panel is reloaded from location.href),
 *            toastMs, setTimer }
 * Returns the event handler (for tests), or null without Echo or a user.
 */
export function initFriends({
    echo,
    userId,
    friendsUrl = null,
    document,
    fetch,
    parse,
    location,
    toastMs = TOAST_MS,
    setTimer = undefined,
}) {
    if (!echo || !userId) return null;

    let latest = 0;

    const handle = async (event) => {
        if (Number.isInteger(event?.incoming_count)) updateRequestBadges(document, event.incoming_count);

        const text = noticeText(event);
        if (text) {
            const href = friendsUrl ? `${friendsUrl}?tab=${noticeTab(event)}` : null;
            showToast(document, { text, href, ms: toastMs, ...(setTimer ? { setTimer } : {}) });
        }

        const run = ++latest;
        return refreshPanel({ document, fetch, parse, url: location.href, isCurrent: () => run === latest });
    };

    echo.private(`App.Models.User.${userId}`).listen(FRIENDSHIP_EVENT, handle);

    return handle;
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || null;

    initFriends({
        echo: window.Echo,
        userId: meta('user-id'),
        friendsUrl: meta('friends-url'),
        document,
        fetch: window.fetch.bind(window),
        parse: (html) => new DOMParser().parseFromString(html, 'text/html'),
        location: window.location,
    });
}
