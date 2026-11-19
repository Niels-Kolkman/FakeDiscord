/**
 * Keeping Tab inside an open popup (ISSUE-38): the inbox panel
 * (notifications.js), the pinned messages panel (channel-messages.js) and
 * the profile card (profile-popover.js). Like a dialog, Tab and Shift+Tab
 * cycle through what can be focused in it until Escape or its close
 * button closes it; the popup's own code puts focus back on the button
 * that opened it.
 *
 * Found by walking the children (no CSS selector), so the same code runs
 * on the page and on the tests' fake DOM.
 */

const ALWAYS = ['BUTTON', 'SELECT', 'TEXTAREA', 'SUMMARY'];

function isFocusable(node) {
    if (node.disabled) return false;
    const tabindex = node.getAttribute?.('tabindex');
    if (tabindex !== null && tabindex !== undefined) return Number(tabindex) >= 0;
    // The href property (scripts set it) or the attribute (server markup).
    if (node.tagName === 'A') return Boolean(node.href) || node.getAttribute('href') !== null;
    if (node.tagName === 'INPUT') return node.type !== 'hidden';
    return ALWAYS.includes(node.tagName);
}

/** What Tab can reach inside `container`, in order (hidden parts left out). */
export function focusableIn(container) {
    const found = [];
    const walk = (node) => {
        for (const child of node?.children ?? []) {
            if (child.hidden) continue;
            if (isFocusable(child)) found.push(child);
            walk(child);
        }
    };
    walk(container);
    return found;
}

/**
 * For a keydown in an open popup: on Tab past the last (or Shift+Tab
 * before the first) focusable element, wrap around. Returns whether it
 * moved focus.
 */
export function trapTab(container, event, doc = globalThis.document) {
    if (event.key !== 'Tab' || !container) return false;

    const items = focusableIn(container);
    const active = doc?.activeElement ?? null;

    if (items.length === 0) {
        event.preventDefault();
        container.focus?.({ preventScroll: true });
        return true;
    }

    const first = items[0];
    const last = items[items.length - 1];
    const outside = !items.includes(active);

    if (event.shiftKey && (active === first || outside)) {
        event.preventDefault();
        last.focus?.({ preventScroll: true });
        return true;
    }
    if (!event.shiftKey && (active === last || outside)) {
        event.preventDefault();
        first.focus?.({ preventScroll: true });
        return true;
    }
    return false;
}
