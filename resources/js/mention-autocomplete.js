/**
 * Mention autocomplete in the composer (ISSUE-33).
 *
 * Typing "@" and a few characters in a channel's composer lists who that
 * could be, from data-mentions-url (App\Http\Controllers\MentionSuggestionController):
 * members ("@username"), roles ("@Role Name") and, for members with
 * Mention @everyone, "@everyone". ↑ / ↓ move, Enter or Tab inserts the
 * highlighted one, Escape closes the list; a click inserts too.
 *
 * While the list is open those keys stop at this script, so Enter does
 * not send the message (channel-messages.js sets this up before its own
 * Enter handler). The text the server reads is the same either way:
 * typing "@bob" by hand mentions bob just as well.
 *
 * initMentionAutocomplete() takes its fetch and timer functions as
 * options so tests/js/mention-autocomplete.test.js can drive it.
 */

export const FETCH_DELAY_MS = 120;

/** Longest text after "@" that is still looked up (MentionSuggestionController::MAX_QUERY_LENGTH). */
export const MAX_QUERY_LENGTH = 100;

/**
 * The "@query" being typed right before the caret, or null: { start, query }
 * where start is the index of the "@". Like App\Support\Mentions, an "@"
 * inside a word or an e-mail address ("me@bob") does not start one.
 */
export function findMentionQuery(text, caret) {
    if (typeof text !== 'string') return null;

    const before = text.slice(0, caret ?? text.length);
    const match = /(?:^|[^a-z0-9_.@])@([^\s@]*)$/iu.exec(before);
    if (!match || match[1].length > MAX_QUERY_LENGTH) return null;

    return { start: before.length - match[1].length - 1, query: match[1] };
}

/**
 * The suggestions of a MentionSuggestionController response, in the order
 * shown: { key, type, label, detail, insert, avatarUrl, color }.
 */
export function flattenSuggestions(data) {
    const users = (data?.users ?? []).map((user) => ({
        key: `user-${user.id}`,
        type: 'user',
        label: user.name || user.username,
        detail: `@${user.username}`,
        insert: `@${user.username}`,
        avatarUrl: user.avatar_url ?? null,
        color: null,
    }));
    const roles = (data?.roles ?? []).map((role) => ({
        key: `role-${role.id}`,
        type: 'role',
        label: `@${role.name}`,
        detail: 'Role',
        insert: `@${role.name}`,
        avatarUrl: null,
        color: role.color ?? null,
    }));
    const everyone = data?.everyone === true
        ? [{
            key: 'everyone',
            type: 'everyone',
            label: '@everyone',
            detail: 'Everyone who can see this channel',
            insert: '@everyone',
            avatarUrl: null,
            color: null,
        }]
        : [];

    return [...users, ...roles, ...everyone];
}

/**
 * Put `insert` in place of the "@query" at mention.start..caret, followed
 * by one space: { value, caret } with the caret after that space.
 */
export function applySuggestion(text, mention, caret, insert) {
    const after = text.slice(caret);
    const spaced = after.startsWith(' ') ? after.slice(1) : after;
    const head = `${text.slice(0, mention.start)}${insert} `;

    return { value: head + spaced, caret: head.length };
}

const COLOR = /^#[0-9a-f]{6}$/i;

function renderOption(suggestion, id, doc) {
    const option = doc.createElement('li');
    option.id = id;
    option.setAttribute('role', 'option');
    option.setAttribute('aria-selected', 'false');
    option.className = 'flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm text-[#dbdee1] aria-selected:bg-[#404249] aria-selected:text-white';
    option.dataset.mentionOption = '';
    option.dataset.mentionType = suggestion.type;

    if (suggestion.avatarUrl) {
        const avatar = doc.createElement('img');
        avatar.src = suggestion.avatarUrl;
        avatar.alt = '';
        avatar.width = 20;
        avatar.height = 20;
        avatar.className = 'h-5 w-5 shrink-0 rounded-full object-cover';
        option.appendChild(avatar);
    } else {
        const swatch = doc.createElement('span');
        swatch.setAttribute('aria-hidden', 'true');
        swatch.className = 'flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#4e5058] text-xs';
        swatch.textContent = '@';
        // Validated like Role::displayColor(), since it goes in a style.
        if (suggestion.color && COLOR.test(suggestion.color)) swatch.style.backgroundColor = suggestion.color;
        option.appendChild(swatch);
    }

    const label = doc.createElement('span');
    label.className = 'truncate font-medium';
    label.textContent = suggestion.label;
    label.dataset.mentionLabel = '';
    option.appendChild(label);

    const detail = doc.createElement('span');
    detail.className = 'ml-auto shrink-0 truncate text-xs text-[#949ba4]';
    detail.textContent = suggestion.detail;
    option.appendChild(detail);

    return option;
}

