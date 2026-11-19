// ISSUE-37: the small-screen drawers (resources/js/mobile-nav.js): the top
// bar buttons, swipes, Escape and the backdrop open and close them, the
// rest of the page is inert meanwhile and focus goes back. Run with
// `npm test`. The markup is covered by tests/Feature/Layout/MobileLayoutTest.php.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    FLICK_DISTANCE,
    FLICK_MS,
    MOBILE_QUERY,
    SWIPE_DISTANCE,
    initMobileNav,
    swipeDirection,
} from '../../resources/js/mobile-nav.js';
import { fakeDocument, keyEvent, make } from './helpers/fake-dom.js';

function focusable(node) {
    node.focused = 0;
    node.focus = () => { node.focused += 1; };
    return node;
}

function fakeMedia(matches = true) {
    const listeners = [];
    return {
        matches,
        queries: [],
        addEventListener(type, handler) { if (type === 'change') listeners.push(handler); },
        change(value) {
            this.matches = value;
            listeners.forEach((handler) => handler({ matches: value }));
        },
    };
}

/** The app shell as components/layouts/app.blade.php renders it. */
function shell({ members = true, mobile = true } = {}) {
    const navToggle = focusable(make('button', { drawerToggle: 'nav' }));
    navToggle.setAttribute('aria-expanded', 'false');
    const membersToggle = members ? focusable(make('button', { drawerToggle: 'members' })) : null;
    membersToggle?.setAttribute('aria-expanded', 'false');

    const composer = make('textarea');
    const code = make('pre', {}, [make('code')]);
    const message = make('p');
    const noSwipe = make('div', { noSwipe: '' }, [make('span')]);
    const main = make('main', { appMain: '' }, [
        make('div', { mobileBar: '' }, [navToggle, ...(membersToggle ? [membersToggle] : [])]),
        message, code, composer, noSwipe,
    ]);

    const nav = focusable(make('div', { drawer: 'nav' }, [make('nav', { serverRail: '' }), make('nav', { channelSidebar: '' })]));
    const membersDrawer = members ? focusable(make('div', { drawer: 'members' }, [make('aside', { memberList: '' })])) : null;
    const backdrop = make('div', { drawerBackdrop: '' });
    backdrop.hidden = true;

    const body = make('body', { appShell: '' }, [nav, main, ...(membersDrawer ? [membersDrawer] : []), backdrop]);
    const doc = fakeDocument(body);
    doc.activeElement = body;

    const media = fakeMedia(mobile);
    let time = 1000;
    const clock = { now: () => time, advance: (ms) => { time += ms; } };
    const api = initMobileNav({
        document: doc,
        matchMedia: (query) => { media.queries.push(query); return media; },
        now: clock.now,
    });

    return { doc, api, media, clock, main, nav, membersDrawer, backdrop, navToggle, membersToggle, composer, code, message, noSwipe };
}

function click(node) {
    node.dispatchEvent({ type: 'click' });
}

/** A one-finger swipe from (x1, y) to (x2, y + dy) taking `ms`. */
function swipe(ctx, { from = 100, to, dy = 0, ms = 400, target = ctx.message, fingers = 1 }) {
    const touch = (x, y) => ({ clientX: x, clientY: y });
    ctx.doc.dispatch('touchstart', { target, touches: Array.from({ length: fingers }, () => touch(from, 300)) });
    ctx.clock.advance(ms);
    ctx.doc.dispatch('touchend', { target, changedTouches: [touch(to, 300 + dy)] });
}

function isOpen(drawer) {
    return 'open' in drawer.dataset;
}

