// Alt+arrows switch channels and DMs, Alt+Shift+arrows go to
// unread channels (resources/js/keyboard-shortcuts.js). Run with
// `npm test`. The sidebar markup and help text are covered by
// tests/Feature/Layout/AccessibilityTest.php.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { FOCUS_KEY, initKeyboardShortcuts, navigationItems, targetFor } from '../../resources/js/keyboard-shortcuts.js';
import { fakeDocument, keyEvent, make } from './helpers/fake-dom.js';

function link(href, active = false) {
    const a = make('a');
    a.setAttribute('href', href);
    if (active) a.setAttribute('aria-current', 'page');
    return a;
}

/** A channel row as servers/partials/channel-item.blade.php renders it. */
function channel(name, { active = false, unread = false, hidden = false } = {}) {
    const row = make('li', { channel: '', channelId: name }, [make('span', { unreadDot: '' }), link(`/c/${name}`, active), make('form')]);
    if (unread) row.dataset.unread = '';
    row.hidden = hidden;
    return row;
}

function fakeStorage(initial = {}) {
    const data = { ...initial };
    return {
        data,
        getItem: (key) => (key in data ? data[key] : null),
        setItem: (key, value) => { data[key] = String(value); },
        removeItem: (key) => { delete data[key]; },
    };
}

function focusable(node) {
    node.focused = 0;
    node.focus = () => { node.focused += 1; };
    return node;
}

/** A server page: sidebar with rows, a composer and main. */
function page(rows, { manage = false, storage = fakeStorage() } = {}) {
    const sidebar = make('nav', { channelSidebar: '' }, [make('ul', { channelList: '' }, rows)]);
    if (manage) sidebar.dataset.layoutUrl = '/layout';
    const composer = focusable(make('textarea', { messageInput: '' }));
    composer.value = '';
    const main = focusable(make('main', { appMain: '' }, [composer]));
    const doc = fakeDocument(make('body', {}, [sidebar, main]));
    const visited = [];
    const api = initKeyboardShortcuts({ document: doc, navigate: (url) => visited.push(url), storage });
    const press = (key, extra = {}) => {
        const event = keyEvent(key, { altKey: true, ctrlKey: false, metaKey: false, target: main, ...extra });
        doc.dispatch('keydown', event);
        return event;
    };
    return { doc, sidebar, composer, main, visited, storage, api, press };
}

test('navigationItems reads the channel rows in order', () => {
    const { doc } = page([channel('a'), channel('b', { active: true, unread: false }), channel('c', { unread: true, hidden: true })]);
    const items = navigationItems(doc);
    assert.deepEqual(items.map((item) => item.link.getAttribute('href')), ['/c/a', '/c/b', '/c/c']);
    assert.deepEqual(items.map((item) => [item.active, item.unread, item.hidden]), [[false, false, false], [true, false, false], [false, true, true]]);
});

test('navigationItems falls back to the DM list, and skips rows without a link', () => {
    const dm = (id, active) => make('li', { dmId: id }, [link(`/dms/${id}`, active)]);
    const broken = make('li', { dmId: '9' }, [make('span')]);
    const doc = fakeDocument(make('body', {}, [make('section', { dmList: '' }, [make('ul', {}, [dm('1', false), dm('2', true), broken])])]));
    assert.deepEqual(navigationItems(doc).map((item) => item.link.getAttribute('href')), ['/dms/1', '/dms/2']);

    assert.deepEqual(navigationItems(fakeDocument(make('body'))), []);
});

test('targetFor steps from the active item and wraps around', () => {
    const items = [{ active: false }, { active: true }, { active: false }].map((item, i) => ({ ...item, id: i, unread: false, hidden: false }));
    assert.equal(targetFor(items, { up: false }).id, 2);
    assert.equal(targetFor(items, { up: true }).id, 0);

    items[1].active = false;
    items[2].active = true;
    assert.equal(targetFor(items, { up: false }).id, 0, 'wraps to the top');
    items[2].active = false;
    items[0].active = true;
    assert.equal(targetFor(items, { up: true }).id, 2, 'wraps to the bottom');
});

test('targetFor without an active item starts at the top or the bottom', () => {
    const items = [0, 1, 2].map((id) => ({ id, active: false, unread: false, hidden: false }));
    assert.equal(targetFor(items, { up: false }).id, 0);
    assert.equal(targetFor(items, { up: true }).id, 2);
});

