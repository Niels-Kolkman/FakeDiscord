// Tab stays inside open popups (resources/js/focus-trap.js), used
// by the inbox (notifications.test.js covers it end to end), the pinned
// messages panel and the profile card. Run with `npm test`.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { focusableIn, trapTab } from '../../resources/js/focus-trap.js';
import { keyEvent, make } from './helpers/fake-dom.js';

function focusable(node) {
    node.focused = 0;
    node.focus = () => { node.focused += 1; };
    return node;
}

function anchor(href) {
    const a = focusable(make('a'));
    if (href !== null) a.setAttribute('href', href);
    return a;
}

/** A dialog with: a button, a link, a hidden section with a button, an input, a plain span. */
function dialog() {
    const close = focusable(make('button'));
    const linkWithHref = anchor('/x');
    const linkWithout = anchor(null);
    const hiddenButton = focusable(make('button'));
    const hiddenPart = make('div', {}, [hiddenButton]);
    hiddenPart.hidden = true;
    const field = focusable(make('input'));
    const hiddenInput = make('input');
    hiddenInput.type = 'hidden';
    const disabled = make('button');
    disabled.disabled = true;
    const negative = make('div');
    negative.setAttribute('tabindex', '-1');
    const custom = focusable(make('div'));
    custom.setAttribute('tabindex', '0');
    const last = focusable(make('summary'));
    const panel = focusable(make('div', {}, [
        close,
        make('p', {}, [linkWithHref, linkWithout, make('span')]),
        hiddenPart,
        field, hiddenInput, disabled, negative, custom,
        make('details', {}, [last]),
    ]));
    return { panel, close, linkWithHref, field, custom, last, hiddenButton };
}

const fakeDoc = (activeElement) => ({ activeElement });

test('focusableIn finds what Tab reaches, in order, skipping hidden and disabled parts', () => {
    const { panel, close, linkWithHref, field, custom, last } = dialog();
    assert.deepEqual(focusableIn(panel), [close, linkWithHref, field, custom, last]);
    assert.deepEqual(focusableIn(null), []);
    assert.deepEqual(focusableIn(make('div')), []);
});

test('Tab on the last element wraps to the first', () => {
    const { panel, close, last } = dialog();
    const event = keyEvent('Tab');
    assert.equal(trapTab(panel, event, fakeDoc(last)), true);
    assert.equal(event.defaultPrevented, true);
    assert.equal(close.focused, 1);
});

test('Shift+Tab on the first element wraps to the last', () => {
    const { panel, close, last } = dialog();
    const event = keyEvent('Tab', { shiftKey: true });
    assert.equal(trapTab(panel, event, fakeDoc(close)), true);
    assert.equal(event.defaultPrevented, true);
    assert.equal(last.focused, 1);
});

test('Tab in the middle is left to the browser', () => {
    const { panel, linkWithHref, field } = dialog();
    const forward = keyEvent('Tab');
    assert.equal(trapTab(panel, forward, fakeDoc(linkWithHref)), false);
    assert.equal(forward.defaultPrevented, false);

    const back = keyEvent('Tab', { shiftKey: true });
    assert.equal(trapTab(panel, back, fakeDoc(field)), false);
    assert.equal(back.defaultPrevented, false);
});

test('from the dialog itself (just opened), Tab goes to the first and Shift+Tab to the last', () => {
    const { panel, close, last } = dialog();
    trapTab(panel, keyEvent('Tab'), fakeDoc(panel));
    assert.equal(close.focused, 1);
    trapTab(panel, keyEvent('Tab', { shiftKey: true }), fakeDoc(panel));
    assert.equal(last.focused, 1);
});

test('a dialog with nothing to focus keeps focus on itself', () => {
    const panel = focusable(make('div', {}, [make('p')]));
    const event = keyEvent('Tab');
    assert.equal(trapTab(panel, event, fakeDoc(panel)), true);
    assert.equal(event.defaultPrevented, true);
    assert.equal(panel.focused, 1);
});

// --- Further edge cases ---