test('swipeDirection needs a long enough, mostly sideways move', () => {
    const at = (x, y = 0, t = 0) => ({ x, y, t });

    assert.equal(swipeDirection(at(0), at(SWIPE_DISTANCE, 0, 1000)), 'right');
    assert.equal(swipeDirection(at(200), at(200 - SWIPE_DISTANCE, 0, 1000)), 'left');
    assert.equal(swipeDirection(at(0), at(SWIPE_DISTANCE - 1, 0, 1000)), null, 'too short and slow');
    assert.equal(swipeDirection(at(0), at(FLICK_DISTANCE, 0, FLICK_MS)), 'right', 'a quick flick');
    assert.equal(swipeDirection(at(0), at(FLICK_DISTANCE - 1, 0, 10)), null, 'too short even for a flick');
    assert.equal(swipeDirection(at(0), at(FLICK_DISTANCE, 0, FLICK_MS + 1)), null, 'a slow short move');

    // Scrolling: more vertical than 1.5 times sideways.
    assert.equal(swipeDirection(at(0, 0), at(90, 61, 100)), null);
    assert.equal(swipeDirection(at(0, 0), at(90, 60, 100)), 'right');
    assert.equal(swipeDirection(at(0, 0), at(0, 200, 100)), null);

    assert.equal(swipeDirection(null, at(100)), null);
    assert.equal(swipeDirection(at(0), null), null);
});

test('it listens to the small-screen media query and does nothing without drawers', () => {
    const { media } = shell();
    assert.deepEqual(media.queries, [MOBILE_QUERY]);

    const doc = fakeDocument(make('body'));
    assert.equal(initMobileNav({ document: doc, matchMedia: () => fakeMedia() }), null);
    assert.deepEqual(Object.keys(doc.listeners), []);
});

test('the bar buttons open a drawer, make the page inert and show the backdrop', () => {
    const ctx = shell();

    click(ctx.navToggle);

    assert.equal(ctx.api.current(), 'nav');
    assert.equal(isOpen(ctx.nav), true);
    assert.equal(ctx.navToggle.getAttribute('aria-expanded'), 'true');
    assert.equal(ctx.membersToggle.getAttribute('aria-expanded'), 'false');
    assert.equal(ctx.backdrop.hidden, false);
    assert.equal(ctx.main.getAttribute('inert'), '');
    assert.equal(ctx.membersDrawer.getAttribute('inert'), '');
    assert.equal(ctx.nav.getAttribute('inert'), null);
    assert.equal(ctx.nav.getAttribute('tabindex'), '-1');
    assert.equal(ctx.nav.focused, 1);
});

test('closing undoes all of it and gives focus back to the button', () => {
    const ctx = shell();
    click(ctx.membersToggle);
    assert.equal(ctx.api.current(), 'members');

    ctx.api.close();

    assert.equal(ctx.api.current(), null);
    assert.equal(isOpen(ctx.membersDrawer), false);
    assert.equal(ctx.membersToggle.getAttribute('aria-expanded'), 'false');
    assert.equal(ctx.backdrop.hidden, true);
    assert.equal(ctx.main.getAttribute('inert'), null);
    assert.equal(ctx.nav.getAttribute('inert'), null);
    assert.equal(ctx.membersToggle.focused, 1);

    // Closing again is a no-op.
    ctx.api.close();
    assert.equal(ctx.membersToggle.focused, 1);
});

test('a button for the open drawer closes it', () => {
    const ctx = shell();
    click(ctx.navToggle);
    click(ctx.navToggle);
    assert.equal(ctx.api.current(), null);
});

test('opening the other drawer swaps them', () => {
    const ctx = shell();
    click(ctx.navToggle);
    click(ctx.membersToggle);

    assert.equal(ctx.api.current(), 'members');
    assert.equal(isOpen(ctx.nav), false);
    assert.equal(isOpen(ctx.membersDrawer), true);
    assert.equal(ctx.navToggle.getAttribute('aria-expanded'), 'false');
    assert.equal(ctx.nav.getAttribute('inert'), '');
    assert.equal(ctx.navToggle.focused, 0, 'swapping does not bounce focus to the old button');

    ctx.api.close();
    assert.equal(ctx.membersToggle.focused, 1);
});

test('Escape and the backdrop close the open drawer', () => {
    const ctx = shell();
    click(ctx.navToggle);

    const esc = keyEvent('Escape');
    ctx.doc.dispatch('keydown', esc);
    assert.equal(ctx.api.current(), null);
    assert.equal(esc.defaultPrevented, true);

    // With nothing open, Escape is left alone (other scripts use it).
    const idle = keyEvent('Escape');
    ctx.doc.dispatch('keydown', idle);
    assert.equal(idle.defaultPrevented, false);

    click(ctx.membersToggle);
    click(ctx.backdrop);
    assert.equal(ctx.api.current(), null);

    click(ctx.navToggle);
    ctx.doc.dispatch('keydown', keyEvent('Enter'));
    assert.equal(ctx.api.current(), 'nav');
});