test('targetFor skips hidden items, or finds unread ones anywhere', () => {
    const items = [
        { id: 0, active: true, unread: false, hidden: false },
        { id: 1, active: false, unread: false, hidden: true },
        { id: 2, active: false, unread: false, hidden: false },
        { id: 3, active: false, unread: true, hidden: true },
    ];
    assert.equal(targetFor(items, { up: false }).id, 2);
    assert.equal(targetFor(items, { up: true }).id, 2, 'wrapping past the hidden unread one');
    assert.equal(targetFor(items, { unread: true }).id, 3);
    assert.equal(targetFor(items, { unread: true, up: true }).id, 3);
});

test('targetFor gives null when there is nowhere to go', () => {
    assert.equal(targetFor([]), null);
    assert.equal(targetFor([{ active: true, unread: true, hidden: false }]), null, 'only the open one');
    assert.equal(targetFor([{ active: true }, { active: false, unread: false, hidden: false }], { unread: true }), null);
});

test('Alt+ArrowDown and Alt+ArrowUp open the next and previous channel', () => {
    const ctx = page([channel('a'), channel('b', { active: true }), channel('c')]);

    const down = ctx.press('ArrowDown');
    assert.deepEqual(ctx.visited, ['/c/c']);
    assert.equal(down.defaultPrevented, true);
    assert.equal(ctx.storage.data[FOCUS_KEY], '1', 'the next page focuses the composer');

    ctx.press('ArrowUp');
    assert.deepEqual(ctx.visited, ['/c/c', '/c/a']);
});

test('Alt+Shift+arrows go to unread channels, also in collapsed categories', () => {
    const ctx = page([channel('a', { unread: true }), channel('b', { active: true }), channel('c'), channel('d', { unread: true, hidden: true })]);
    ctx.press('ArrowDown', { shiftKey: true });
    ctx.press('ArrowUp', { shiftKey: true });
    assert.deepEqual(ctx.visited, ['/c/d', '/c/a']);
});

test('nothing unread: the keys are left alone', () => {
    const ctx = page([channel('a', { active: true }), channel('b')]);
    const event = ctx.press('ArrowDown', { shiftKey: true });
    assert.deepEqual(ctx.visited, []);
    assert.equal(event.defaultPrevented, false);
    assert.equal(ctx.storage.data[FOCUS_KEY], undefined);
});

test('other keys and modifiers are ignored', () => {
    const ctx = page([channel('a', { active: true }), channel('b')]);
    ctx.press('ArrowDown', { altKey: false });
    ctx.press('ArrowDown', { ctrlKey: true });
    ctx.press('ArrowDown', { metaKey: true });
    ctx.press('ArrowDown', { isComposing: true });
    ctx.press('ArrowLeft');
    ctx.press('j');
    assert.deepEqual(ctx.visited, []);
});

test('a key another handler already used is left alone', () => {
    const ctx = page([channel('a', { active: true }), channel('b')]);
    ctx.press('ArrowDown', { defaultPrevented: true });
    assert.deepEqual(ctx.visited, []);
});

test('a text field with a draft keeps it: no switch', () => {
    const ctx = page([channel('a', { active: true }), channel('b')]);
    ctx.composer.value = 'half a message';
    ctx.press('ArrowDown', { target: ctx.composer });
    assert.deepEqual(ctx.visited, []);

    const editable = { isContentEditable: true, textContent: 'draft' };
    ctx.press('ArrowDown', { target: editable });
    assert.deepEqual(ctx.visited, []);

    ctx.composer.value = '';
    ctx.press('ArrowDown', { target: ctx.composer });
    assert.deepEqual(ctx.visited, ['/c/b'], 'an empty composer is fine');
});

test('Alt+arrows on a channel or category in a sidebar you can reorder move it instead', () => {
    const rows = [channel('a', { active: true }), channel('b')];
    const toggle = make('button', { categoryToggle: '' });
    const category = make('section', { category: '' }, [toggle]);
    const ctx = page(rows, { manage: true });
    ctx.sidebar.appendChild(category);

    ctx.press('ArrowDown', { target: rows[0].children[1] });
    ctx.press('ArrowDown', { target: toggle });
    assert.deepEqual(ctx.visited, [], 'channel-sidebar.js reorders');

    ctx.press('ArrowDown', { target: rows[0].children[1], shiftKey: true });
    assert.deepEqual(ctx.visited, [], 'no unread channel');
    rows[1].dataset.unread = '';
    ctx.press('ArrowDown', { target: rows[0].children[1], shiftKey: true });
    assert.deepEqual(ctx.visited, ['/c/b'], 'Shift is not a reorder key');

    ctx.press('ArrowDown', { target: ctx.main });
    assert.deepEqual(ctx.visited, ['/c/b', '/c/b'], 'outside the sidebar it switches');
});

