// Live friend requests. Run with `npm test` (node --test, no deps).
// The Blade markup is covered by tests/Feature/Friends/FriendsPageTest.php;
// the script is checked here against a minimal fake DOM and a fake Echo.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    FRIENDSHIP_EVENT,
    initFriends,
    noticeTab,
    noticeText,
    refreshPanel,
    showToast,
    updateRequestBadges,
} from '../../resources/js/friends.js';

// --- Fake DOM ------------------------------------------------------------

// Only [data-*] selectors are needed. innerHTML throws, so a test fails
// loudly if the script ever parsed user text as HTML.
const camel = (name) => name.replace(/-([a-z])/g, (_, c) => c.toUpperCase());

function fakeNode(tag = 'div') {
    const node = {
        tagName: tag.toUpperCase(),
        className: '',
        textContent: '',
        hidden: false,
        href: undefined,
        dataset: {},
        attributes: {},
        childNodes: [],
        parentNode: null,
        setAttribute(name, value) {
            this.attributes[name] = String(value);
        },
        getAttribute(name) {
            return this.attributes[name] ?? null;
        },
        appendChild(child) {
            child.parentNode = this;
            this.childNodes.push(child);
            return child;
        },
        replaceChildren(...children) {
            this.childNodes = [];
            children.forEach((child) => this.appendChild(child));
        },
        remove() {
            if (!this.parentNode) return;
            this.parentNode.childNodes = this.parentNode.childNodes.filter((child) => child !== this);
            this.parentNode = null;
        },
        querySelectorAll(selector) {
            const match = /^\[data-([a-z-]+)\]$/.exec(selector);
            if (!match) throw new Error(`Unsupported selector ${selector}`);
            const key = camel(match[1]);
            const found = [];
            const walk = (parent) => parent.childNodes.forEach((child) => {
                if (child.dataset && key in child.dataset) found.push(child);
                walk(child);
            });
            walk(this);
            return found;
        },
        querySelector(selector) {
            return this.querySelectorAll(selector)[0] ?? null;
        },
    };
    Object.defineProperty(node, 'innerHTML', {
        get() { throw new Error('innerHTML must not be used'); },
        set() { throw new Error('innerHTML must not be used'); },
    });
    return node;
}

function el(dataName, children = []) {
    const node = fakeNode();
    if (dataName) node.dataset[camel(dataName)] = '';
    children.forEach((child) => node.appendChild(child));
    return node;
}

function fakeDocument(children = []) {
    const root = el(null, children);
    root.body = el(null);
    root.appendChild(root.body);
    root.createElement = (tag) => fakeNode(tag);
    return root;
}

function badge(count, hidden = false) {
    const node = el('friend-request-badge');
    node.textContent = String(count);
    node.hidden = hidden;
    return node;
}

function fakeEcho() {
    const subscriptions = [];
    return {
        subscriptions,
        private(name) {
            const subscription = { name, listeners: {} };
            subscriptions.push(subscription);
            return {
                listen(event, callback) {
                    subscription.listeners[event] = callback;
                    return this;
                },
            };
        },
    };
}

function fakeTimers() {
    const timers = [];
    return {
        setTimer: (callback, ms) => timers.push({ callback, ms }),
        runAll: () => timers.splice(0).forEach(({ callback }) => callback()),
        timers,
    };
}

const alice = { id: 7, name: 'Alice', username: 'alice', avatar_url: 'data:,' };

// A server response whose [data-friends-panel] holds one marker node.
function serverPanel(label) {
    const marker = el('marker');
    marker.textContent = label;
    return fakeDocument([el('friends-panel', [marker])]);
}

// --- Pure helpers ----------------------------------------------------------

