// ISSUE-24: group DMs. Run with `npm test` (node --test, no deps).
// The Blade markup is covered by tests/Feature/DirectMessages; here the
// live part: reloading the open group page when ConversationUpdated arrives.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { DM_ACTIVITY_EVENT, DM_UPDATED_EVENT, initDirectMessages, refreshGroup } from '../../resources/js/direct-messages.js';

// --- Fake DOM -------------------------------------------------------------

function fakeNode(children = [], extra = {}) {
    return {
        childNodes: [...children],
        replaceChildren(...nodes) {
            this.childNodes = nodes;
        },
        ...extra,
    };
}

/**
 * A page with an optional group block (conversation id, settings open or
 * not) and an optional DM list. The settings <details> is a child node of
 * the group block, so replacing the children replaces it too.
 */
function fakePage({ groupId = null, open = false, groupChildren = ['old header'], list = ['old list'] } = {}) {
    const settings = { open };
    const group = groupId === null ? null : fakeNode([...groupChildren, settings], {
        getAttribute: (name) => (name === 'data-conversation-id' ? String(groupId) : null),
        querySelector: (selector) => (selector === 'details' ? group.childNodes.find((n) => typeof n === 'object' && 'open' in n) ?? null : null),
    });
    const dmList = list === null ? null : fakeNode(list);

    return {
        group,
        dmList,
        querySelector: (selector) => ({ '[data-group-details]': group, '[data-dm-list]': dmList })[selector] ?? null,
    };
}

function response(status, body = '') {
    return { ok: status >= 200 && status < 300, status, text: async () => body };
}

function fakeLocation(href = '/dms/5') {
    return { href, assigned: [], assign(url) { this.assigned.push(url); } };
}

// The server's answer is described by the body: "group" for a fresh group
// page, anything else for a page without a group block.
const parse = (html) => (html === 'group'
    ? fakePage({ groupId: 5, groupChildren: ['new header'], list: ['new list'] })
    : fakePage({ list: ['new list'] }));

// --- refreshGroup() -------------------------------------------------------

test('on the group page, the header, settings and DM list are reloaded', async () => {
    const document = fakePage({ groupId: 5, open: true });
    const location = fakeLocation();
    const requests = [];
    const fetch = async (url, options) => { requests.push([url, options]); return response(200, 'group'); };

    const result = await refreshGroup({ document, fetch, parse, location, friendsUrl: '/friends', conversationId: 5 });

    assert.equal(result, 'refreshed');
    assert.equal(document.group.childNodes[0], 'new header');
    assert.deepEqual(document.dmList.childNodes, ['new list']);
    // The settings stay open after the reload.
    assert.equal(document.group.querySelector('details').open, true);
    assert.deepEqual(requests, [['/dms/5', { headers: { Accept: 'text/html' }, credentials: 'same-origin' }]]);
    assert.deepEqual(location.assigned, []);
});

test('closed settings stay closed', async () => {
    const document = fakePage({ groupId: 5, open: false });

    await refreshGroup({ document, fetch: async () => response(200, 'group'), parse, location: fakeLocation(), friendsUrl: '/friends', conversationId: 5 });

    assert.equal(document.group.querySelector('details').open, false);
});

test('someone taken out of the open group goes to the friends page', async () => {
    for (const status of [404, 403]) {
        const document = fakePage({ groupId: 5 });
        const location = fakeLocation();

        const result = await refreshGroup({ document, fetch: async () => response(status), parse, location, friendsUrl: '/friends', conversationId: 5 });

        assert.equal(result, 'left');
        assert.deepEqual(location.assigned, ['/friends']);
        assert.equal(document.group.childNodes[0], 'old header');
    }
});

test('another group or page only reloads the DM list', async () => {
    for (const document of [fakePage({ groupId: 9 }), fakePage()]) {
        const location = fakeLocation('/friends');

        const result = await refreshGroup({ document, fetch: async () => response(200, 'list'), parse, location, friendsUrl: '/friends', conversationId: 5 });

        assert.equal(result, 'list');
        assert.deepEqual(document.dmList.childNodes, ['new list']);
        assert.deepEqual(location.assigned, []);
    }
});

test('a server error or network failure leaves the page alone', async () => {
    for (const fetch of [async () => response(500), async () => { throw new Error('offline'); }]) {
        const document = fakePage({ groupId: 5 });
        const location = fakeLocation();

        assert.equal(await refreshGroup({ document, fetch, parse, location, friendsUrl: '/friends', conversationId: 5 }), 'list');
        assert.equal(document.group.childNodes[0], 'old header');
        assert.deepEqual(location.assigned, []);
    }
});

test('a stale answer is not applied', async () => {
    const document = fakePage({ groupId: 5 });
    const location = fakeLocation();

    await refreshGroup({ document, fetch: async () => response(200, 'group'), parse, location, friendsUrl: '/friends', conversationId: 5, isCurrent: () => false });
    await refreshGroup({ document, fetch: async () => response(404), parse, location, friendsUrl: '/friends', conversationId: 5, isCurrent: () => false });

    assert.equal(document.group.childNodes[0], 'old header');
    assert.deepEqual(location.assigned, []);
});

// --- initDirectMessages() -------------------------------------------------

test('listens for group updates next to activity on the user channel', async () => {
    const listeners = {};
    const echo = {
        private(name) {
            assert.equal(name, 'App.Models.User.3');
            return { listen(event, callback) { listeners[event] = callback; return this; } };
        },
    };
    const document = fakePage({ groupId: 5 });
    const location = fakeLocation();

    const handle = initDirectMessages({ echo, userId: '3', document, fetch: async () => response(404), parse, location, friendsUrl: '/friends' });

    assert.equal(listeners[DM_ACTIVITY_EVENT], handle);
    assert.equal(listeners[DM_UPDATED_EVENT], handle.updated);
    assert.equal(await listeners[DM_UPDATED_EVENT]({ conversation_id: 'x' }), false);
    assert.equal(await listeners[DM_UPDATED_EVENT]({ conversation_id: 5 }), 'left');
    assert.deepEqual(location.assigned, ['/friends']);
});
