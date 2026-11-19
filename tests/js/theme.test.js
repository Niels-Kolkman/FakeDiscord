// ISSUE-37: the theme toggle switches <html data-theme> at once, saves the
// choice in the background and goes back if saving fails. Run with
// `npm test`. The server side (PUT /settings/theme, the layouts) is
// covered by tests/Feature/Settings/ThemeTest.php.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    STORAGE_KEY,
    applyTheme,
    initTheme,
    normalTheme,
    otherTheme,
    toggleLabel,
} from '../../resources/js/theme.js';
import { fakeDocument, make } from './helpers/fake-dom.js';

/** A toggle as components/theme-toggle.blade.php renders it, for `current`. */
function toggle(current = 'dark', token = 'csrf-123') {
    const next = otherTheme(current);
    const input = make('input', { themeNext: '' });
    input.value = next;
    const icon = make('span', { themeIcon: '' });
    icon.textContent = next === 'light' ? '☀️' : '🌙';
    const label = make('span', { themeLabel: '' });
    label.textContent = toggleLabel(next);
    const button = make('button', { themeButton: '' }, [icon, label]);
    button.setAttribute('title', toggleLabel(next));

    const form = make('form', { themeToggle: '' }, [input, button]);
    form.setAttribute('action', '/settings/theme');
    form.elements = { namedItem: (name) => (name === '_token' ? { value: token } : null) };

    return { form, input, icon, label, button };
}

function page(current = 'dark', count = 1) {
    const toggles = Array.from({ length: count }, () => toggle(current));
    const doc = fakeDocument(make('body', {}, toggles.map((t) => t.form)));
    doc.documentElement = { dataset: { theme: current } };
    return { doc, toggles };
}

