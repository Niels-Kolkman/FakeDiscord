/**
 * Live DM list in the sidebar (ISSUE-23).
 *
 * On every page of a signed-in user (<meta name="user-id">) this listens
 * on the private "App.Models.User.{id}" channel for
 * App\Events\ConversationActivity (".conversation.activity"), sent to both
 * participants whenever someone writes in a DM. If the page has a
 * [data-dm-list] (conversations/partials/dm-list.blade.php), it is reloaded
 * from the server, so the conversation moves to the top, or shows up for
 * the first time. When events arrive quickly, only the newest reload is
 * applied.
 *
 * The list HTML comes from our own server (Blade escapes it).
 *
 * initDirectMessages() takes its echo, document, fetch, parser and
 * location as options so tests/js/direct-messages.test.js can drive it.
 */

export const DM_ACTIVITY_EVENT = '.conversation.activity';

/**
 * Reload [data-dm-list] from url. isCurrent() is asked before the new list
 * goes in, so a slow older response cannot overwrite a newer one. Returns
 * whether the list was replaced; on failure it stays as it is.
 */
export async function refreshDmList({ document, fetch, parse, url, isCurrent = () => true }) {
    if (!document.querySelector('[data-dm-list]')) return false;

    try {
        const response = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
        if (!response.ok) return false;

        const fresh = parse(await response.text()).querySelector('[data-dm-list]');
        const list = document.querySelector('[data-dm-list]');
        if (!fresh || !list || !isCurrent()) return false;

        list.replaceChildren(...fresh.childNodes);
        return true;
    } catch {
        return false;
    }
}

/**
 * options: { echo, userId, document, fetch, parse,
 *            location (the list is reloaded from location.href) }
 * Returns the event handler (for tests), or null without Echo or a user.
 */
export function initDirectMessages({ echo, userId, document, fetch, parse, location }) {
    if (!echo || !userId) return null;

    let latest = 0;

    const handle = (event) => {
        if (!Number.isInteger(event?.conversation_id)) return Promise.resolve(false);

        const run = ++latest;
        return refreshDmList({ document, fetch, parse, url: location.href, isCurrent: () => run === latest });
    };

    echo.private(`App.Models.User.${userId}`).listen(DM_ACTIVITY_EVENT, handle);

    return handle;
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    initDirectMessages({
        echo: window.Echo,
        userId: document.querySelector('meta[name="user-id"]')?.getAttribute('content') || null,
        document,
        fetch: window.fetch.bind(window),
        parse: (html) => new DOMParser().parseFromString(html, 'text/html'),
        location: window.location,
    });
}
