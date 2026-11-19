// ISSUE-33: mention autocomplete in the composer. Run with `npm test`
// (node --test, no deps). The suggestions endpoint is covered by
// tests/Feature/Notifications/MentionSuggestionsTest.php; the script is
// checked here against a fake DOM, a fake fetch and fake timers.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    FETCH_DELAY_MS,
    applySuggestion,
    findMentionQuery,
    flattenSuggestions,
    initMentionAutocomplete,
} from '../../resources/js/mention-autocomplete.js';
import { fakeDocument, keyEvent, make, textOf } from './helpers/fake-dom.js';

const RESPONSE = {
    users: [
        { id: 2, username: 'bob', name: 'Bob <b>', avatar_url: 'data:image/png;base64,x' },
        { id: 3, username: 'bobcat', name: '', avatar_url: null },
    ],
    roles: [{ id: 9, name: 'Board Games', color: '#ff0000' }, { id: 10, name: 'Bots', color: 'red;background:url(x)' }],
    everyone: false,
};

function setup({ response = RESPONSE, fail = false } = {}) {
    const doc = fakeDocument();
    const input = make('textarea');
    input.value = '';
    input.selectionStart = 0;
    input.focused = false;
    input.setSelectionRange = (start) => { input.selectionStart = start; };
    input.focus = () => { input.focused = true; };
    const box = make('ul', { mentionSuggestions: '' });
    box.hidden = true;
    box.id = 'mention-suggestions';

    const timers = [];
    const calls = [];
    const handle = initMentionAutocomplete({
        input,
        box,
        url: '/servers/1/channels/2/mentions',
        fetchSuggestions: (url, query) => {
            calls.push([url, query]);
            return fail ? Promise.reject(new Error('down')) : Promise.resolve(response);
        },
        document: doc,
        setTimeout: (fn, ms) => { timers.push({ fn, ms }); return timers.length; },
        clearTimeout: () => {},
    });

    // Types text (caret at the end) and lets the debounce run.
    const type = async (text) => {
        input.value = text;
        input.selectionStart = text.length;
        const done = handle.update();
        timers.splice(0).forEach((timer) => timer.fn());
        await done;
    };

    const press = (key, extra) => {
        const event = keyEvent(key, extra);
        input.listeners.keydown.forEach((handler) => handler(event));
        return event;
    };

    return { input, box, handle, type, press, calls, timers };
}

// --- findMentionQuery -----------------------------------------------------------

test('findMentionQuery finds the @query right before the caret', () => {
    assert.deepEqual(findMentionQuery('@', 1), { start: 0, query: '' });
    assert.deepEqual(findMentionQuery('hi @bo', 6), { start: 3, query: 'bo' });
    assert.deepEqual(findMentionQuery('(@bob', 5), { start: 1, query: 'bob' });
    assert.deepEqual(findMentionQuery('hi @bo there', 6), { start: 3, query: 'bo' });
});

test('findMentionQuery ignores e-mail addresses, double @ and finished words', () => {
    assert.equal(findMentionQuery('mail me@bob', 11), null);
    assert.equal(findMentionQuery('@@bob', 5), null);
    assert.equal(findMentionQuery('@bob ', 5), null);
    assert.equal(findMentionQuery('no at sign', 10), null);
    assert.equal(findMentionQuery(null, 0), null);
    assert.equal(findMentionQuery(`@${'a'.repeat(101)}`, 102), null);
});

// --- flattenSuggestions / applySuggestion ------------------------------------------

test('flattenSuggestions lists users, then roles, then @everyone', () => {
    const items = flattenSuggestions({ ...RESPONSE, everyone: true });

    assert.deepEqual(items.map((item) => item.insert), ['@bob', '@bobcat', '@Board Games', '@Bots', '@everyone']);
    assert.equal(items[0].label, 'Bob <b>');
    assert.equal(items[1].label, 'bobcat');
    assert.equal(items[2].detail, 'Role');
    assert.deepEqual(flattenSuggestions(null), []);
});

test('applySuggestion replaces the query and adds one space', () => {
    assert.deepEqual(applySuggestion('hi @bo', { start: 3 }, 6, '@bob'), { value: 'hi @bob ', caret: 8 });
    assert.deepEqual(applySuggestion('hi @bo there', { start: 3 }, 6, '@bob'), { value: 'hi @bob there', caret: 8 });
    assert.deepEqual(applySuggestion('@g x', { start: 0 }, 2, '@Game Night'), { value: '@Game Night x', caret: 12 });
});

// --- initMentionAutocomplete ---------------------------------------------------------

test('without an input, list or url it does nothing', () => {
    assert.equal(initMentionAutocomplete({ input: null, box: make('ul'), url: '/x', fetchSuggestions: () => {} }), null);
    assert.equal(initMentionAutocomplete({ input: make('textarea'), box: null, url: '/x', fetchSuggestions: () => {} }), null);
    assert.equal(initMentionAutocomplete({ input: make('textarea'), box: make('ul'), url: null, fetchSuggestions: () => {} }), null);
});

