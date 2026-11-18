// ISSUE-23: 1:1 direct messages. Run with `npm test` (node --test, no deps).
// The Blade markup is covered by tests/Feature/DirectMessages; here the
// script parts: matching events to a DM panel, and reloading the DM list.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { belongsToTarget } from '../../resources/js/channel-messages.js';
import { DM_ACTIVITY_EVENT, initDirectMessages, refreshDmList } from '../../resources/js/direct-messages.js';

// --- belongsToTarget() ---------------------------------------------------

test('a channel panel takes payloads of its channel only', () => {
    const target = { channelId: '7', conversationId: null };

    assert.equal(belongsToTarget({ channel_id: 7, conversation_id: null }, target), true);
    assert.equal(belongsToTarget({ channel_id: 8, conversation_id: null }, target), false);
    // A DM payload never lands in a channel, also with the same number.
    assert.equal(belongsToTarget({ channel_id: null, conversation_id: 7 }, target), false);
    assert.equal(belongsToTarget(null, target), false);
});

test('a DM panel takes payloads of its conversation only', () => {
    const target = { channelId: undefined, conversationId: '12' };

    assert.equal(belongsToTarget({ channel_id: null, conversation_id: 12 }, target), true);
    assert.equal(belongsToTarget({ channel_id: null, conversation_id: 13 }, target), false);
    assert.equal(belongsToTarget({ channel_id: 12, conversation_id: null }, target), false);
    assert.equal(belongsToTarget({ id: 1 }, target), false);
});

test('a panel without a target takes nothing', () => {
    assert.equal(belongsToTarget({ channel_id: null, conversation_id: null }, {}), false);
});

// --- Fake DOM for the DM list ---------------------------------------------

function fakeList(children = []) {
    return {
        childNodes: [...children],
        replaceChildren(...nodes) {
            this.childNodes = nodes;
        },
    };
}

function fakeDocument(list) {
    return {
        querySelector: (selector) => (selector === '[data-dm-list]' ? list : null),
    };
}

function htmlResponse(body, ok = true) {
    return { ok, text: async () => body };
}

/** parse() returns a document whose [data-dm-list] holds the given names. */
const parseNames = (html) => fakeDocument(html === 'none' ? null : fakeList(html.split(',')));

// --- refreshDmList() ----------------------------------------------------

test('reloads the list from the page', async () => {
    const list = fakeList(['Bob', 'Carol']);
    const requests = [];
    const fetch = async (url, options) => {
        requests.push([url, options]);
        return htmlResponse('Carol,Bob');
    };

    const replaced = await refreshDmList({ document: fakeDocument(list), fetch, parse: parseNames, url: '/friends' });

    assert.equal(replaced, true);
    assert.deepEqual(list.childNodes, ['Carol', 'Bob']);
    assert.equal(requests[0][0], '/friends');
    assert.deepEqual(requests[0][1], { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
});

test('pages without a DM list fetch nothing', async () => {
    let fetched = false;
    const replaced = await refreshDmList({
        document: fakeDocument(null),
        fetch: async () => { fetched = true; return htmlResponse(''); },
        parse: parseNames,
        url: '/servers/1',
    });

    assert.equal(replaced, false);
    assert.equal(fetched, false);
});

test('a failed or odd response leaves the list alone', async () => {
    for (const fetch of [
        async () => htmlResponse('Carol', false),
        async () => htmlResponse('none'),
        async () => { throw new Error('offline'); },
    ]) {
        const list = fakeList(['Bob']);
        assert.equal(await refreshDmList({ document: fakeDocument(list), fetch, parse: parseNames, url: '/' }), false);
        assert.deepEqual(list.childNodes, ['Bob']);
    }
});

// --- initDirectMessages() ------------------------------------------------

function fakeEcho() {
    const listeners = {};
    const echo = {
        subscribed: null,
        private(name) {
            echo.subscribed = name;
            return { listen(event, callback) { listeners[event] = callback; return this; } };
        },
    };
    return { echo, listeners };
}

test('listens for conversation activity on the user channel', () => {
    const { echo, listeners } = fakeEcho();

    const handle = initDirectMessages({ echo, userId: '5', document: fakeDocument(fakeList()), fetch: async () => htmlResponse(''), parse: parseNames, location: { href: '/' } });

    assert.equal(echo.subscribed, 'App.Models.User.5');
    assert.equal(listeners[DM_ACTIVITY_EVENT], handle);
    assert.equal(DM_ACTIVITY_EVENT, '.conversation.activity');
});

test('does nothing without Echo or a signed-in user', () => {
    assert.equal(initDirectMessages({ echo: undefined, userId: '5' }), null);
    assert.equal(initDirectMessages({ echo: fakeEcho().echo, userId: null }), null);
});

test('only the newest of quick reloads is applied', async () => {
    const list = fakeList(['Old']);
    const pending = [];
    const fetch = () => new Promise((resolve) => pending.push(resolve));
    const handle = initDirectMessages({ echo: fakeEcho().echo, userId: '5', document: fakeDocument(list), fetch, parse: parseNames, location: { href: '/dms/1' } });

    const first = handle({ conversation_id: 1 });
    const second = handle({ conversation_id: 2 });

    // The newer response arrives first, then the older one.
    pending[1](htmlResponse('Newest'));
    assert.equal(await second, true);
    pending[0](htmlResponse('Stale'));
    assert.equal(await first, false);

    assert.deepEqual(list.childNodes, ['Newest']);
});

test('ignores events without a conversation id', async () => {
    let fetched = false;
    const handle = initDirectMessages({
        echo: fakeEcho().echo,
        userId: '5',
        document: fakeDocument(fakeList()),
        fetch: async () => { fetched = true; return htmlResponse(''); },
        parse: parseNames,
        location: { href: '/' },
    });

    assert.equal(await handle({}), false);
    assert.equal(await handle(null), false);
    assert.equal(fetched, false);
});
