/**
 * The theme toggle (components/theme-toggle.blade.php).
 *
 * Without this script each toggle is a plain form that saves the other
 * theme (PUT /settings/theme) and reloads the page. With it the page
 * switches at once (<html data-theme>, which resources/css/app.css turns
 * into the colour tokens), every toggle on the page flips to offer the
 * theme you came from, and the choice is saved in the background. If
 * saving fails the page goes back to the theme it had.
 *
 * Other tabs of the same browser follow through a localStorage key
 * (STORAGE_KEY); other browsers get it on their next page load, from the
 * user's saved theme.
 *
 * Everything takes its document, fetch, storage and window, so
 * tests/js/theme.test.js can drive it.
 */

export const THEMES = ['dark', 'light'];
export const STORAGE_KEY = 'theme-changed';

/** A known theme, or the default (dark). */
export function normalTheme(theme) {
    return THEMES.includes(theme) ? theme : THEMES[0];
}

export function otherTheme(theme) {
    return normalTheme(theme) === 'dark' ? 'light' : 'dark';
}

export function toggleLabel(next) {
    return `Switch to ${next} theme`;
}

/** Puts `theme` on the page and points every toggle at the other one. */
export function applyTheme(doc, theme) {
    const current = normalTheme(theme);
    const next = otherTheme(current);
    const label = toggleLabel(next);

    doc.documentElement.dataset.theme = current;
    doc.querySelectorAll('[data-theme-toggle]').forEach((form) => {
        const input = form.querySelector('[data-theme-next]');
        if (input) input.value = next;
        form.querySelector('[data-theme-button]')?.setAttribute('title', label);
        const icon = form.querySelector('[data-theme-icon]');
        if (icon) icon.textContent = next === 'light' ? '☀️' : '🌙';
        const text = form.querySelector('[data-theme-label]');
        if (text) text.textContent = label;
    });

    return current;
}

function csrfToken(doc, form) {
    return form.elements?.namedItem?.('_token')?.value
        ?? doc.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        ?? '';
}

/**
 * options: { document, fetch, storage, window }. Without fetch the forms
 * are left alone (they still work, with a reload).
 */
export function initTheme({ document: doc, fetch = null, storage = null, window: win = null }) {
    const forms = doc.querySelectorAll('[data-theme-toggle]');
    // Only the latest switch may apply its answer, so a slow or failed
    // earlier one cannot undo a newer click.
    let latest = 0;

    const broadcast = (theme) => {
        try {
            // The time makes every switch a change, even back to the same theme.
            storage?.setItem(STORAGE_KEY, `${theme}:${Date.now()}`);
        } catch {
            // Storage can be full or blocked; other tabs then catch up on reload.
        }
    };

    const save = async (form, theme, previous) => {
        const id = ++latest;
        try {
            const response = await fetch(form.getAttribute('action'), {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(doc, form),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ theme }),
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            if (id !== latest) return;
            broadcast(applyTheme(doc, data?.theme ?? theme));
        } catch {
            if (id === latest) applyTheme(doc, previous);
        }
    };

    if (fetch) {
        forms.forEach((form) => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const previous = normalTheme(doc.documentElement.dataset.theme);
                const theme = normalTheme(form.querySelector('[data-theme-next]')?.value ?? otherTheme(previous));
                applyTheme(doc, theme);
                save(form, theme, previous);
            });
        });
    }

    win?.addEventListener('storage', (event) => {
        if (event.key !== STORAGE_KEY || !event.newValue) return;
        applyTheme(doc, String(event.newValue).split(':')[0]);
    });
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    let storage = null;
    try {
        storage = window.localStorage;
    } catch {
        // Blocked storage: no cross-tab sync.
    }
    initTheme({ document, fetch: window.fetch.bind(window), storage, window });
}
