// ISSUE-33: the notification inbox (bell and panel), live mention badges
// from the payload's `mentions`, and highlighting messages that mention
// you. Run with `npm test` (node --test, no deps). The server side is
// covered by tests/Feature/Notifications; the scripts are checked here
// against a fake DOM, a fake Echo and fake requests.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    NOTIFICATION_TYPE,
    badgeText,
    initInbox,
    markRowRead,
    renderNotification,
} from '../../resources/js/notifications.js';
import { mentionsViewer } from '../../resources/js/unread.js';
import { renderMessage } from '../../resources/js/channel-messages.js';
import { fakeDocument, make, textOf } from './helpers/fake-dom.js';

const item = (overrides = {}) => ({
    id: 'n-1',
    read: false,
    created_at: '2026-11-19T10:00:00+00:00',
    reason: 'user',
    label: 'mentioned you',
    author_name: 'Alice <script>',
    avatar_url: 'data:image/png;base64,x',
    server_name: 'Pings',
    channel_name: 'general',
    excerpt: 'hey @bob <img src=x>',
    channel_id: 5,
    message_id: 9,
    url: '/servers/1/channels/5#message-9',
    open_url: '/notifications/n-1',
    ...overrides,
});

function fakeEcho() {
    const handlers = {};
    return {
        handlers,
        private(name) {
            return {
                notification(callback) {
                    handlers[name] = callback;
                    return this;
                },
            };
        },
        send(name, notification) {
            handlers[name]?.(notification);
        },
    };
}

function inboxPage({ unread = 0 } = {}) {
    const count = make('span', { inboxCount: '' });
    count.textContent = badgeText(unread);
    const badge = make('span', { inboxBadge: '' }, [count]);
    badge.hidden = unread === 0;
    const toggle = make('a', { inboxToggle: '' }, [badge]);
    const status = make('p', { inboxStatus: '' });
    const list = make('ul', { inboxList: '' });
    const readAll = make('button', { inboxReadAll: '' });
    const close = make('button', { inboxClose: '' });
    const panel = make('div', { inboxPanel: '' }, [readAll, close, status, list]);
    panel.hidden = true;
    const root = make('div', {
        inbox: '',
        inboxUrl: '/notifications',
        readAllUrl: '/notifications/read',
        unreadCount: String(unread),
    }, [toggle, panel]);

    return { root, toggle, badge, count, panel, list, status, readAll, close };
}

function setup({ unread = 0, items = [], openChannelId = null, visible = true, fail = false } = {}) {
    const page = inboxPage({ unread });
    const doc = fakeDocument(make('body', {}, [page.root]));
    const echo = fakeEcho();
    const gets = [];
    const posts = [];
    const handle = initInbox({
        echo,
        document: doc,
        userId: '7',
        get: (url) => {
            gets.push(url);
            return fail ? Promise.reject(new Error('down')) : Promise.resolve({ notifications: items, unread_count: unread });
        },
        post: (url) => {
            posts.push(url);
            return Promise.resolve({ unread_count: 0 });
        },
        openChannelId,
        isVisible: () => visible,
    });

    const click = (node, extra = {}) => {
        const event = { target: node, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra };
        node.listeners.click?.forEach((handler) => handler(event));
        return event;
    };

    return { ...page, doc, echo, gets, posts, handle, click };
}

// --- Helpers ----------------------------------------------------------------------

test('badgeText matches NotificationInbox::badge()', () => {
    assert.equal(badgeText(0), '');
    assert.equal(badgeText(-2), '');
    assert.equal(badgeText(3), '3');
    assert.equal(badgeText(99), '99');
    assert.equal(badgeText(100), '99+');
    assert.equal(badgeText('nope'), '');
});

