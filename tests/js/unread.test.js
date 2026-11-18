// ISSUE-32: unread dots and mention badges, live. Run with `npm test`
// (node --test, no deps). The server side is covered by
// tests/Feature/Channels/UnreadTrackingTest.php; the script is checked here
// against a minimal fake DOM, a fake Echo, a fake post and fake timers.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    MARK_READ_DELAY_MS,
    MESSAGE_SENT,
    applyUnread,
    countsAsUnread,
    initUnread,
    mentionsUser,
} from '../../resources/js/unread.js';

// --- Fakes ---------------------------------------------------------------

const camel = (name) => name.replace(/-([a-z])/g, (_, c) => c.toUpperCase());

function fakeNode(dataset = {}, children = []) {
    const node = {
        dataset: { ...dataset },
        hidden: false,
        textContent: '',
        children,
        querySelector(selector) {
            return this.querySelectorAll(selector)[0] ?? null;
        },
        querySelectorAll(selector) {
            const key = camel(selector.match(/^\[data-([a-z-]+)\]$/)[1]);
            const found = [];
            const walk = (current) => current.children.forEach((child) => {
                if (key in child.dataset) found.push(child);
                walk(child);
            });
            walk(this);
            return found;
        },
    };
    return node;
}

function channelItem(id, { type = 'text', mentions = 0 } = {}) {
    const count = fakeNode({ mentionCount: '' });
    count.textContent = String(mentions);
    const label = fakeNode({ mentionLabel: '' });
    const badge = fakeNode({ mentionBadge: '' }, [count, label]);
    badge.hidden = mentions === 0;

    return fakeNode({ channel: '', channelId: String(id), channelType: type }, [badge]);
}

function fakePage({ items, openChannelId = null, blocked = [] }) {
    const sidebar = fakeNode({ channelSidebar: '', blockedUserIds: JSON.stringify(blocked) }, items);
    const panel = openChannelId === null
        ? null
        : fakeNode({ channelMessages: '', channelId: String(openChannelId), readUrl: `/read/${openChannelId}` });
    const listeners = {};

    return {
        visibilityState: 'visible',
        querySelector(selector) {
            if (selector === '[data-channel-sidebar]') return sidebar;
            if (selector === '[data-channel-messages][data-read-url]') return panel;
            return null;
        },
        addEventListener(type, listener) {
            listeners[type] = listener;
        },
        fire(type) {
            listeners[type]?.();
        },
    };
}

function fakeEcho() {
    const handlers = {};
    return {
        handlers,
        private(name) {
            return {
                listen(event, handler) {
                    handlers[`${name} ${event}`] = handler;
                },
            };
        },
        send(channelId, message) {
            handlers[`channel.${channelId} ${MESSAGE_SENT}`]?.(message);
        },
    };
}

function fakeTimers() {
    const pending = new Map();
    let next = 1;
    return {
        pending,
        setTimeout(fn, ms) {
            const id = next++;
            pending.set(id, { fn, ms });
            return id;
        },
        clearTimeout(id) {
            pending.delete(id);
        },
        runAll() {
            const due = [...pending.values()];
            pending.clear();
            due.forEach(({ fn }) => fn());
        },
    };
}

function setup({ items, openChannelId = null, blocked = [] }) {
    const document = fakePage({ items, openChannelId, blocked });
    const echo = fakeEcho();
    const timers = fakeTimers();
    const posts = [];
    const api = initUnread({
        echo,
        document,
        userId: '1',
        username: 'bob',
        post: (url, data) => {
            posts.push({ url, data });
            return Promise.resolve();
        },
        setTimeout: timers.setTimeout,
        clearTimeout: timers.clearTimeout,
    });

    return { api, document, echo, timers, posts };
}

const message = (id, content = 'hi', authorId = 2) => ({ id, content, author: authorId === null ? null : { id: authorId } });

// --- mentionsUser ---------------------------------------------------------

test('mentionsUser matches App\\Support\\Mentions', () => {
    const cases = [
        ['@bob', true],
        ['hi @bob how are you', true],
        ['@Bob', true],
        ['see you @bob.', true],
        ['(@bob), @bob!', true],
        ['@bobby', false],
        ['@bob.smith', false],
        ['bob@bob', false],
        ['@@bob', false],
        ['bob', false],
    ];

    for (const [content, expected] of cases) {
        assert.equal(mentionsUser(content, 'bob'), expected, content);
    }
});

test('mentionsUser treats regex characters in usernames literally', () => {
    assert.equal(mentionsUser('@a.b', 'a.b'), true);
    assert.equal(mentionsUser('@axb', 'a.b'), false);
    assert.equal(mentionsUser('@bob', ''), false);
    assert.equal(mentionsUser(null, 'bob'), false);
});

// --- countsAsUnread -------------------------------------------------------

test('your own and blocked messages do not count; deleted accounts do', () => {
    const blockedUserIds = new Set(['3']);

    assert.equal(countsAsUnread(message(1, 'x', 2), { userId: '1', blockedUserIds }), true);
    assert.equal(countsAsUnread(message(1, 'x', 1), { userId: '1', blockedUserIds }), false);
    assert.equal(countsAsUnread(message(1, 'x', 3), { userId: '1', blockedUserIds }), false);
    assert.equal(countsAsUnread(message(1, 'x', null), { userId: '1', blockedUserIds }), true);
    assert.equal(countsAsUnread(null, { userId: '1' }), false);
});

