// ISSUE-36: the composer's emoji picker (resources/js/emoji-picker.js).
// Run with `npm test`. The markup is covered by MessageMarkdownTest.php;
// the picker runs here against the shared fake DOM and a fake storage.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { fakeDocument, keyEvent, make, textOf } from './helpers/fake-dom.js';
import { EMOJI_CATEGORIES } from '../../resources/js/emoji-data.js';
import {
    addRecent,
    COLUMNS,
    initEmojiPicker,
    insertText,
    MAX_RECENT,
    readRecent,
    RECENT_KEY,
    searchEmoji,
} from '../../resources/js/emoji-picker.js';

function fakeStorage(initial = {}) {
    const data = { ...initial };
    return {
        data,
        getItem: (key) => (key in data ? data[key] : null),
        setItem: (key, value) => { data[key] = String(value); },
    };
}

function setup({ value = '', storage = fakeStorage(), maxLength = 2000 } = {}) {
    const toggle = make('button', { emojiPickerToggle: '' });
    toggle.hidden = true;
    const panel = make('div', { emojiPicker: '' });
    panel.hidden = true;
    const input = make('textarea', { messageInput: '' });
    input.value = value;
    input.maxLength = maxLength;
    input.selectionStart = value.length;
    input.selectionEnd = value.length;
    input.setSelectionRange = (start, end) => { input.selectionStart = start; input.selectionEnd = end; };
    const inputs = [];
    input.addEventListener('input', (event) => inputs.push(event));
    const outside = make('div');
    const doc = fakeDocument(make('body', {}, [toggle, panel, input, outside]));
    const picker = initEmojiPicker({ toggle, panel, input, storage, doc });
    const click = (target) => target.dispatchEvent({ type: 'click', target, preventDefault() {} });
    const clickInPanel = (target) => panel.dispatchEvent({ type: 'click', target });
    const keyInPanel = (target, key) => {
        const event = keyEvent(key, { target, stopPropagation() {} });
        panel.dispatchEvent(event);
        return event;
    };
    return { toggle, panel, input, inputs, outside, doc, picker, storage, click, clickInPanel, keyInPanel };
}

const search = (panel) => panel.querySelector('[data-emoji-search]');
const buttons = (panel) => panel.querySelectorAll('[data-emoji]');
const sections = (panel) => panel.querySelectorAll('[data-emoji-section]').map((section) => section.dataset.emojiSection);

// --- data ----------------------------------------------------------------------

test('every emoji has a name and appears once', () => {
    const all = EMOJI_CATEGORIES.flatMap((category) => category.emoji);
    assert.ok(all.length > 300);
    all.forEach(([emoji, name]) => {
        assert.ok(emoji.length > 0);
        assert.match(name, /^[a-z0-9 -]+$/);
    });
    assert.equal(new Set(all.map(([emoji]) => emoji)).size, all.length);
    EMOJI_CATEGORIES.forEach((category) => assert.ok(category.label && category.icon && category.emoji.length > 0));
});

// --- helpers -------------------------------------------------------------------

test('searchEmoji matches the start of every word, case-insensitively', () => {
    assert.deepEqual(searchEmoji('thumbs up'), [['👍', 'thumbs up']]);
    assert.deepEqual(searchEmoji('THUMB').map(([emoji]) => emoji), ['👍', '👎']);
    assert.ok(searchEmoji('heart').some(([emoji]) => emoji === '❤️'));
    assert.ok(searchEmoji('mail').some(([emoji]) => emoji === '📧'));
    assert.deepEqual(searchEmoji('art'), searchEmoji('artist'));
    assert.deepEqual(searchEmoji(''), []);
    assert.deepEqual(searchEmoji('   '), []);
    assert.deepEqual(searchEmoji(null), []);
    assert.deepEqual(searchEmoji('zzzz-nothing'), []);
});

test('insertText replaces the selection and puts the cursor after the text', () => {
    assert.deepEqual(insertText('hello world', 5, 5, '🎉'), { value: 'hello🎉 world', caret: 7 });
    assert.deepEqual(insertText('hello world', 6, 11, '👋'), { value: 'hello 👋', caret: 8 });
    assert.deepEqual(insertText('', 0, 0, '👍'), { value: '👍', caret: 2 });
    assert.deepEqual(insertText('abc', undefined, undefined, '!'), { value: 'abc!', caret: 4 });
    assert.deepEqual(insertText('abc', 99, 99, '!'), { value: 'abc!', caret: 4 });
    assert.deepEqual(insertText('abc', 2, 1, '!'), { value: 'ab!c', caret: 3 });
});