test('tabindex decides over the tag: -1 removes a button, 0 adds a link without href', () => {
    const skipped = make('button');
    skipped.setAttribute('tabindex', '-1');
    const reachable = make('a');
    reachable.setAttribute('tabindex', '0');
    const positive = make('span');
    positive.setAttribute('tabindex', '2');
    assert.deepEqual(focusableIn(make('div', {}, [skipped, reachable, positive])), [reachable, positive]);
});

test('select and textarea are reachable, disabled ones and disabled inputs are not', () => {
    const select = make('select');
    const area = make('textarea');
    const off = [make('select'), make('textarea'), make('input')];
    off.forEach((node) => { node.disabled = true; });
    const text = make('input');
    text.type = 'text';
    assert.deepEqual(focusableIn(make('div', {}, [select, ...off, area, text])), [select, area, text]);
});

test('a link whose href was set by a script (property, no attribute) is reachable', () => {
    const scripted = make('a');
    scripted.href = 'https://example.com/jump';
    assert.equal(scripted.getAttribute('href'), null);
    assert.deepEqual(focusableIn(make('div', {}, [scripted])), [scripted]);
});

test('a focusable element inside a focusable one (a link in a summary) is found too', () => {
    const inner = make('a');
    inner.setAttribute('href', '/x');
    const summary = make('summary', {}, [inner]);
    assert.deepEqual(focusableIn(make('div', {}, [summary])), [summary, inner]);
});

test('a hidden focusable element and everything inside it are left out', () => {
    const inner = make('button');
    const hiddenButton = make('button', {}, [inner]);
    hiddenButton.hidden = true;
    const visible = make('button');
    assert.deepEqual(focusableIn(make('div', {}, [hiddenButton, visible])), [visible]);
});

test('with a single focusable element, Tab and Shift+Tab stay on it', () => {
    const only = focusable(make('button'));
    const panel = make('div', {}, [only]);

    const forward = keyEvent('Tab');
    assert.equal(trapTab(panel, forward, fakeDoc(only)), true);
    assert.equal(forward.defaultPrevented, true);

    const back = keyEvent('Tab', { shiftKey: true });
    assert.equal(trapTab(panel, back, fakeDoc(only)), true);
    assert.equal(back.defaultPrevented, true);
    assert.equal(only.focused, 2);
});

test('focus outside the dialog (the page, or a hidden part of it) is pulled back in', () => {
    const { panel, close, last, hiddenButton } = dialog();
    const elsewhere = make('button');

    trapTab(panel, keyEvent('Tab'), fakeDoc(elsewhere));
    assert.equal(close.focused, 1);
    trapTab(panel, keyEvent('Tab', { shiftKey: true }), fakeDoc(hiddenButton));
    assert.equal(last.focused, 1);
    trapTab(panel, keyEvent('Tab'), fakeDoc(null));
    assert.equal(close.focused, 2, 'nothing focused counts as outside');
});

test('focus moves without scrolling the page', () => {
    const calls = [];
    const first = make('button');
    first.focus = (options) => calls.push(options);
    const second = make('button');
    const panel = make('div', {}, [first, second]);
    trapTab(panel, keyEvent('Tab'), fakeDoc(second));
    assert.deepEqual(calls, [{ preventScroll: true }]);
});

test('without a document (no activeElement) Tab goes to the first element', () => {
    const { panel, close } = dialog();
    assert.equal(trapTab(panel, keyEvent('Tab'), undefined), true);
    assert.equal(close.focused, 1);
});

test('elements and a dialog without focus() do not throw', () => {
    const bare = make('div', {}, [make('p')]);
    const event = keyEvent('Tab');
    assert.doesNotThrow(() => trapTab(bare, event, fakeDoc(null)));
    assert.equal(event.defaultPrevented, true, 'Tab is still kept in the dialog');

    const withButtons = make('div', {}, [make('button'), make('button')]);
    assert.doesNotThrow(() => trapTab(withButtons, keyEvent('Tab'), fakeDoc(null)));
});

test('other keys and a missing dialog are ignored', () => {
    const { panel, close } = dialog();
    const event = keyEvent('ArrowDown');
    assert.equal(trapTab(panel, event, fakeDoc(close)), false);
    assert.equal(event.defaultPrevented, false);
    assert.equal(trapTab(null, keyEvent('Tab'), fakeDoc(close)), false);
});