test('noticeText shows requests and accepts, not removals', () => {
    assert.equal(noticeText({ type: 'request', user: alice }), 'Alice sent you a friend request.');
    assert.equal(noticeText({ type: 'accepted', user: alice }), 'Alice accepted your friend request.');
    assert.equal(noticeText({ type: 'removed', user: alice }), null);
    assert.equal(noticeText({ type: 'unknown', user: alice }), null);
    assert.equal(noticeText({ type: 'request', user: { name: '' } }), null);
    assert.equal(noticeText({ type: 'request' }), null);
    assert.equal(noticeText(null), null);
});

test('noticeTab links requests to Pending and accepts to All', () => {
    assert.equal(noticeTab({ type: 'request' }), 'pending');
    assert.equal(noticeTab({ type: 'accepted' }), 'all');
});

test('updateRequestBadges sets every badge and hides them at zero', () => {
    const first = badge(0, true);
    const second = badge(0, true);
    const document = fakeDocument([first, el(null, [second])]);

    updateRequestBadges(document, 3);
    assert.deepEqual([first.textContent, first.hidden, second.textContent, second.hidden], ['3', false, '3', false]);

    updateRequestBadges(document, 0);
    assert.deepEqual([first.textContent, first.hidden], ['0', true]);

    updateRequestBadges(document, -2);
    assert.deepEqual([first.textContent, first.hidden], ['0', true]);
});

// --- Toasts ------------------------------------------------------------------

test('showToast adds a live region once, with the text as textContent and a View link', () => {
    const document = fakeDocument();
    const timers = fakeTimers();

    showToast(document, { text: '<b>Alice</b> sent you a friend request.', href: '/friends?tab=pending', ms: 100, setTimer: timers.setTimer });
    showToast(document, { text: 'Second', ms: 100, setTimer: timers.setTimer });

    const regions = document.querySelectorAll('[data-friend-toasts]');
    assert.equal(regions.length, 1);
    assert.equal(regions[0].getAttribute('role'), 'status');
    assert.equal(regions[0].getAttribute('aria-live'), 'polite');

    const toasts = document.querySelectorAll('[data-friend-toast]');
    assert.equal(toasts.length, 2);
    assert.equal(toasts[0].childNodes[0].textContent, '<b>Alice</b> sent you a friend request.');
    assert.equal(toasts[0].childNodes[1].href, '/friends?tab=pending');
    assert.equal(toasts[0].childNodes[1].textContent, 'View');
    assert.equal(toasts[1].childNodes.length, 1, 'no link without an href');

    assert.deepEqual(timers.timers.map(({ ms }) => ms), [100, 100]);
    timers.runAll();
    assert.equal(document.querySelectorAll('[data-friend-toast]').length, 0);
});

// --- Panel reload --------------------------------------------------------------

test('refreshPanel swaps in the panel from the server response', async () => {
    const old = el('marker');
    old.textContent = 'old';
    const panel = el('friends-panel', [old]);
    const document = fakeDocument([panel]);
    const requests = [];

    const changed = await refreshPanel({
        document,
        fetch: async (url, options) => {
            requests.push({ url, options });
            return { ok: true, text: async () => 'html' };
        },
        parse: () => serverPanel('new'),
        url: '/friends?tab=pending',
    });

    assert.equal(changed, true);
    assert.equal(document.querySelector('[data-friends-panel]'), panel, 'the panel element itself stays');
    assert.equal(document.querySelector('[data-marker]').textContent, 'new');
    assert.equal(requests[0].url, '/friends?tab=pending');
    assert.equal(requests[0].options.headers.Accept, 'text/html');
});