test('renderNotification builds the entry from text only', () => {
    const row = renderNotification(item(), fakeDocument());

    assert.equal(row.dataset.notificationId, 'n-1');
    assert.ok('unread' in row.dataset);
    assert.equal(row.querySelector('[data-notification-link]').href, '/notifications/n-1');
    assert.equal(textOf(row.querySelector('[data-notification-author]')), 'Alice <script>');
    assert.equal(textOf(row.querySelector('[data-notification-label]')), 'mentioned you');
    assert.equal(textOf(row.querySelector('[data-notification-excerpt]')), 'hey @bob <img src=x>');
    assert.match(textOf(row.querySelector('[data-notification-where]')), /^Pings · #general/);
    assert.ok(row.querySelector('[data-notification-dot]'));
});

test('a read entry has no dot; markRowRead takes the dot away', () => {
    const read = renderNotification(item({ read: true, avatar_url: null }), fakeDocument());
    assert.equal('unread' in read.dataset, false);
    assert.equal(read.querySelector('[data-notification-dot]'), null);

    const unread = renderNotification(item(), fakeDocument());
    markRowRead(unread);
    assert.equal('unread' in unread.dataset, false);
    assert.equal(unread.querySelector('[data-notification-dot]'), null);
});

// --- initInbox ----------------------------------------------------------------------

test('without the bell it does nothing', () => {
    assert.equal(initInbox({ echo: fakeEcho(), document: fakeDocument(), userId: '1', get: () => {}, post: () => {} }), null);
});

test('the bell opens the panel and loads the inbox once', async () => {
    const { toggle, panel, list, status, gets, click, handle } = setup({ unread: 1, items: [item(), item({ id: 'n-2', read: true })] });

    const event = click(toggle);
    assert.equal(event.defaultPrevented, true);
    assert.equal(panel.hidden, false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    await handle.load();

    assert.deepEqual(gets, ['/notifications']);
    assert.equal(list.children.length, 2);
    assert.equal(status.hidden, true);

    click(toggle);
    assert.equal(panel.hidden, true);
    click(toggle);
    await Promise.resolve();
    assert.deepEqual(gets, ['/notifications']);
});

test('ctrl-click keeps the link to the inbox page', () => {
    const { toggle, panel, click } = setup();

    const event = click(toggle, { ctrlKey: true });

    assert.equal(event.defaultPrevented, false);
    assert.equal(panel.hidden, true);
});

test('an empty inbox and a failed load say so', async () => {
    const empty = setup();
    await empty.handle.open();
    assert.match(empty.status.textContent, /No mentions yet/);
    assert.equal(empty.status.hidden, false);

    const failing = setup({ fail: true });
    await failing.handle.open();
    assert.match(failing.status.textContent, /could not be loaded/);
});

test('the close button, Escape and a click elsewhere close the panel', async () => {
    const { panel, close, click, doc, handle } = setup();

    await handle.open();
    click(close);
    assert.equal(panel.hidden, true);

    await handle.open();
    const escape = { key: 'Escape', preventDefault() {} };
    panel.listeners.keydown.forEach((handler) => handler(escape));
    assert.equal(panel.hidden, true);

    await handle.open();
    doc.dispatch('click', { target: panel });
    assert.equal(panel.hidden, false);
    doc.dispatch('click', { target: make('div') });
    assert.equal(panel.hidden, true);
});

test('opening moves focus into the panel and Tab stays in it (ISSUE-38)', async () => {
    const { panel, readAll, list, doc, handle } = setup({ items: [item()] });
    for (const node of [panel, readAll]) {
        node.focused = 0;
        node.focus = () => { node.focused += 1; doc.activeElement = node; };
    }

    await handle.open();
    assert.equal(panel.getAttribute('tabindex'), '-1');
    assert.equal(panel.focused, 1);

    const press = (extra = {}) => {
        const event = { key: 'Tab', shiftKey: false, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra };
        panel.listeners.keydown.forEach((handler) => handler(event));
        return event;
    };

    // From the panel itself, Tab goes to "Mark all read".
    assert.equal(press().defaultPrevented, true);
    assert.equal(readAll.focused, 1);

    // From the entry's link (the last stop) back to the top.
    const entryLink = list.children[0].tagName === 'A' ? list.children[0] : list.children[0].children.find((child) => child.tagName === 'A');
    doc.activeElement = entryLink;
    assert.equal(press().defaultPrevented, true);
    assert.equal(readAll.focused, 2);

    // Shift+Tab on the first goes to the last.
    doc.activeElement = readAll;
    let wrapped = 0;
    entryLink.focus = () => { wrapped += 1; };
    assert.equal(press({ shiftKey: true }).defaultPrevented, true);
    assert.equal(wrapped, 1);

    // In between, the browser moves on.
    doc.activeElement = readAll;
    assert.equal(press().defaultPrevented, false);
});

test('mark all read clears the count and the dots', async () => {
    const { readAll, posts, list, badge, click, handle } = setup({ unread: 2, items: [item(), item({ id: 'n-2' })] });
    await handle.open();

    click(readAll);
    await handle.markAllRead();

    assert.deepEqual(posts, ['/notifications/read', '/notifications/read']);
    assert.equal(handle.count(), 0);
    assert.equal(badge.hidden, true);
    assert.equal(list.querySelectorAll('[data-unread]').length, 0);
});

test('a live mention counts up and joins a loaded list at the top', async () => {
    const { echo, badge, count, list, handle } = setup({ items: [item({ id: 'old', read: true })] });

    echo.send('App.Models.User.7', { ...item({ id: 'live-1' }), type: NOTIFICATION_TYPE });
    assert.equal(handle.count(), 1);
    assert.equal(count.textContent, '1');
    assert.equal(badge.hidden, false);

    // Loading takes the server's count (0 in this fake), then counts on.
    await handle.open();
    assert.equal(handle.count(), 0);
    echo.send('App.Models.User.7', { ...item({ id: 'live-2' }), type: NOTIFICATION_TYPE });
    assert.equal(handle.count(), 1);
    assert.equal(list.children[0].dataset.notificationId, 'live-2');
});

test('other notification types are ignored', () => {
    const { echo, handle } = setup();

    echo.send('App.Models.User.7', { ...item(), type: 'App\\Notifications\\Other' });
    echo.send('App.Models.User.7', null);

    assert.equal(handle.count(), 0);
});

test('a mention in the channel you are looking at does not count', async () => {
    const watching = setup({ openChannelId: '5' });
    await watching.handle.open();
    watching.echo.send('App.Models.User.7', { ...item(), type: NOTIFICATION_TYPE });
    assert.equal(watching.handle.count(), 0);
    assert.equal('unread' in watching.list.children[0].dataset, false);

    const hiddenTab = setup({ openChannelId: '5', visible: false });
    hiddenTab.echo.send('App.Models.User.7', { ...item(), type: NOTIFICATION_TYPE });
    assert.equal(hiddenTab.handle.count(), 1);

    const elsewhere = setup({ openChannelId: '6' });
    elsewhere.echo.send('App.Models.User.7', { ...item(), type: NOTIFICATION_TYPE });
    assert.equal(elsewhere.handle.count(), 1);
});

test('the count starts from the page and is never negative', () => {
    const { handle, count } = setup({ unread: 120 });

    assert.equal(handle.count(), 120);
    handle.setCount(-3);
    assert.equal(handle.count(), 0);
    assert.equal(count.textContent, '');
});

// --- mentionsViewer (unread.js) ---------------------------------------------------------

test('mentionsViewer reads the payload: by id, a role you hold or @everyone', () => {
    const roles = new Set(['4']);

    assert.equal(mentionsViewer({ mentions: { users: [7], roles: [], everyone: false } }, { userId: '7' }), true);
    assert.equal(mentionsViewer({ mentions: { users: ['8'], roles: [], everyone: false } }, { userId: 7 }), false);
    assert.equal(mentionsViewer({ mentions: { users: [], roles: [4], everyone: false } }, { userId: '7', roleIds: roles }), true);
    assert.equal(mentionsViewer({ mentions: { users: [], roles: [5], everyone: false } }, { userId: '7', roleIds: roles }), false);
    assert.equal(mentionsViewer({ mentions: { users: [], roles: [], everyone: true } }, { userId: '7' }), true);
});

test('mentionsViewer trusts a null mentions, and only guesses without the key', () => {
    assert.equal(mentionsViewer({ content: '@bob', mentions: null }, { userId: '7', username: 'bob' }), false);
    assert.equal(mentionsViewer({ content: '@bob' }, { userId: '7', username: 'bob' }), true);
    assert.equal(mentionsViewer(null, { userId: '7' }), false);
});

// --- renderMessage highlight ---------------------------------------------------------------

function withFakeDocument(fn) {
    const previous = globalThis.document;
    globalThis.document = fakeDocument();
    try {
        return fn();
    } finally {
        if (previous === undefined) delete globalThis.document;
        else globalThis.document = previous;
    }
}

const message = (overrides = {}) => ({
    id: 1,
    content: '@bob hi',
    created_at: '2026-11-19T10:00:00+00:00',
    author: { id: 3, name: 'Alice', username: 'alice', avatar_url: 'x', profile_url: '/u/alice' },
    reactions: [],
    attachments: [],
    reply_to: null,
    ...overrides,
});

test('a live message that mentions you is highlighted like the server renders it', () => withFakeDocument(() => {
    const context = { viewerId: '7', viewerRoleIds: new Set(['4']) };

    const byName = renderMessage(message({ mentions: { users: [7], roles: [], everyone: false } }), context);
    assert.ok('mentionsViewer' in byName.dataset);
    assert.match(byName.className, /bg-\[#f0b232\]\/10/);

    const byRole = renderMessage(message({ mentions: { users: [], roles: [4], everyone: false } }), context);
    assert.ok('mentionsViewer' in byRole.dataset);

    const notYou = renderMessage(message({ mentions: null }), context);
    assert.equal('mentionsViewer' in notYou.dataset, false);

    const own = renderMessage(message({ author: { id: 7, name: 'Bob' }, mentions: { users: [], roles: [], everyone: true } }), context);
    assert.equal('mentionsViewer' in own.dataset, false);
}));
