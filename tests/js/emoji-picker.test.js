// The composer's emoji picker (resources/js/emoji-picker.js).
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

// --- edge cases ------------------------------------------------------------------

test('choosing an emoji replaces the selected text', () => {
    const { toggle, panel, input, click, clickInPanel } = setup({ value: 'I am sad today' });
    input.selectionStart = 5;
    input.selectionEnd = 8;
    click(toggle);
    clickInPanel(buttons(panel).find((button) => button.dataset.emoji === '😀'));
    assert.equal(input.value, 'I am 😀 today');
    assert.equal(input.selectionStart, 7);
    assert.equal(input.selectionEnd, 7);
});

test('an emoji that exactly fills maxlength is inserted', () => {
    // 👍 is two UTF-16 units, which is what a textarea's maxlength counts.
    const { input, inputs, picker } = setup({ value: 'ab', maxLength: 4 });
    assert.equal(picker.choose('👍'), true);
    assert.equal(input.value, 'ab👍');
    assert.equal(inputs.length, 1);
});

test('no maxlength (the browser default of -1) means no limit', () => {
    const { input, picker } = setup({ value: 'x'.repeat(5000), maxLength: -1 });
    assert.equal(picker.choose('🎉'), true);
    assert.equal(input.value.length, 5002);
});

test('emoji with variation selectors and joiners are inserted whole', () => {
    const { input, picker } = setup({ value: 'ab' });
    input.selectionStart = 1;
    input.selectionEnd = 1;
    picker.choose('❤️');
    assert.equal(input.value, 'a❤️b');
    assert.equal(input.selectionStart, 1 + '❤️'.length);
    const all = EMOJI_CATEGORIES.flatMap((category) => category.emoji).map(([emoji]) => emoji);
    const joined = all.find((emoji) => emoji.includes('‍'));
    if (joined) {
        picker.choose(joined);
        assert.ok(input.value.includes(joined));
    }
});

test('choosing gives the focus back to the composer', () => {
    const { toggle, input, picker, click } = setup();
    let focused = 0;
    input.focus = () => { focused += 1; };
    click(toggle);
    picker.choose('👍');
    assert.equal(focused, 1);
});

test('an emoji refused for maxlength is not remembered', () => {
    const storage = fakeStorage();
    const { picker } = setup({ value: 'abc', maxLength: 3, storage });
    assert.equal(picker.choose('👍'), false);
    assert.equal(storage.data[RECENT_KEY], undefined);
});

test('the recent row shows at most MAX_RECENT emoji, newest first', () => {
    const storage = fakeStorage();
    const { toggle, panel, picker, click } = setup({ storage, maxLength: -1 });
    const all = EMOJI_CATEGORIES.flatMap((category) => category.emoji).map(([emoji]) => emoji);
    const used = all.slice(0, MAX_RECENT + 4);
    used.forEach((emoji) => picker.choose(emoji));

    click(toggle);
    const recent = panel.querySelector('[data-emoji-section]');
    assert.equal(recent.dataset.emojiSection, 'recent');
    const shown = recent.querySelectorAll('[data-emoji]').map((button) => button.dataset.emoji);
    assert.equal(shown.length, MAX_RECENT);
    assert.deepEqual(shown, used.slice(-MAX_RECENT).reverse());
    assert.equal(JSON.parse(storage.data[RECENT_KEY]).length, MAX_RECENT);
});

test('the recent row is updated while the panel stays built', () => {
    const { toggle, panel, picker, click } = setup();
    click(toggle);
    assert.notEqual(sections(panel)[0], 'recent');
    picker.choose('🍕');
    click(toggle);
    assert.equal(sections(panel)[0], 'recent');
    assert.equal(panel.querySelector('[data-emoji-section]').querySelectorAll('[data-emoji]')[0].dataset.emoji, '🍕');
});

test('a saved recent list longer than the maximum is cut', () => {
    const all = EMOJI_CATEGORIES.flatMap((category) => category.emoji).map(([emoji]) => emoji);
    const saved = all.slice(0, MAX_RECENT + 5);
    assert.deepEqual(readRecent(fakeStorage({ [RECENT_KEY]: JSON.stringify(saved) })), saved.slice(0, MAX_RECENT));
});