test('refreshPanel leaves the panel alone on errors and off the friends page', async () => {
    const marker = el('marker');
    marker.textContent = 'old';
    const document = fakeDocument([el('friends-panel', [marker])]);
    const parse = () => serverPanel('new');

    assert.equal(await refreshPanel({ document, fetch: async () => ({ ok: false }), parse, url: '/friends' }), false);
    assert.equal(await refreshPanel({ document, fetch: async () => { throw new Error('offline'); }, parse, url: '/friends' }), false);
    assert.equal(await refreshPanel({ document, fetch: async () => ({ ok: true, text: async () => '' }), parse: () => fakeDocument(), url: '/friends' }), false);
    assert.equal(await refreshPanel({ document, fetch: async () => ({ ok: true, text: async () => '' }), parse, url: '/friends', isCurrent: () => false }), false);
    assert.equal(document.querySelector('[data-marker]').textContent, 'old');

    let fetched = false;
    const elsewhere = fakeDocument();
    assert.equal(await refreshPanel({ document: elsewhere, fetch: async () => { fetched = true; }, parse, url: '/dashboard' }), false);
    assert.equal(fetched, false, 'no request without a panel');
});

// --- initFriends -----------------------------------------------------------------

test('initFriends needs Echo and a signed-in user', () => {
    assert.equal(initFriends({ echo: null, userId: '1', document: fakeDocument() }), null);
    assert.equal(initFriends({ echo: fakeEcho(), userId: null, document: fakeDocument() }), null);
});

test("initFriends listens on the user's private channel", () => {
    const echo = fakeEcho();
    initFriends({ echo, userId: '42', document: fakeDocument(), location: { href: '/dashboard' } });

    assert.equal(echo.subscriptions.length, 1);
    assert.equal(echo.subscriptions[0].name, 'App.Models.User.42');
    assert.equal(typeof echo.subscriptions[0].listeners[FRIENDSHIP_EVENT], 'function');
    assert.equal(FRIENDSHIP_EVENT, '.friendship.changed');
});

test('an incoming request shows a toast and updates the badges outside the friends page', async () => {
    const echo = fakeEcho();
    const timers = fakeTimers();
    const counter = badge(0, true);
    const document = fakeDocument([counter]);
    let fetched = false;

    initFriends({
        echo,
        userId: '1',
        friendsUrl: '/friends',
        document,
        fetch: async () => { fetched = true; },
        parse: () => fakeDocument(),
        location: { href: '/dashboard' },
        setTimer: timers.setTimer,
    });

    await echo.subscriptions[0].listeners[FRIENDSHIP_EVENT]({ type: 'request', friendship_id: 3, user: alice, incoming_count: 1 });

    assert.deepEqual([counter.textContent, counter.hidden], ['1', false]);
    const toast = document.querySelector('[data-friend-toast]');
    assert.equal(toast.childNodes[0].textContent, 'Alice sent you a friend request.');
    assert.equal(toast.childNodes[1].href, '/friends?tab=pending');
    assert.equal(fetched, false, 'nothing to reload off the friends page');
});

test('a removal is silent but still updates the badges and the friends page', async () => {
    const echo = fakeEcho();
    const counter = badge(2);
    const marker = el('marker');
    marker.textContent = 'old';
    const document = fakeDocument([counter, el('friends-panel', [marker])]);
    const urls = [];

    initFriends({
        echo,
        userId: '1',
        friendsUrl: '/friends',
        document,
        fetch: async (url) => {
            urls.push(url);
            return { ok: true, text: async () => '' };
        },
        parse: () => serverPanel('fresh'),
        location: { href: '/friends?tab=pending' },
    });

    const changed = await echo.subscriptions[0].listeners[FRIENDSHIP_EVENT]({ type: 'removed', friendship_id: 3, user: alice, incoming_count: 1 });

    assert.equal(changed, true);
    assert.deepEqual([counter.textContent, counter.hidden], ['1', false]);
    assert.equal(document.querySelector('[data-friend-toast]'), null);
    assert.deepEqual(urls, ['/friends?tab=pending']);
    assert.equal(document.querySelector('[data-marker]').textContent, 'fresh');
});