test('swiping right opens the navigation, left the member list, and back closes them', () => {
    const ctx = shell();

    swipe(ctx, { from: 20, to: 200 });
    assert.equal(ctx.api.current(), 'nav');

    swipe(ctx, { from: 200, to: 250 });
    assert.equal(ctx.api.current(), 'nav', 'another right swipe keeps it open');
    swipe(ctx, { from: 250, to: 100 });
    assert.equal(ctx.api.current(), null);

    swipe(ctx, { from: 300, to: 100 });
    assert.equal(ctx.api.current(), 'members');
    swipe(ctx, { from: 100, to: 20 });
    assert.equal(ctx.api.current(), 'members', 'another left swipe keeps it open');
    swipe(ctx, { from: 100, to: 300 });
    assert.equal(ctx.api.current(), null);
});

test('a swipe left with no member list does nothing', () => {
    const ctx = shell({ members: false });

    swipe(ctx, { from: 300, to: 100 });
    assert.equal(ctx.api.current(), null);

    swipe(ctx, { from: 20, to: 200 });
    assert.equal(ctx.api.current(), 'nav');
});

test('short, slow, vertical and multi-finger moves are not swipes', () => {
    const ctx = shell();

    swipe(ctx, { from: 100, to: 100 + SWIPE_DISTANCE - 1 });
    swipe(ctx, { from: 100, to: 300, dy: 200 });
    swipe(ctx, { from: 100, to: 300, fingers: 2 });
    assert.equal(ctx.api.current(), null);

    swipe(ctx, { from: 100, to: 100 + FLICK_DISTANCE, ms: 100 });
    assert.equal(ctx.api.current(), 'nav', 'a quick flick counts');
});

test('swipes in text fields, code blocks and [data-no-swipe] are left alone', () => {
    const ctx = shell();

    for (const target of [ctx.composer, ctx.code.children[0], ctx.noSwipe.children[0]]) {
        swipe(ctx, { from: 20, to: 250, target });
        assert.equal(ctx.api.current(), null);
    }
});

test('a cancelled touch is not a swipe', () => {
    const ctx = shell();
    ctx.doc.dispatch('touchstart', { target: ctx.message, touches: [{ clientX: 0, clientY: 0 }] });
    ctx.doc.dispatch('touchcancel', {});
    ctx.doc.dispatch('touchend', { target: ctx.message, changedTouches: [{ clientX: 300, clientY: 0 }] });

    assert.equal(ctx.api.current(), null);

    // And a touchend without a start does nothing either.
    ctx.doc.dispatch('touchend', { target: ctx.message, changedTouches: [{ clientX: 300, clientY: 0 }] });
    assert.equal(ctx.api.current(), null);
});

test('from md up nothing opens, and growing past it closes the open drawer', () => {
    const desktop = shell({ mobile: false });
    click(desktop.navToggle);
    swipe(desktop, { from: 20, to: 250 });
    assert.equal(desktop.api.current(), null);
    assert.equal(desktop.main.getAttribute('inert'), null);

    const ctx = shell();
    click(ctx.navToggle);
    ctx.media.change(false);

    assert.equal(ctx.api.current(), null);
    assert.equal(ctx.main.getAttribute('inert'), null);
    assert.equal(ctx.backdrop.hidden, true);
    assert.equal(ctx.navToggle.focused, 0, 'the button is hidden from md up, so focus stays');

    // Shrinking back does not reopen it.
    ctx.media.change(true);
    assert.equal(ctx.api.current(), null);
});

test('a swiped-open drawer gives focus back to what had it', () => {
    const ctx = shell();
    focusable(ctx.message);
    ctx.doc.activeElement = ctx.message;

    swipe(ctx, { from: 20, to: 250 });
    ctx.doc.dispatch('keydown', keyEvent('Escape'));

    assert.equal(ctx.message.focused, 1);
});

test('without matchMedia the drawers never open', () => {
    const body = make('body', {}, [make('div', { drawer: 'nav' }), make('main', { appMain: '' })]);
    const api = initMobileNav({ document: fakeDocument(body), matchMedia: null });

    api.open('nav');
    assert.equal(api.current(), null);
});
