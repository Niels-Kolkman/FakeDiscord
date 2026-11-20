/**
 * Live DM list in the sidebar.
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

/** App\Events\ConversationUpdated: a group DM changed. */
export const DM_UPDATED_EVENT = '.conversation.updated';

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
 * A group DM changed (App\Events\ConversationUpdated): renamed,
 * or people were added, removed or left.
 *
 * On any page the DM list is reloaded. On the page of that group
 * ([data-group-details][data-conversation-id]) its header and settings
 * are reloaded too, keeping the settings open if they were; when the page
 * answers 404 the viewer is no longer in the group and goes to friendsUrl.
 * Returns 'left', 'refreshed' or 'list'.
 */
export async function refreshGroup({ document, fetch, parse, location, friendsUrl, conversationId, isCurrent = () => true }) {
    const details = document.querySelector('[data-group-details]');
    const here = details && Number(details.getAttribute('data-conversation-id')) === conversationId;

    if (!here) {
        await refreshDmList({ document, fetch, parse, url: location.href, isCurrent });
        return 'list';
    }

    try {
        const response = await fetch(location.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
        if (response.status === 404 || response.status === 403) {
            if (isCurrent()) location.assign(friendsUrl);
            return 'left';
        }
        if (!response.ok) return 'list';

        const page = parse(await response.text());
        if (!isCurrent()) return 'list';

        const fresh = page.querySelector('[data-group-details]');
        const current = document.querySelector('[data-group-details]');
        if (fresh && current) {
            const wasOpen = current.querySelector('details')?.open ?? false;
            current.replaceChildren(...fresh.childNodes);
            const settings = current.querySelector('details');
            if (settings && wasOpen) settings.open = true;
        }

        const list = page.querySelector('[data-dm-list]');
        const currentList = document.querySelector('[data-dm-list]');
        if (list && currentList) currentList.replaceChildren(...list.childNodes);

        return 'refreshed';
    } catch {
        return 'list';
    }
}

/**
 * options: { echo, userId, document, fetch, parse,
 *            location (the list is reloaded from location.href),
 *            friendsUrl (where to go after being taken out of the open group) }
 * Returns the activity handler (for tests, with .updated for group
 * changes), or null without Echo or a user.
 */
export function initDirectMessages({ echo, userId, document, fetch, parse, location, friendsUrl = '/friends' }) {
    if (!echo || !userId) return null;

    let latest = 0;

    const handle = (event) => {
        if (!Number.isInteger(event?.conversation_id)) return Promise.resolve(false);

        const run = ++latest;
        return refreshDmList({ document, fetch, parse, url: location.href, isCurrent: () => run === latest });
    };

    const updated = (event) => {
        if (!Number.isInteger(event?.conversation_id)) return Promise.resolve(false);

        const run = ++latest;
        return refreshGroup({
            document, fetch, parse, location, friendsUrl,
            conversationId: event.conversation_id,
            isCurrent: () => run === latest,
        });
    };
    handle.updated = updated;

    echo.private(`App.Models.User.${userId}`)
        .listen(DM_ACTIVITY_EVENT, handle)
        .listen(DM_UPDATED_EVENT, updated);

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