test('when events arrive quickly only the newest reload is applied', async () => {
    const echo = fakeEcho();
    const marker = el('marker');
    marker.textContent = 'old';
    const document = fakeDocument([el('friends-panel', [marker])]);

    const responses = [];
    initFriends({
        echo,
        userId: '1',
        document,
        fetch: () => new Promise((resolve) => responses.push(resolve)),
        parse: (label) => serverPanel(label),
        location: { href: '/friends' },
        setTimer: () => {},
    });

    const listener = echo.subscriptions[0].listeners[FRIENDSHIP_EVENT];
    const first = listener({ type: 'request', user: alice, incoming_count: 1 });
    const second = listener({ type: 'removed', user: alice, incoming_count: 0 });

    // The newer response arrives first, the older one after it.
    responses[1]({ ok: true, text: async () => 'second' });
    assert.equal(await second, true);
    responses[0]({ ok: true, text: async () => 'first' });
    assert.equal(await first, false);

    assert.equal(document.querySelector('[data-marker]').textContent, 'second');
});

test('an accepted request links the toast to All and uses the configured toast time', async () => {
    const echo = fakeEcho();
    const timers = fakeTimers();
    const document = fakeDocument();

    initFriends({
        echo,
        userId: '1',
        friendsUrl: '/friends',
        document,
        location: { href: '/dashboard' },
        toastMs: 1234,
        setTimer: timers.setTimer,
    });
    await echo.subscriptions[0].listeners[FRIENDSHIP_EVENT]({ type: 'accepted', user: alice, incoming_count: 0 });

    const toast = document.querySelector('[data-friend-toast]');
    assert.equal(toast.childNodes[0].textContent, 'Alice accepted your friend request.');
    assert.equal(toast.childNodes[1].href, '/friends?tab=all');
    assert.deepEqual(timers.timers.map(({ ms }) => ms), [1234]);

    timers.runAll();
    assert.equal(document.querySelector('[data-friend-toast]'), null, 'the toast goes away');
});

test('a count that is not an integer leaves the badges alone', async () => {
    const echo = fakeEcho();
    const counter = badge(2);
    const document = fakeDocument([counter]);

    initFriends({ echo, userId: '1', document, location: { href: '/dashboard' }, setTimer: () => {} });
    const listener = echo.subscriptions[0].listeners[FRIENDSHIP_EVENT];

    await listener({ type: 'removed', user: alice, incoming_count: '5' });
    await listener({ type: 'removed', user: alice, incoming_count: 1.5 });
    await listener({ type: 'removed', user: alice, incoming_count: null });

    assert.deepEqual([counter.textContent, counter.hidden], ['2', false]);
});

test('updateRequestBadges treats non-integer counts as zero', () => {
    const counter = badge(3);
    const document = fakeDocument([counter]);

    updateRequestBadges(document, '3');
    assert.deepEqual([counter.textContent, counter.hidden], ['0', true]);

    updateRequestBadges(document, Number.NaN);
    assert.deepEqual([counter.textContent, counter.hidden], ['0', true]);
});

test('a malformed event shows no toast and does not throw', async () => {
    const echo = fakeEcho();
    const document = fakeDocument();

    initFriends({ echo, userId: '1', friendsUrl: '/friends', document, location: { href: '/dashboard' }, setTimer: () => {} });
    const listener = echo.subscriptions[0].listeners[FRIENDSHIP_EVENT];

    assert.equal(await listener(null), false);
    assert.equal(await listener({ type: 'request', user: { name: 42 } }), false);
    assert.equal(document.querySelector('[data-friend-toast]'), null);
});

test('an event without a count leaves the badges alone', async () => {
    const echo = fakeEcho();
    const counter = badge(4);
    const document = fakeDocument([counter]);

    initFriends({ echo, userId: '1', document, location: { href: '/dashboard' }, setTimer: () => {} });
    await echo.subscriptions[0].listeners[FRIENDSHIP_EVENT]({ type: 'accepted', user: alice });

    assert.equal(counter.textContent, '4');
    // No friends URL: the toast has no link.
    assert.equal(document.querySelector('[data-friend-toast]').childNodes.length, 1);
});