// --- applyUnread ----------------------------------------------------------

test('applyUnread sets data-unread and counts mentions up', () => {
    const item = channelItem(5, { mentions: 1 });
    const badge = item.querySelector('[data-mention-badge]');

    applyUnread(item);
    assert.ok('unread' in item.dataset);
    assert.equal(badge.querySelector('[data-mention-count]').textContent, '1');

    applyUnread(item, { mention: true });
    assert.equal(badge.querySelector('[data-mention-count]').textContent, '2');
    assert.equal(badge.querySelector('[data-mention-label]').textContent, ' mentions');
    assert.equal(badge.hidden, false);
});

test('a first mention shows the badge with a singular label', () => {
    const item = channelItem(5);
    applyUnread(item, { mention: true });

    const badge = item.querySelector('[data-mention-badge]');
    assert.equal(badge.hidden, false);
    assert.equal(badge.querySelector('[data-mention-label]').textContent, ' mention');
});

// --- initUnread -------------------------------------------------------------

test('subscribes to every text channel in the sidebar, not voice', () => {
    const { echo } = setup({ items: [channelItem(1), channelItem(2), channelItem(3, { type: 'voice' })] });

    assert.deepEqual(Object.keys(echo.handlers).sort(), [
        `channel.1 ${MESSAGE_SENT}`,
        `channel.2 ${MESSAGE_SENT}`,
    ]);
});

test('without Echo or a sidebar it does nothing', () => {
    assert.equal(initUnread({ echo: null, document: fakePage({ items: [] }), post: () => {} }), null);
    assert.equal(initUnread({ echo: fakeEcho(), document: { querySelector: () => null }, post: () => {} }), null);
});

test('a message in another channel marks it unread, a mention counts', () => {
    const other = channelItem(2);
    const { echo, posts } = setup({ items: [channelItem(1), other], openChannelId: 1 });

    echo.send(2, message(10, 'hello'));
    assert.ok('unread' in other.dataset);
    assert.equal(other.querySelector('[data-mention-badge]').hidden, true);

    echo.send(2, message(11, 'hey @bob'));
    assert.equal(other.querySelector('[data-mention-count]').textContent, '1');
    assert.equal(posts.length, 0);
});

test('your own and blocked messages leave the sidebar alone', () => {
    const other = channelItem(2);
    const { echo } = setup({ items: [other], blocked: [3] });

    echo.send(2, message(10, '@bob', 1));
    echo.send(2, message(11, '@bob', 3));

    assert.equal('unread' in other.dataset, false);
    assert.equal(other.querySelector('[data-mention-badge]').hidden, true);
});

test('messages in the open channel are marked read, batched', () => {
    const open = channelItem(1);
    const { echo, timers, posts } = setup({ items: [open], openChannelId: 1 });

    echo.send(1, message(10));
    echo.send(1, message(12));
    echo.send(1, message(11));

    assert.equal(timers.pending.size, 1);
    assert.equal([...timers.pending.values()][0].ms, MARK_READ_DELAY_MS);
    timers.runAll();

    assert.deepEqual(posts, [{ url: '/read/1', data: { message_id: 12 } }]);
    assert.equal('unread' in open.dataset, false);
});

test('your own messages in the open channel are not posted', () => {
    const { echo, timers, posts } = setup({ items: [channelItem(1)], openChannelId: 1 });

    echo.send(1, message(10, 'mine', 1));
    timers.runAll();

    assert.equal(posts.length, 0);
});

test('a hidden tab waits until it is visible again', () => {
    const { document, echo, timers, posts } = setup({ items: [channelItem(1)], openChannelId: 1 });

    document.visibilityState = 'hidden';
    echo.send(1, message(10));
    assert.equal(timers.pending.size, 0);

    document.visibilityState = 'visible';
    document.fire('visibilitychange');
    timers.runAll();

    assert.deepEqual(posts, [{ url: '/read/1', data: { message_id: 10 } }]);
});

test('hiding the tab before the batch is sent cancels it until you are back', () => {
    const { document, echo, timers, posts } = setup({ items: [channelItem(1)], openChannelId: 1 });

    echo.send(1, message(10));
    document.visibilityState = 'hidden';
    document.fire('visibilitychange');
    assert.equal(timers.pending.size, 0);

    document.visibilityState = 'visible';
    document.fire('visibilitychange');
    timers.runAll();

    assert.deepEqual(posts, [{ url: '/read/1', data: { message_id: 10 } }]);
});

test('a failed request does not throw', async () => {
    const document = fakePage({ items: [channelItem(1)], openChannelId: 1 });
    const echo = fakeEcho();
    const timers = fakeTimers();
    const api = initUnread({
        echo,
        document,
        userId: '1',
        username: 'bob',
        post: () => Promise.reject(new Error('offline')),
        setTimeout: timers.setTimeout,
        clearTimeout: timers.clearTimeout,
    });

    api.markRead(10);
    await assert.doesNotReject(async () => {
        timers.pending.clear();
        await api.flush();
    });
});