test('addRecent puts the emoji first without repeats and keeps at most the maximum', () => {
    assert.deepEqual(addRecent(['👍', '🎉'], '🎉'), ['🎉', '👍']);
    assert.deepEqual(addRecent([], '👍'), ['👍']);
    const many = Array.from({ length: MAX_RECENT }, (_, i) => String(i));
    assert.equal(addRecent(many, '👍').length, MAX_RECENT);
    assert.equal(addRecent(many, '👍')[0], '👍');
});

test('readRecent ignores broken, foreign and unknown values', () => {
    assert.deepEqual(readRecent(fakeStorage({ [RECENT_KEY]: '["👍","<b>","🎉",3]' })), ['👍', '🎉']);
    assert.deepEqual(readRecent(fakeStorage({ [RECENT_KEY]: 'not json' })), []);
    assert.deepEqual(readRecent(fakeStorage({ [RECENT_KEY]: '{"a":1}' })), []);
    assert.deepEqual(readRecent(fakeStorage()), []);
    assert.deepEqual(readRecent(null), []);
    assert.deepEqual(readRecent({ getItem() { throw new Error('blocked'); } }), []);
});

// --- the picker ----------------------------------------------------------------

test('initEmojiPicker needs a toggle, a panel and an input', () => {
    assert.equal(initEmojiPicker({}), null);
    assert.equal(initEmojiPicker({ toggle: make('button'), panel: make('div'), doc: fakeDocument() }), null);
});

test('the toggle is shown and the panel built only when first opened', () => {
    const { toggle, panel, click } = setup();
    assert.equal(toggle.hidden, false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(toggle.getAttribute('aria-haspopup'), 'dialog');
    assert.equal(panel.children.length, 0);

    click(toggle);
    assert.equal(panel.hidden, false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    assert.ok(search(panel));
    assert.equal(search(panel).getAttribute('aria-label'), 'Search emoji');
    assert.deepEqual(sections(panel), EMOJI_CATEGORIES.map((category) => category.id));
    const first = buttons(panel)[0];
    assert.equal(first.getAttribute('aria-label'), EMOJI_CATEGORIES[0].emoji[0][1]);
    assert.equal(first.type, 'button');

    click(toggle);
    assert.equal(panel.hidden, true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
});

test('choosing an emoji inserts it at the cursor, fires input and closes', () => {
    const { toggle, panel, input, inputs, click, clickInPanel } = setup({ value: 'hi there' });
    input.selectionStart = 2;
    input.selectionEnd = 2;
    click(toggle);
    const party = buttons(panel).find((button) => button.dataset.emoji === '🎉');
    clickInPanel(party);

    assert.equal(input.value, 'hi🎉 there');
    assert.equal(input.selectionStart, 4);
    assert.equal(inputs.length, 1);
    assert.equal(inputs[0].type, 'input');
    assert.equal(panel.hidden, true);
});

test('a click on the panel outside an emoji does nothing', () => {
    const { toggle, panel, input, click, clickInPanel } = setup();
    click(toggle);
    clickInPanel(search(panel));
    assert.equal(input.value, '');
    assert.equal(panel.hidden, false);
});

test('an emoji that would go past maxlength is not inserted', () => {
    const { toggle, panel, input, inputs, picker, click } = setup({ value: 'abc', maxLength: 4 });
    click(toggle);
    assert.equal(picker.choose('👍'), false);
    assert.equal(input.value, 'abc');
    assert.equal(inputs.length, 0);
    assert.equal(panel.hidden, true);
});

test('used emoji are remembered and shown first', () => {
    const storage = fakeStorage();
    const first = setup({ storage });
    first.click(first.toggle);
    first.picker.choose('🔥');
    first.picker.choose('👍');
    first.picker.choose('🔥');
    assert.deepEqual(JSON.parse(storage.data[RECENT_KEY]), ['🔥', '👍']);

    const later = setup({ storage });
    later.click(later.toggle);
    assert.equal(sections(later.panel)[0], 'recent');
    const recent = later.panel.querySelector('[data-emoji-section]');
    assert.deepEqual(recent.querySelectorAll('[data-emoji]').map((button) => button.dataset.emoji), ['🔥', '👍']);
    assert.equal(recent.querySelectorAll('[data-emoji]')[0].getAttribute('aria-label'), 'fire');
});

test('a storage that throws only loses the saved recent row', () => {
    const storage = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); } };
    const { toggle, panel, input, picker, click } = setup({ storage });
    click(toggle);
    assert.equal(picker.choose('👍'), true);
    assert.equal(input.value, '👍');
    click(toggle);
    assert.equal(sections(panel)[0], 'recent');
});

