/**
 * The composer's emoji picker (ISSUE-36), set up by channel-messages.js
 * for [data-emoji-picker-toggle] and [data-emoji-picker] in
 * messages/panel.blade.php. Both are rendered hidden: inserting needs this
 * script, so without it there is no button.
 *
 * - 😀 opens a panel with a search box and the emoji of emoji-data.js by
 *   category, the recently used ones first. Clicking one (or Enter on it,
 *   or Enter in the search box for the first result) inserts it at the
 *   cursor, keeps the composer's maxlength, closes the panel and puts the
 *   focus back in the composer. An "input" event is sent so the composer
 *   resizes and the typing indicator / mention autocomplete see the change.
 * - The arrow keys move between emoji (COLUMNS per row); Escape closes and
 *   focuses the button again; clicking outside closes.
 * - Recently used emoji are kept in localStorage (RECENT_KEY) for this
 *   browser only; a blocked or broken storage just means no recent row.
 *
 * Everything is built with createElement / textContent.
 */

import { EMOJI_CATEGORIES } from './emoji-data.js';

export const RECENT_KEY = 'fakediscord.recent-emoji';
export const MAX_RECENT = 16;
export const COLUMNS = 8;

const EMOJI_BUTTON = 'flex h-9 w-9 items-center justify-center rounded text-2xl leading-none hover:bg-active focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]';

/** emoji → name, for labelling the recent row. */
const NAMES = new Map(EMOJI_CATEGORIES.flatMap((category) => category.emoji));

/**
 * The emoji whose name has a word starting with every word of the query
 * ("thumb up" finds 👍). An empty query finds nothing.
 *
 * @returns {Array<[string, string]>}
 */
export function searchEmoji(query, categories = EMOJI_CATEGORIES) {
    const words = String(query ?? '').toLowerCase().trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) return [];

    const found = [];
    const seen = new Set();
    categories.forEach((category) => category.emoji.forEach(([emoji, name]) => {
        const nameWords = name.toLowerCase().split(/[\s-]+/);
        if (seen.has(emoji) || !words.every((word) => nameWords.some((part) => part.startsWith(word)))) return;
        seen.add(emoji);
        found.push([emoji, name]);
    }));
    return found;
}

/**
 * The value with `text` in place of the selection [start, end), and where
 * the cursor goes. Missing or out of range positions mean the end.
 */
export function insertText(value, start, end, text) {
    const current = String(value ?? '');
    const valid = (position) => Number.isInteger(position) && position >= 0 && position <= current.length;
    const from = valid(start) ? start : current.length;
    const to = valid(end) && end >= from ? end : from;
    return { value: current.slice(0, from) + text + current.slice(to), caret: from + text.length };
}

/** The recent list with `emoji` first, without repeats, at most `max`. */
export function addRecent(recent, emoji, max = MAX_RECENT) {
    return [emoji, ...recent.filter((other) => other !== emoji)].slice(0, max);
}

/** The recently used emoji saved in this browser; [] when unreadable. */
export function readRecent(storage) {
    try {
        const saved = JSON.parse(storage?.getItem(RECENT_KEY) ?? '[]');
        if (!Array.isArray(saved)) return [];
        return saved.filter((emoji) => typeof emoji === 'string' && NAMES.has(emoji)).slice(0, MAX_RECENT);
    } catch {
        return [];
    }
}

function writeRecent(storage, recent) {
    try {
        storage?.setItem(RECENT_KEY, JSON.stringify(recent));
    } catch {
        // Storage full or blocked: recent emoji are only a convenience.
    }
}