test('search matches words in any order, across hyphens, and finds each emoji once', () => {
    const found = searchEmoji('face');
    assert.ok(found.length > 1);
    assert.equal(new Set(found.map(([emoji]) => emoji)).size, found.length);
    const twice = [{ id: 'a', emoji: [['👍', 'thumbs up']] }, { id: 'b', emoji: [['👍', 'thumbs up']] }];
    assert.deepEqual(searchEmoji('thumbs', twice), [['👍', 'thumbs up']]);
    assert.deepEqual(searchEmoji('up thumbs'), searchEmoji('thumbs up'));
    const hyphen = [{ id: 'c', emoji: [['🤜', 'right-facing fist']] }];
    assert.deepEqual(searchEmoji('facing', hyphen), [['🤜', 'right-facing fist']]);
    assert.deepEqual(searchEmoji('ight', hyphen), []);
});

test('Enter in a whitespace-only search does not insert anything', () => {
    const { toggle, panel, input, click, keyInPanel } = setup();
    click(toggle);
    const box = search(panel);
    box.value = '   ';
    box.dispatchEvent({ type: 'input' });
    const event = keyInPanel(box, 'Enter');
    assert.equal(event.defaultPrevented, true);
    assert.equal(input.value, '');
    assert.equal(panel.hidden, false);
});

test('Enter on a search with no results does nothing', () => {
    const { toggle, panel, input, click, keyInPanel } = setup();
    click(toggle);
    const box = search(panel);
    box.value = 'qwertyuiop';
    box.dispatchEvent({ type: 'input' });
    keyInPanel(box, 'Enter');
    assert.equal(input.value, '');
    assert.equal(panel.hidden, false);
});

test('ArrowDown with no results does not throw', () => {
    const { toggle, panel, click, keyInPanel } = setup();
    click(toggle);
    const box = search(panel);
    box.value = 'qwertyuiop';
    box.dispatchEvent({ type: 'input' });
    const event = keyInPanel(box, 'ArrowDown');
    assert.equal(event.defaultPrevented, true);
});

test('other keys in the panel are left alone', () => {
    const { toggle, panel, click, keyInPanel } = setup();
    click(toggle);
    const first = buttons(panel)[0];
    ['a', 'Tab', ' ', 'Home'].forEach((key) => {
        assert.equal(keyInPanel(first, key).defaultPrevented, false, key);
    });
    assert.equal(panel.hidden, false);
});

test('Enter on an emoji button is left to the button (a click)', () => {
    const { toggle, panel, input, click, keyInPanel } = setup();
    click(toggle);
    const event = keyInPanel(buttons(panel)[0], 'Enter');
    assert.equal(event.defaultPrevented, false);
    assert.equal(input.value, '');
});

test('closing with the toggle does not move the focus to it', () => {
    const { toggle, click } = setup();
    let focused = 0;
    toggle.focus = () => { focused += 1; };
    click(toggle);
    click(toggle);
    assert.equal(focused, 0);
});

test('opening focuses the search box', () => {
    const { toggle, panel, click } = setup();
    click(toggle);
    let focused = 0;
    search(panel).focus = () => { focused += 1; };
    click(toggle);
    click(toggle);
    assert.equal(focused, 1);
});

test('a click outside while closed and close() while closed change nothing', () => {
    const { toggle, panel, outside, doc, picker } = setup();
    doc.dispatch('click', { target: outside });
    picker.close(true);
    assert.equal(panel.hidden, true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(picker.isOpen(), false);
});

test('the picker is only built once however often it opens', () => {
    const { toggle, panel, click } = setup();
    click(toggle);
    const box = search(panel);
    click(toggle);
    click(toggle);
    assert.equal(search(panel), box);
    assert.equal(panel.querySelectorAll('[data-emoji-search]').length, 1);
});

test('two composers get independent pickers', () => {
    const storage = fakeStorage();
    const a = setup({ storage });
    const b = setup({ storage });
    a.click(a.toggle);
    assert.equal(a.panel.hidden, false);
    assert.equal(b.panel.hidden, true);
    a.picker.choose('🚀');
    assert.equal(a.input.value, '🚀');
    assert.equal(b.input.value, '');
});

test('emoji names are used as labels and hover titles', () => {
    const { toggle, panel, click } = setup();
    click(toggle);
    const names = new Map(EMOJI_CATEGORIES.flatMap((category) => category.emoji));
    buttons(panel).forEach((button) => {
        assert.equal(button.getAttribute('aria-label'), names.get(button.dataset.emoji));
        assert.equal(button.title, names.get(button.dataset.emoji));
    });
});

test('emoji buttons hold the emoji as text', () => {
    const { toggle, panel, click } = setup();
    click(toggle);
    // fake-dom's innerHTML throws, so building the panel also proves it is not used.
    assert.ok(buttons(panel).every((button) => button.textContent === button.dataset.emoji));
});