test('typing @ and a query fetches after a short delay and shows the list', async () => {
    const { input, box, type, calls, timers, handle } = setup();

    input.value = '@bo';
    input.selectionStart = 3;
    const pending = handle.update();
    assert.equal(timers[0].ms, FETCH_DELAY_MS);
    assert.equal(calls.length, 0);
    timers.splice(0).forEach((timer) => timer.fn());
    await pending;

    assert.deepEqual(calls, [['/servers/1/channels/2/mentions', 'bo']]);
    assert.equal(box.hidden, false);
    assert.equal(box.children.length, 4);
    assert.equal(input.getAttribute('aria-expanded'), 'true');
    assert.equal(input.getAttribute('aria-controls'), 'mention-suggestions');
    assert.equal(input.getAttribute('aria-activedescendant'), 'mention-suggestions-0');
    assert.equal(box.children[0].getAttribute('aria-selected'), 'true');
    // Names are text, never markup.
    assert.equal(textOf(box.children[0].querySelector('[data-mention-label]')), 'Bob <b>');

    await type('hello');
    assert.equal(box.hidden, true);
    assert.equal(input.getAttribute('aria-expanded'), 'false');
});

test('role colours are only used when they are a #rrggbb value', async () => {
    const { box, type } = setup();
    await type('@b');

    const swatch = (option) => option.children[0];
    assert.equal(swatch(box.children[2]).style.backgroundColor, '#ff0000');
    assert.equal(swatch(box.children[3]).style.backgroundColor, undefined);
});

test('arrow keys move the highlight around, Enter inserts it and is not sent', async () => {
    const { input, box, type, press } = setup();
    await type('hi @bo');

    press('ArrowDown');
    assert.equal(box.children[1].getAttribute('aria-selected'), 'true');
    press('ArrowUp');
    press('ArrowUp');
    assert.equal(box.children[3].getAttribute('aria-selected'), 'true');
    press('ArrowDown');

    const enter = press('Enter');
    assert.equal(enter.defaultPrevented, true);
    assert.equal(enter.stopped, true);
    assert.equal(input.value, 'hi @bob ');
    assert.equal(input.selectionStart, 8);
    assert.equal(input.focused, true);
    assert.equal(box.hidden, true);
});

test('Tab inserts a role with its full name', async () => {
    const { input, type, press } = setup();
    await type('@bo');

    press('ArrowDown');
    press('ArrowDown');
    press('Tab');

    assert.equal(input.value, '@Board Games ');
});

test('Escape closes the list and stops there; closed, keys pass through', async () => {
    const { box, type, press } = setup();
    await type('@bo');

    const escape = press('Escape');
    assert.equal(escape.stopped, true);
    assert.equal(box.hidden, true);

    const enter = press('Enter');
    assert.equal(enter.defaultPrevented, false);
    assert.equal(enter.stopped, false);
});

test('Shift+Enter and composing text are left alone', async () => {
    const { type, press, input } = setup();
    await type('@bo');

    assert.equal(press('Enter', { shiftKey: true }).stopped, false);
    assert.equal(press('Enter', { isComposing: true }).stopped, false);
    assert.equal(input.value, '@bo');
});

test('clicking an option inserts it without losing focus', async () => {
    const { input, box, type } = setup();
    await type('@bo');

    const option = box.children[1];
    const event = { target: option.children[1], defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    box.listeners.mousedown.forEach((handler) => handler(event));

    assert.equal(event.defaultPrevented, true);
    assert.equal(input.value, '@bobcat ');
});

test('an empty answer or a failed lookup shows no list', async () => {
    const empty = setup({ response: { users: [], roles: [], everyone: false } });
    await empty.type('@zz');
    assert.equal(empty.box.hidden, true);
    assert.equal(empty.handle.state().open, false);

    const failing = setup({ fail: true });
    await failing.type('@bo');
    assert.equal(failing.box.hidden, true);
});

test('answers are cached per query', async () => {
    const { type, calls } = setup();
    await type('@bo');
    await type('@b');
    await type('@BO');

    assert.deepEqual(calls.map(([, query]) => query), ['bo', 'b']);
});

test('a lookup that comes back after the query was left is dropped', async () => {
    const { handle, timers, box, input } = setup();

    input.value = '@bo';
    input.selectionStart = 3;
    const pending = handle.update();
    input.value = 'plain';
    input.selectionStart = 5;
    handle.update();
    timers.splice(0).forEach((timer) => timer.fn());
    await pending;

    assert.equal(box.hidden, true);
});

test('blur closes the list', async () => {
    const { input, box, type } = setup();
    await type('@bo');

    input.listeners.blur.forEach((handler) => handler());

    assert.equal(box.hidden, true);
});