test('searching filters the emoji and says when nothing matches', () => {
    const { toggle, panel, click } = setup();
    click(toggle);
    const box = search(panel);

    box.value = 'pizza';
    box.dispatchEvent({ type: 'input' });
    assert.deepEqual(sections(panel), ['search']);
    assert.deepEqual(buttons(panel).map((button) => button.dataset.emoji), ['🍕']);

    box.value = 'qwertyuiop';
    box.dispatchEvent({ type: 'input' });
    assert.equal(buttons(panel).length, 0);
    assert.equal(textOf(panel.querySelector('[data-emoji-empty]')), 'No emoji match that search.');

    box.value = '';
    box.dispatchEvent({ type: 'input' });
    assert.equal(sections(panel).length, EMOJI_CATEGORIES.length);
});

test('Enter in the search box picks the first result instead of sending', () => {
    const { toggle, panel, input, click, keyInPanel } = setup();
    click(toggle);
    const box = search(panel);

    const empty = keyInPanel(box, 'Enter');
    assert.equal(empty.defaultPrevented, true);
    assert.equal(input.value, '');
    assert.equal(panel.hidden, false);

    box.value = 'rocket';
    box.dispatchEvent({ type: 'input' });
    const picked = keyInPanel(box, 'Enter');
    assert.equal(picked.defaultPrevented, true);
    assert.equal(input.value, '🚀');
    assert.equal(panel.hidden, true);
});

test('reopening clears the last search', () => {
    const { toggle, panel, click } = setup();
    click(toggle);
    search(panel).value = 'pizza';
    search(panel).dispatchEvent({ type: 'input' });
    click(toggle);
    click(toggle);
    assert.equal(search(panel).value, '');
    assert.equal(sections(panel).length, EMOJI_CATEGORIES.length);
});

test('Escape closes and gives the focus back to the toggle', () => {
    const { toggle, panel, click, keyInPanel } = setup();
    let focused = 0;
    toggle.focus = () => { focused += 1; };
    click(toggle);
    const event = keyInPanel(search(panel), 'Escape');
    assert.equal(event.defaultPrevented, true);
    assert.equal(panel.hidden, true);
    assert.equal(focused, 1);
});

test('the arrow keys move between emoji by one and by a row', () => {
    const { toggle, panel, click, keyInPanel } = setup();
    click(toggle);
    const all = buttons(panel);
    let focused = null;
    all.forEach((button) => { button.focus = () => { focused = button; }; });

    keyInPanel(search(panel), 'ArrowDown');
    assert.equal(focused, all[0]);
    keyInPanel(all[0], 'ArrowRight');
    assert.equal(focused, all[1]);
    keyInPanel(all[1], 'ArrowDown');
    assert.equal(focused, all[1 + COLUMNS]);
    keyInPanel(all[1 + COLUMNS], 'ArrowUp');
    assert.equal(focused, all[1]);
    keyInPanel(all[0], 'ArrowLeft');
    assert.equal(focused, all[0]);
    keyInPanel(all[all.length - 1], 'ArrowDown');
    assert.equal(focused, all[all.length - 1]);

    focused = null;
    const ignored = keyInPanel(search(panel), 'ArrowLeft');
    assert.equal(focused, null);
    assert.equal(ignored.defaultPrevented, false);
});

test('a click outside closes the picker, a click on the toggle or panel does not', () => {
    const { toggle, panel, outside, doc, click } = setup();
    click(toggle);
    doc.dispatch('click', { target: toggle });
    assert.equal(panel.hidden, false);
    doc.dispatch('click', { target: search(panel) });
    assert.equal(panel.hidden, false);
    doc.dispatch('click', { target: outside });
    assert.equal(panel.hidden, true);
});

test('emoji buttons hold the emoji as text', () => {
    const { toggle, panel, click } = setup();
    click(toggle);
    // fake-dom's innerHTML throws, so building the panel also proves it is not used.
    assert.ok(buttons(panel).every((button) => button.textContent === button.dataset.emoji));
});