/**
 * options: { input, box, url, fetchSuggestions(url, query) => Promise<data>,
 * document, setTimeout, clearTimeout }. Returns a handle for tests, or null
 * without an input, a list box or a URL.
 */
export function initMentionAutocomplete({
    input,
    box,
    url,
    fetchSuggestions,
    document: doc = globalThis.document,
    setTimeout: schedule = globalThis.setTimeout,
    clearTimeout: cancel = globalThis.clearTimeout,
}) {
    if (!input || !box || !url || typeof fetchSuggestions !== 'function') return null;

    const listId = box.id || 'mention-suggestions';
    box.id = listId;
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', listId);
    input.setAttribute('aria-expanded', 'false');

    const cache = new Map();
    let mention = null;
    let items = [];
    let active = 0;
    let timer = null;
    let request = 0;

    const isOpen = () => !box.hidden && items.length > 0;

    const hide = () => {
        items = [];
        box.hidden = true;
        box.replaceChildren();
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    };

    const close = () => {
        if (timer !== null) cancel(timer);
        timer = null;
        request += 1;
        mention = null;
        hide();
    };

    const highlight = (index) => {
        active = (index + items.length) % items.length;
        [...box.children].forEach((option, i) => option.setAttribute('aria-selected', i === active ? 'true' : 'false'));
        const current = box.children[active];
        if (current) {
            input.setAttribute('aria-activedescendant', current.id);
            current.scrollIntoView?.({ block: 'nearest' });
        }
    };

    const show = (suggestions) => {
        if (suggestions.length === 0) {
            hide();
            return;
        }
        items = suggestions;
        box.replaceChildren(...items.map((suggestion, i) => renderOption(suggestion, `${listId}-${i}`, doc)));
        box.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        highlight(0);
    };

    const choose = (index = active) => {
        const suggestion = items[index];
        if (!suggestion || !mention) return false;

        const caret = input.selectionStart ?? input.value.length;
        const next = applySuggestion(input.value, mention, caret, suggestion.insert);
        close();
        input.value = next.value;
        input.setSelectionRange?.(next.caret, next.caret);
        input.focus?.();
        // Lets the composer resize and the typing indicator notice.
        if (typeof Event === 'function') input.dispatchEvent?.(new Event('input', { bubbles: true }));

        return true;
    };

    const lookup = (query) => {
        const key = query.toLowerCase();
        if (cache.has(key)) return Promise.resolve(cache.get(key));

        return Promise.resolve(fetchSuggestions(url, query)).then((data) => {
            cache.set(key, data);
            return data;
        });
    };

    /** Looks at the text before the caret again; resolves once shown. */
    const update = () => {
        const found = findMentionQuery(input.value, input.selectionStart ?? input.value.length);
        if (!found) {
            close();
            return Promise.resolve();
        }

        mention = found;
        if (timer !== null) cancel(timer);
        const ticket = ++request;

        return new Promise((resolve) => {
            timer = schedule(() => {
                timer = null;
                lookup(found.query)
                    // A failed lookup only means no list; typing still works.
                    .catch(() => null)
                    .then((data) => {
                        if (ticket === request && data) show(flattenSuggestions(data));
                        resolve();
                    });
            }, FETCH_DELAY_MS);
        });
    };

    input.addEventListener('input', update);
    input.addEventListener('blur', close);

    input.addEventListener('keydown', (event) => {
        if (!isOpen() || event.isComposing) return;

        let handled = true;
        if (event.key === 'ArrowDown') highlight(active + 1);
        else if (event.key === 'ArrowUp') highlight(active - 1);
        else if ((event.key === 'Enter' && !event.shiftKey) || event.key === 'Tab') handled = choose();
        else if (event.key === 'Escape') close();
        else handled = false;

        if (handled) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    });

    // mousedown, not click: the textarea keeps the focus (no blur first).
    box.addEventListener('mousedown', (event) => {
        const option = event.target?.closest?.('[data-mention-option]');
        if (!option) return;
        event.preventDefault();
        choose([...box.children].indexOf(option));
    });

    return {
        update,
        choose,
        close,
        state: () => ({ open: isOpen(), items, active, mention }),
    };
}
