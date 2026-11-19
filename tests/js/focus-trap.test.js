// ISSUE-38: Tab stays inside open popups (resources/js/focus-trap.js), used
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

test('other keys and a missing dialog are ignored', () => {
    const { panel, close } = dialog();
    const event = keyEvent('ArrowDown');
    assert.equal(trapTab(panel, event, fakeDoc(close)), false);
    assert.equal(event.defaultPrevented, false);
    assert.equal(trapTab(null, keyEvent('Tab'), fakeDoc(close)), false);
});