test('without reordering, a focused channel switches too', () => {
    const rows = [channel('a', { active: true }), channel('b')];
    const ctx = page(rows);
    ctx.press('ArrowDown', { target: rows[0].children[1] });
    assert.deepEqual(ctx.visited, ['/c/b']);
});

test('arriving with a shortcut focuses the composer once', () => {
    const storage = fakeStorage({ [FOCUS_KEY]: '1' });
    const ctx = page([channel('a', { active: true })], { storage });
    assert.equal(ctx.composer.focused, 1);
    assert.equal(storage.data[FOCUS_KEY], undefined, 'used up');

    const again = page([channel('a', { active: true })], { storage });
    assert.equal(again.composer.focused, 0);
});

test('arriving on a page without a composer focuses main', () => {
    const storage = fakeStorage({ [FOCUS_KEY]: '1' });
    const main = focusable(make('main', { appMain: '' }));
    initKeyboardShortcuts({ document: fakeDocument(make('body', {}, [main])), navigate: () => {}, storage });
    assert.equal(main.focused, 1);
});

// --- Further edge cases ---

/** A friends / DM page: a DM list and main, no channel sidebar. */
function dmPage(rows) {
    const main = focusable(make('main', { appMain: '' }));
    const doc = fakeDocument(make('body', {}, [make('section', { dmList: '' }, [make('ul', {}, rows)]), main]));
    const visited = [];
    initKeyboardShortcuts({ document: doc, navigate: (url) => visited.push(url), storage: fakeStorage() });
    const press = (key, extra = {}) => {
        const event = keyEvent(key, { altKey: true, ctrlKey: false, metaKey: false, target: main, ...extra });
        doc.dispatch('keydown', event);
        return event;
    };
    return { visited, press };
}

const dmRow = (id, { active = false, unread = false } = {}) => {
    const row = make('li', { dmId: id }, [link(`/dms/${id}`, active)]);
    if (unread) row.dataset.unread = '';
    return row;
};

test('Alt+arrows switch direct messages on the friends and DM pages, wrapping around', () => {
    const ctx = dmPage([dmRow('1'), dmRow('2', { active: true }), dmRow('3')]);
    ctx.press('ArrowDown');
    ctx.press('ArrowUp');
    assert.deepEqual(ctx.visited, ['/dms/3', '/dms/1']);

    const top = dmPage([dmRow('1', { active: true }), dmRow('2')]);
    top.press('ArrowUp');
    assert.deepEqual(top.visited, ['/dms/2'], 'wraps from the first to the last');
});

test('Alt+Shift+arrows go to unread direct messages', () => {
    const ctx = dmPage([dmRow('1', { active: true }), dmRow('2'), dmRow('3', { unread: true })]);
    ctx.press('ArrowDown', { shiftKey: true });
    assert.deepEqual(ctx.visited, ['/dms/3']);
});

test('on the friends page with no DM open, Alt+Down opens the first DM and Alt+Up the last', () => {
    const ctx = dmPage([dmRow('1'), dmRow('2'), dmRow('3')]);
    ctx.press('ArrowDown');
    ctx.press('ArrowUp');
    assert.deepEqual(ctx.visited, ['/dms/1', '/dms/3']);
});

test('a channel sidebar wins over a DM list on the same page', () => {
    const doc = fakeDocument(make('body', {}, [
        make('section', { dmList: '' }, [dmRow('1')]),
        make('nav', { channelSidebar: '' }, [channel('a')]),
    ]));
    assert.deepEqual(navigationItems(doc).map((item) => item.link.getAttribute('href')), ['/c/a']);

    const empty = fakeDocument(make('body', {}, [make('nav', { channelSidebar: '' }), make('section', { dmList: '' }, [dmRow('1')])]));
    assert.deepEqual(navigationItems(empty), [], 'no fall back to DMs from an empty channel sidebar');
});

test('rows whose link has an empty href are skipped', () => {
    const blank = make('li', { channel: '' }, [link('')]);
    const doc = fakeDocument(make('body', {}, [make('nav', { channelSidebar: '' }, [channel('a'), blank, channel('b')])]));
    assert.deepEqual(navigationItems(doc).map((item) => item.link.getAttribute('href')), ['/c/a', '/c/b']);
});

test('only the open channel, or every other one hidden: Alt+arrows do nothing and keep the key', () => {
    const alone = page([channel('a', { active: true })]);
    const event = alone.press('ArrowDown');
    assert.deepEqual(alone.visited, []);
    assert.equal(event.defaultPrevented, false);

    const collapsed = page([channel('a', { active: true }), channel('b', { hidden: true }), channel('c', { hidden: true })]);
    collapsed.press('ArrowDown');
    collapsed.press('ArrowUp');
    assert.deepEqual(collapsed.visited, []);
    assert.equal(collapsed.storage.data[FOCUS_KEY], undefined, 'nothing stored for a switch that did not happen');
});