function defaultStorage() {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

/**
 * Wire up one composer's picker. Returns null when a part is missing.
 * options: { toggle, panel, input, storage, doc }
 */
export function initEmojiPicker({ toggle, panel, input, storage = defaultStorage(), doc = globalThis.document } = {}) {
    if (!toggle || !panel || !input || !doc) return null;

    const el = (tag, className, text) => {
        const node = doc.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };

    let recent = readRecent(storage);
    let search = null;
    let results = null;

    const emojiButton = (emoji, name) => {
        const button = el('button', EMOJI_BUTTON, emoji);
        button.type = 'button';
        button.title = name;
        button.setAttribute('aria-label', name);
        button.dataset.emoji = emoji;
        return button;
    };

    const section = (label, entries, key) => {
        const wrapper = el('section', 'mb-2');
        wrapper.dataset.emojiSection = key;
        wrapper.appendChild(el('h3', 'px-1 pb-1 text-xs font-semibold uppercase text-fg-muted', label));
        const grid = el('div', 'grid grid-cols-8 gap-0.5');
        grid.setAttribute('role', 'group');
        grid.setAttribute('aria-label', label);
        entries.forEach(([emoji, name]) => grid.appendChild(emojiButton(emoji, name)));
        wrapper.appendChild(grid);
        return wrapper;
    };

    const renderResults = () => {
        const query = search.value.trim();
        if (query !== '') {
            const found = searchEmoji(query);
            if (found.length === 0) {
                const empty = el('p', 'px-1 py-6 text-center text-sm text-fg-subtle', 'No emoji match that search.');
                empty.dataset.emojiEmpty = '';
                results.replaceChildren(empty);
            } else {
                results.replaceChildren(section('Search results', found, 'search'));
            }
            return;
        }

        const sections = [];
        if (recent.length > 0) sections.push(section('Recently used', recent.map((emoji) => [emoji, NAMES.get(emoji)]), 'recent'));
        EMOJI_CATEGORIES.forEach((category) => sections.push(section(category.label, category.emoji, category.id)));
        results.replaceChildren(...sections);
    };

    // Built on first open: a few hundred buttons nobody may need.
    const build = () => {
        if (search) return;
        search = el('input', 'mb-2 w-full rounded bg-surface-3 px-2 py-1.5 text-sm text-fg placeholder-fg-dim focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]');
        search.type = 'search';
        search.value = '';
        search.placeholder = 'Find the perfect emoji';
        search.setAttribute('aria-label', 'Search emoji');
        search.autocomplete = 'off';
        search.dataset.emojiSearch = '';
        results = el('div', 'max-h-72 overflow-y-auto pr-1');
        results.dataset.emojiResults = '';
        panel.replaceChildren(search, results);
        search.addEventListener('input', renderResults);
        renderResults();
    };

    const isOpen = () => !panel.hidden;

    const close = (focusToggle = false) => {
        if (!isOpen()) return;
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        if (focusToggle) toggle.focus?.({ preventScroll: true });
    };

    const open = () => {
        build();
        if (search.value !== '') {
            search.value = '';
            renderResults();
        }
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        search.focus?.({ preventScroll: true });
    };

    const choose = (emoji) => {
        const max = Number(input.maxLength);
        const next = insertText(input.value, input.selectionStart, input.selectionEnd, emoji);
        if (max > 0 && next.value.length > max) {
            close();
            input.focus?.();
            return false;
        }

        input.value = next.value;
        input.setSelectionRange?.(next.caret, next.caret);
        input.dispatchEvent(typeof Event === 'function' ? new Event('input', { bubbles: true }) : { type: 'input' });

        recent = addRecent(recent, emoji);
        writeRecent(storage, recent);
        if (search) renderResults();

        close();
        input.focus?.();
        return true;
    };

    const buttons = () => [...panel.querySelectorAll('[data-emoji]')];

    const move = (from, key) => {
        const all = buttons();
        if (all.length === 0) return;
        const index = all.indexOf(from);
        const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -COLUMNS, ArrowDown: COLUMNS }[key];
        const target = index === -1 ? 0 : Math.min(all.length - 1, Math.max(0, index + step));
        all[target].focus?.();
        return all[target];
    };

    toggle.hidden = false;
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-haspopup', 'dialog');

    toggle.addEventListener('click', (event) => {
        event.preventDefault?.();
        if (isOpen()) close();
        else open();
    });

    panel.addEventListener('click', (event) => {
        const button = event.target?.closest?.('[data-emoji]');
        if (button && panel.contains(button)) choose(button.dataset.emoji);
    });

    panel.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation?.();
            close(true);
            return;
        }

        const onSearch = event.target === search;
        if (event.key === 'Enter' && onSearch) {
            // Not the composer form's Enter: pick the first result instead.
            event.preventDefault();
            const first = buttons()[0];
            if (first && search.value.trim() !== '') choose(first.dataset.emoji);
            return;
        }

        if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
        if (onSearch && event.key !== 'ArrowDown') return;
        event.preventDefault();
        move(onSearch ? null : event.target, event.key);
    });

    doc.addEventListener('click', (event) => {
        if (isOpen() && !panel.contains(event.target) && !toggle.contains(event.target)) close();
    });

    return { open, close, choose, isOpen };
}
