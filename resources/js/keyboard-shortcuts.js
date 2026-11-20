/**
 * Keyboard shortcuts for switching channels, like Discord:
 *
 *   Alt+ArrowUp / Alt+ArrowDown              previous / next channel
 *   Alt+Shift+ArrowUp / Alt+Shift+ArrowDown  previous / next unread channel
 *
 * "Channels" are the rows of the server's channel sidebar
 * (servers/partials/sidebar.blade.php, [data-channel]) or, on the friends
 * and DM pages, the direct messages (conversations/partials/dm-list,
 * [data-dm-id]), in the order they are shown. Both wrap around. Plain
 * Alt+arrows skip channels hidden in a collapsed category; the unread ones
 * ([data-unread], resources/js/unread.js) are found anywhere.
 *
 * Left alone:
 * - Alt+arrows on a focused channel or category in a sidebar that can be
 *   reordered ([data-layout-url]): that moves it (channel-sidebar.js);
 * - a text field with text in it, so a draft is never lost to a page load;
 * - keys another handler already used (event.defaultPrevented), and
 *   Ctrl / Cmd combinations.
 *
 * A switch loads the channel's page. The next page then focuses the
 * composer (or the main content), so the keyboard is where it was.
 */

export const FOCUS_KEY = 'fakediscord.shortcut-focus';

function linkOf(item) {
    return [...(item.children ?? [])].find((child) => child.tagName === 'A') ?? null;
}

/**
 * The channels (or DMs) on the page, in order:
 * [{ element, link, active, unread, hidden }].
 */
export function navigationItems(doc) {
    const sidebar = doc.querySelector('[data-channel-sidebar]');
    const rows = sidebar
        ? sidebar.querySelectorAll('[data-channel]')
        : (doc.querySelector('[data-dm-list]')?.querySelectorAll('[data-dm-id]') ?? []);

    return [...rows]
        .map((element) => {
            const link = linkOf(element);
            return {
                element,
                link,
                active: link?.getAttribute('aria-current') === 'page',
                unread: element.dataset.unread !== undefined,
                hidden: Boolean(element.hidden),
            };
        })
        .filter((item) => item.link?.getAttribute('href'));
}

/**
 * The item to switch to from the active one (from the top or bottom
 * when none is active), or null.
 */
export function targetFor(items, { up = false, unread = false } = {}) {
    const count = items.length;
    const active = items.findIndex((item) => item.active);
    const start = active !== -1 ? active : (up ? count : -1);

    for (let step = 1; step <= count; step += 1) {
        const item = items[(((start + (up ? -step : step)) % count) + count) % count];
        if (item.active) continue;
        if (unread ? item.unread : !item.hidden) return item;
    }
    return null;
}

function holdsText(target) {
    if (!target) return false;
    if (target.isContentEditable) return (target.textContent ?? '') !== '';
    return ['INPUT', 'TEXTAREA'].includes(target.tagName) && (target.value ?? '') !== '';
}

function reordering(target) {
    if (!target?.closest?.('[data-layout-url]')) return false;
    return Boolean(target.closest('[data-channel]') || target.closest('[data-category-toggle]'));
}

function defaultStorage() {
    try {
        return globalThis.sessionStorage ?? null;
    } catch {
        return null; // blocked storage
    }
}

/**
 * options: { document, navigate(url), storage }. Returns { handle(event) }
 * (for tests).
 */
export function initKeyboardShortcuts({ document: doc, navigate, storage = defaultStorage() }) {
    // Arrived here with a shortcut: put the keyboard back in the page.
    let arrived = false;
    try {
        arrived = storage?.getItem(FOCUS_KEY) === '1';
        if (arrived) storage.removeItem(FOCUS_KEY);
    } catch {
        arrived = false;
    }
    if (arrived) {
        const target = doc.querySelector('[data-message-input]') ?? doc.querySelector('[data-app-main]');
        target?.focus?.({ preventScroll: true });
    }

    const handle = (event) => {
        if (event.defaultPrevented || !event.altKey || event.ctrlKey || event.metaKey || event.isComposing) return;
        if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return;
        if (!event.shiftKey && reordering(event.target)) return;
        if (holdsText(event.target)) return;

        const target = targetFor(navigationItems(doc), { up: event.key === 'ArrowUp', unread: event.shiftKey });
        if (!target) return;

        event.preventDefault();
        try {
            storage?.setItem(FOCUS_KEY, '1');
        } catch {
            // Without storage the next page just starts at the top.
        }
        navigate(target.link.getAttribute('href'));
    };

    doc.addEventListener('keydown', handle);
    return { handle };
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    initKeyboardShortcuts({ document, navigate: (url) => window.location.assign(url) });
}