test('an open channel inside a collapsed category still steps to its visible neighbours', () => {
    const ctx = page([channel('a'), channel('b', { active: true, hidden: true }), channel('c')]);
    ctx.press('ArrowDown');
    ctx.press('ArrowUp');
    assert.deepEqual(ctx.visited, ['/c/c', '/c/a']);
});

test('a filled search or other input keeps its text; an empty contenteditable does not block', () => {
    const ctx = page([channel('a', { active: true }), channel('b')]);
    const search = make('input');
    search.value = 'gen';
    ctx.press('ArrowDown', { target: search });
    assert.deepEqual(ctx.visited, []);

    // Only whitespace still counts as a draft: it is not thrown away.
    ctx.composer.value = '   ';
    ctx.press('ArrowDown', { target: ctx.composer });
    assert.deepEqual(ctx.visited, []);

    ctx.press('ArrowDown', { target: { isContentEditable: true, textContent: '' } });
    assert.deepEqual(ctx.visited, ['/c/b']);
});

test('a text field in the sidebar with text in it is left alone, even with Shift', () => {
    const rows = [channel('a', { active: true }), channel('b', { unread: true })];
    const ctx = page(rows);
    const filter = make('input');
    filter.value = 'x';
    ctx.sidebar.appendChild(filter);
    ctx.press('ArrowDown', { target: filter, shiftKey: true });
    assert.deepEqual(ctx.visited, []);
});

test('in a sidebar you can reorder, focus outside a channel or category (its header) still switches', () => {
    const header = make('button');
    const ctx = page([channel('a', { active: true }), channel('b')], { manage: true });
    ctx.sidebar.appendChild(header);
    ctx.press('ArrowDown', { target: header });
    assert.deepEqual(ctx.visited, ['/c/b']);
});

test('an event without a target (window focus) still switches', () => {
    const ctx = page([channel('a', { active: true }), channel('b')]);
    ctx.press('ArrowDown', { target: undefined });
    assert.deepEqual(ctx.visited, ['/c/b']);
});

test('handle() is the keydown listener the page uses', () => {
    const ctx = page([channel('a', { active: true }), channel('b')]);
    assert.deepEqual(ctx.doc.listeners.keydown, [ctx.api.handle]);
    ctx.api.handle(keyEvent('ArrowDown', { altKey: true, target: ctx.main }));
    assert.deepEqual(ctx.visited, ['/c/b']);
});

test('arriving focuses without scrolling, and only for the exact flag value', () => {
    const calls = [];
    const composer = make('textarea', { messageInput: '' });
    composer.focus = (options) => calls.push(options);
    const doc = () => fakeDocument(make('body', {}, [make('main', { appMain: '' }, [composer])]));

    initKeyboardShortcuts({ document: doc(), navigate: () => {}, storage: fakeStorage({ [FOCUS_KEY]: '1' }) });
    assert.deepEqual(calls, [{ preventScroll: true }]);

    const other = fakeStorage({ [FOCUS_KEY]: 'yes' });
    initKeyboardShortcuts({ document: doc(), navigate: () => {}, storage: other });
    assert.equal(calls.length, 1, 'any other value is not a shortcut arrival');
});

test('arriving on a page with neither composer nor main does not throw', () => {
    const storage = fakeStorage({ [FOCUS_KEY]: '1' });
    assert.doesNotThrow(() => initKeyboardShortcuts({ document: fakeDocument(make('body')), navigate: () => {}, storage }));
    assert.equal(storage.data[FOCUS_KEY], undefined);
});

test('storage that reads but cannot be cleared does not focus (or throw)', () => {
    const composer = focusable(make('textarea', { messageInput: '' }));
    const storage = { getItem: () => '1', setItem() {}, removeItem() { throw new Error('blocked'); } };
    assert.doesNotThrow(() => initKeyboardShortcuts({
        document: fakeDocument(make('body', {}, [composer])), navigate: () => {}, storage,
    }));
    assert.equal(composer.focused, 0);
});

test('blocked or missing storage still switches', () => {
    const throwing = {
        getItem() { throw new Error('blocked'); },
        setItem() { throw new Error('blocked'); },
        removeItem() { throw new Error('blocked'); },
    };
    const ctx = page([channel('a', { active: true }), channel('b')], { storage: throwing });
    ctx.press('ArrowDown');
    assert.deepEqual(ctx.visited, ['/c/b']);

    const none = page([channel('a', { active: true }), channel('b')], { storage: null });
    none.press('ArrowDown');
    assert.deepEqual(none.visited, ['/c/b']);
});