function submit(form) {
    const event = { type: 'submit', defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    form.dispatchEvent(event);
    return event;
}

/** A fetch that answers when the test says so. */
function controlledFetch() {
    const calls = [];
    const fetch = (url, options) => new Promise((resolve, reject) => {
        calls.push({ url, options, resolve, reject });
    });
    const answer = (index, theme, { ok = true, status = 200 } = {}) => {
        calls[index].resolve({ ok, status, json: async () => ({ theme }) });
    };
    return { fetch, calls, answer };
}

function fakeStorage({ throws = false } = {}) {
    const items = {};
    return {
        items,
        setItem(key, value) {
            if (throws) throw new Error('QuotaExceededError');
            items[key] = String(value);
        },
    };
}

function fakeWindow() {
    const listeners = {};
    return {
        addEventListener(type, handler) { (listeners[type] ??= []).push(handler); },
        fire(type, event) { (listeners[type] ?? []).forEach((handler) => handler(event)); },
    };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

test('normalTheme and otherTheme fall back to dark for unknown values', () => {
    assert.equal(normalTheme('light'), 'light');
    assert.equal(normalTheme('dark'), 'dark');
    for (const value of [undefined, null, '', 'LIGHT', 'sepia', 1]) {
        assert.equal(normalTheme(value), 'dark', String(value));
    }
    assert.equal(otherTheme('dark'), 'light');
    assert.equal(otherTheme('light'), 'dark');
    assert.equal(otherTheme('sepia'), 'light');
    assert.equal(toggleLabel('light'), 'Switch to light theme');
});

test('applyTheme sets the page theme and points every toggle at the other one', () => {
    const { doc, toggles } = page('dark', 2);

    assert.equal(applyTheme(doc, 'light'), 'light');

    assert.equal(doc.documentElement.dataset.theme, 'light');
    for (const t of toggles) {
        assert.equal(t.input.value, 'dark');
        assert.equal(t.icon.textContent, '🌙');
        assert.equal(t.label.textContent, 'Switch to dark theme');
        assert.equal(t.button.getAttribute('title'), 'Switch to dark theme');
    }

    assert.equal(applyTheme(doc, 'nonsense'), 'dark');
    assert.equal(doc.documentElement.dataset.theme, 'dark');
    assert.equal(toggles[0].input.value, 'light');
    assert.equal(toggles[0].icon.textContent, '☀️');
});

test('submitting switches at once and saves the theme as JSON with the form token', async () => {
    const { doc, toggles } = page('dark');
    const { fetch, calls, answer } = controlledFetch();
    initTheme({ document: doc, fetch });

    const event = submit(toggles[0].form);

    assert.equal(event.defaultPrevented, true);
    assert.equal(doc.documentElement.dataset.theme, 'light', 'switched before the server answered');
    assert.equal(toggles[0].input.value, 'dark');
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/settings/theme');
    assert.equal(calls[0].options.method, 'PUT');
    assert.equal(calls[0].options.credentials, 'same-origin');
    assert.equal(calls[0].options.headers.Accept, 'application/json');
    assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'csrf-123');
    assert.deepEqual(JSON.parse(calls[0].options.body), { theme: 'light' });

    answer(0, 'light');
    await flush();
    assert.equal(doc.documentElement.dataset.theme, 'light');
});

test('a failed save goes back to the theme the page had', async () => {
    for (const failure of ['status', 'network']) {
        const { doc, toggles } = page('dark');
        const { fetch, calls, answer } = controlledFetch();
        initTheme({ document: doc, fetch });

        submit(toggles[0].form);
        if (failure === 'status') answer(0, null, { ok: false, status: 429 });
        else calls[0].reject(new TypeError('Failed to fetch'));
        await flush();

        assert.equal(doc.documentElement.dataset.theme, 'dark', failure);
        assert.equal(toggles[0].input.value, 'light', failure);
        assert.equal(toggles[0].label.textContent, 'Switch to light theme', failure);
    }
});

test('the server answer wins, and a bad answer falls back to what was sent', async () => {
    const { doc, toggles } = page('dark');
    const { fetch, calls } = controlledFetch();
    initTheme({ document: doc, fetch });

    submit(toggles[0].form);
    calls[0].resolve({ ok: true, status: 200, json: async () => ({ theme: 'dark' }) });
    await flush();
    assert.equal(doc.documentElement.dataset.theme, 'dark');

    submit(toggles[0].form);
    calls[1].resolve({ ok: true, status: 200, json: async () => null });
    await flush();
    assert.equal(doc.documentElement.dataset.theme, 'light');
});

test('a late answer to an older click cannot undo a newer one', async () => {
    const { doc, toggles } = page('dark');
    const { fetch, calls, answer } = controlledFetch();
    initTheme({ document: doc, fetch });

    submit(toggles[0].form); // to light
    submit(toggles[0].form); // back to dark
    assert.deepEqual(calls.map((c) => JSON.parse(c.options.body).theme), ['light', 'dark']);

    answer(1, 'dark');
    await flush();
    answer(0, 'light'); // the older one answers last
    await flush();
    assert.equal(doc.documentElement.dataset.theme, 'dark');

    // And an older failure does not revert either.
    submit(toggles[0].form); // light
    submit(toggles[0].form); // dark
    submit(toggles[0].form); // light
    calls[2].reject(new Error('late failure'));
    answer(4, 'light');
    await flush();
    answer(3, 'dark');
    await flush();
    assert.equal(doc.documentElement.dataset.theme, 'light');
});

test('every toggle on the page stays in step', async () => {
    const { doc, toggles } = page('dark', 2);
    const { fetch, answer } = controlledFetch();
    initTheme({ document: doc, fetch });

    submit(toggles[1].form);
    answer(0, 'light');
    await flush();

    assert.equal(toggles[0].input.value, 'dark');
    assert.equal(toggles[0].label.textContent, 'Switch to dark theme');

    // The first one now switches back to dark.
    submit(toggles[0].form);
    assert.equal(doc.documentElement.dataset.theme, 'dark');
});

test('a saved switch tells other tabs, which follow it', async () => {
    const { doc, toggles } = page('dark');
    const { fetch, answer } = controlledFetch();
    const storage = fakeStorage();
    initTheme({ document: doc, fetch, storage });

    submit(toggles[0].form);
    assert.equal(storage.items[STORAGE_KEY], undefined, 'not before it is saved');
    answer(0, 'light');
    await flush();
    assert.match(storage.items[STORAGE_KEY], /^light:\d+$/);

    const other = page('dark');
    const win = fakeWindow();
    initTheme({ document: other.doc, fetch: null, window: win });

    win.fire('storage', { key: STORAGE_KEY, newValue: storage.items[STORAGE_KEY] });
    assert.equal(other.doc.documentElement.dataset.theme, 'light');
    assert.equal(other.toggles[0].input.value, 'dark');

    // Other keys, cleared keys and junk are ignored or read as dark.
    win.fire('storage', { key: 'something-else', newValue: 'dark:1' });
    win.fire('storage', { key: STORAGE_KEY, newValue: null });
    assert.equal(other.doc.documentElement.dataset.theme, 'light');
    win.fire('storage', { key: STORAGE_KEY, newValue: 'sepia:1' });
    assert.equal(other.doc.documentElement.dataset.theme, 'dark');
});

test('blocked storage does not break saving', async () => {
    const { doc, toggles } = page('dark');
    const { fetch, answer } = controlledFetch();
    initTheme({ document: doc, fetch, storage: fakeStorage({ throws: true }) });

    submit(toggles[0].form);
    answer(0, 'light');
    await flush();

    assert.equal(doc.documentElement.dataset.theme, 'light');
});

test('without fetch the form is left to submit normally', () => {
    const { doc, toggles } = page('dark');
    initTheme({ document: doc, fetch: null });

    const event = submit(toggles[0].form);

    assert.equal(event.defaultPrevented, false);
    assert.equal(doc.documentElement.dataset.theme, 'dark');
});

test('a page without toggles still follows other tabs', () => {
    const doc = fakeDocument(make('body'));
    doc.documentElement = { dataset: {} };
    const win = fakeWindow();
    initTheme({ document: doc, fetch: () => assert.fail('nothing to save'), window: win });

    win.fire('storage', { key: STORAGE_KEY, newValue: 'light:5' });

    assert.equal(doc.documentElement.dataset.theme, 'light');
});
