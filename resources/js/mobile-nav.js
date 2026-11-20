/**
 * Drawers on small screens (below 768px; components/layouts/app.blade.php).
 *
 * The server rail + channel (or friends / DM) sidebar is the "nav" drawer
 * on the left, a server's member list the "members" drawer on the right.
 * resources/css/app.css slides them in when they have data-open; this
 * opens and closes them:
 *
 * - the buttons in the page's top bar ([data-drawer-toggle]);
 * - swipes: right opens the nav drawer, left the member list, and a swipe
 *   back the way a drawer came closes it. Swipes that start in a text
 *   field, a select or a code block (which scroll sideways), or in
 *   [data-no-swipe], are left alone;
 * - Escape and a tap on the backdrop close the open drawer.
 *
 * While a drawer is open the rest of the page is inert, so keyboard and
 * screen reader users stay in it; closing returns focus to the button
 * that opened it. Growing past the breakpoint closes it, since from md up
 * the panes are side by side again.
 */

export const MOBILE_QUERY = '(max-width: 767.98px)';
/** A swipe this far (px) always counts... */
export const SWIPE_DISTANCE = 60;
/** ...and a quick flick (this far within FLICK_MS) too. */
export const FLICK_DISTANCE = 30;
export const FLICK_MS = 250;

const SWIPE_EXEMPT_TAGS = ['INPUT', 'TEXTAREA', 'SELECT', 'PRE'];

/**
 * 'left', 'right' or null for a touch that went from `start` to `end`
 * ({x, y, t}). Mostly vertical moves (scrolling) are never swipes.
 */
export function swipeDirection(start, end) {
    if (!start || !end) return null;
    const dx = end.x - start.x;
    const dy = end.y - start.y;
    const distance = Math.abs(dx);
    if (distance < Math.abs(dy) * 1.5) return null;

    const quick = end.t - start.t <= FLICK_MS;
    if (distance < SWIPE_DISTANCE && !(quick && distance >= FLICK_DISTANCE)) return null;

    return dx > 0 ? 'right' : 'left';
}

function swipeExempt(target) {
    for (let node = target; node; node = node.parentNode) {
        if (SWIPE_EXEMPT_TAGS.includes(node.tagName)) return true;
        if (node.isContentEditable) return true;
        if (node.dataset && 'noSwipe' in node.dataset) return true;
    }
    return false;
}

function point(touch, now) {
    return touch ? { x: touch.clientX, y: touch.clientY, t: now() } : null;
}

/**
 * options: { document, matchMedia, now }. Returns { open, close, current }
 * (for tests), or null on pages without drawers.
 */
export function initMobileNav({ document: doc, matchMedia = null, now = () => Date.now() }) {
    const drawers = {};
    doc.querySelectorAll('[data-drawer]').forEach((drawer) => {
        drawers[drawer.dataset.drawer] = drawer;
    });
    if (Object.keys(drawers).length === 0) return null;

    const toggles = doc.querySelectorAll('[data-drawer-toggle]');
    const backdrop = doc.querySelector('[data-drawer-backdrop]');
    const main = doc.querySelector('[data-app-main]');
    // The skip link is outside <main>, but must not be a way out of a drawer either.
    const skipLink = doc.querySelector('[data-skip-link]');
    const media = matchMedia ? matchMedia(MOBILE_QUERY) : null;
    const isMobile = () => Boolean(media?.matches);

    let current = null;
    let returnFocus = null;

    const setExpanded = (name, expanded) => {
        toggles.forEach((toggle) => {
            if (toggle.dataset.drawerToggle === name) toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
    };

    // Everything but the open drawer.
    const setInert = (openName, inert) => {
        const others = [main, skipLink, ...Object.entries(drawers).filter(([name]) => name !== openName).map(([, el]) => el)];
        others.forEach((el) => {
            if (!el) return;
            if (inert) el.setAttribute('inert', '');
            else el.removeAttribute('inert');
        });
    };

    const close = ({ restoreFocus = true } = {}) => {
        if (current === null) return;
        const name = current;
        delete drawers[name].dataset.open;
        setExpanded(name, false);
        setInert(name, false);
        if (backdrop) backdrop.hidden = true;
        current = null;

        if (restoreFocus) returnFocus?.focus?.();
        returnFocus = null;
    };

    const open = (name, opener = null) => {
        const drawer = drawers[name];
        if (!drawer || !isMobile() || current === name) return;
        if (current !== null) close({ restoreFocus: false });

        returnFocus = opener ?? doc.activeElement ?? null;
        drawer.dataset.open = '';
        setExpanded(name, true);
        setInert(name, true);
        if (backdrop) backdrop.hidden = false;
        current = name;

        // Focus the drawer itself, so Tab goes on to its first link.
        if (drawer.getAttribute('tabindex') === null) drawer.setAttribute('tabindex', '-1');
        drawer.focus?.({ preventScroll: true });
    };

    toggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const name = toggle.dataset.drawerToggle;
            if (current === name) close();
            else open(name, toggle);
        });
    });

    backdrop?.addEventListener('click', () => close());

    doc.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && current !== null) {
            event.preventDefault();
            close();
        }
    });

    let start = null;
    doc.addEventListener('touchstart', (event) => {
        start = null;
        if (!isMobile() || event.touches?.length !== 1 || swipeExempt(event.target)) return;
        start = point(event.touches[0], now);
    }, { passive: true });

    doc.addEventListener('touchcancel', () => { start = null; }, { passive: true });

    doc.addEventListener('touchend', (event) => {
        const direction = swipeDirection(start, point(event.changedTouches?.[0], now));
        start = null;
        if (direction === null || !isMobile()) return;

        if (current === 'nav') {
            if (direction === 'left') close();
        } else if (current === 'members') {
            if (direction === 'right') close();
        } else {
            open(direction === 'right' ? 'nav' : 'members');
        }
    }, { passive: true });

    media?.addEventListener?.('change', (event) => {
        if (!event.matches) close({ restoreFocus: false });
    });

    return { open, close, current: () => current };
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    initMobileNav({ document, matchMedia: window.matchMedia?.bind(window) ?? null });
}
