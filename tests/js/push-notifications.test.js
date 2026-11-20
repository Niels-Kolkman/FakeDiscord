// Browser notifications for mentions and DMs while the tab is
// not in focus, and the switch on the settings page. Run with `npm test`
// (node --test, no deps). The server side (mutes, `push`, the DM event) is
// covered by tests/Feature/Notifications/PushNotificationsTest.php; here
// the script runs against a fake Notification API, Echo, storage and DOM.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    DM_EVENT,
    MENTION_TYPE,
    OPT_OUT_KEY,
    dmNotification,
    initPushNotifications,
    initPushSettings,
    mentionNotification,
    pushState,
    tabUnfocused,
} from '../../resources/js/push-notifications.js';
import { fakeDocument, make } from './helpers/fake-dom.js';

function fakeNotificationApi(permission = 'granted', { answer = 'granted', throws = false } = {}) {
    const shown = [];

    class FakeNotification {
        static permission = permission;

        static requestPermission() {
            FakeNotification.asked += 1;
            FakeNotification.permission = answer;
            return Promise.resolve(answer);
        }

        constructor(title, options) {
            if (throws) throw new TypeError('Illegal constructor');
            this.title = title;
            this.options = options;
            this.closed = false;
            shown.push(this);
        }

        close() {
            this.closed = true;
        }
    }

    FakeNotification.asked = 0;
    FakeNotification.shown = shown;
    return FakeNotification;
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

const brokenStorage = {
    getItem() { throw new Error('SecurityError'); },
    setItem() { throw new Error('SecurityError'); },
    removeItem() { throw new Error('SecurityError'); },
};

function fakeEcho() {
    const channels = {};
    return {
        channels,
        private(name) {
            const channel = (channels[name] ??= { notification: null, listeners: {} });
            const api = {
                notification(callback) {
                    channel.notification = callback;
                    return api;
                },
                listen(event, callback) {
                    channel.listeners[event] = callback;
                    return api;
                },
            };
            return api;
        },
    };
}

function unfocusedDocument() {
    const doc = fakeDocument();
    doc.visibilityState = 'hidden';
    return doc;
}

const mention = (overrides = {}) => ({
    type: MENTION_TYPE,
    id: 'n-1',
    push: true,
    label: 'mentioned you',
    author_name: 'Alice',
    avatar_url: '/avatars/alice.png',
    server_name: 'Pings',
    channel_name: 'general',
    excerpt: 'hey @bob',
    channel_id: 5,
    message_id: 9,
    url: '/servers/1/channels/5#message-9',
    open_url: '/notifications/n-1',
    ...overrides,
});

const dm = (overrides = {}) => ({
    conversation_id: 3,
    message_id: 12,
    is_group: false,
    title: 'Alice',
    author_name: 'Alice',
    avatar_url: '/avatars/alice.png',
    excerpt: 'Lunch tomorrow?',
    has_attachments: false,
    url: '/dms/3#message-12',
    ...overrides,
});

function setup({ permission = 'granted', doc = unfocusedDocument(), storage = fakeStorage(), status = 'online', throws = false } = {}) {
    const Notification = fakeNotificationApi(permission, { throws });
    const echo = fakeEcho();
    const navigated = [];
    let focused = 0;
    const handle = initPushNotifications({
        echo,
        userId: '7',
        document: doc,
        Notification,
        storage,
        getStatus: () => status,
        navigate: (url) => navigated.push(url),
        focus: () => { focused += 1; },
    });
    const channel = echo.channels['App.Models.User.7'];

    return { Notification, echo, handle, channel, navigated, focused: () => focused, doc, storage };
}

// --- State and focus -------------------------------------------------------------

test('pushState reads the permission and the opt-out', () => {
    assert.equal(pushState(null, fakeStorage()), 'unsupported');
    assert.equal(pushState(fakeNotificationApi('default'), fakeStorage()), 'default');
    assert.equal(pushState(fakeNotificationApi('denied'), fakeStorage()), 'denied');
    assert.equal(pushState(fakeNotificationApi('granted'), fakeStorage()), 'on');
    assert.equal(pushState(fakeNotificationApi('granted'), fakeStorage({ [OPT_OUT_KEY]: '1' })), 'off');
    // The opt-out only counts once allowed; denied wins over it.
    assert.equal(pushState(fakeNotificationApi('denied'), fakeStorage({ [OPT_OUT_KEY]: '1' })), 'denied');
});

test('pushState treats storage that throws as not opted out', () => {
    assert.equal(pushState(fakeNotificationApi('granted'), brokenStorage), 'on');
    assert.equal(pushState(fakeNotificationApi('granted'), null), 'on');
});

test('tabUnfocused: hidden, or visible without focus', () => {
    const doc = fakeDocument();
    assert.equal(tabUnfocused(doc), false);

    doc.hasFocus = () => true;
    assert.equal(tabUnfocused(doc), false);

    doc.hasFocus = () => false;
    assert.equal(tabUnfocused(doc), true);

    doc.hasFocus = () => true;
    doc.visibilityState = 'hidden';
    assert.equal(tabUnfocused(doc), true);

    assert.equal(tabUnfocused(null), false);
});

// --- What is shown -------------------------------------------------------------------

test('mentionNotification builds the title, body, tag and link', () => {
    assert.deepEqual(mentionNotification(mention()), {
        title: 'Alice mentioned you',
        options: { body: 'Pings · #general\nhey @bob', icon: '/avatars/alice.png', tag: 'mention-n-1' },
        url: '/notifications/n-1',
    });

    const role = mentionNotification(mention({ label: 'mentioned @Mods', avatar_url: null, open_url: null, excerpt: '' }));
    assert.equal(role.title, 'Alice mentioned @Mods');
    assert.equal(role.options.body, 'Pings · #general');
    assert.equal(role.options.icon, undefined);
    assert.equal(role.url, '/servers/1/channels/5#message-9');
});

test('mentionNotification falls back for missing fields', () => {
    const spec = mentionNotification({ type: MENTION_TYPE, message_id: 4 });
    assert.equal(spec.title, 'Someone mentioned you');
    assert.equal(spec.options.body, '');
    assert.equal(spec.options.tag, 'mention-4');
    assert.equal(spec.url, null);
});

test('mentionNotification skips muted places and other notification types', () => {
    assert.equal(mentionNotification(mention({ push: false })), null);
    assert.equal(mentionNotification(mention({ type: 'friend.request' })), null);
    assert.equal(mentionNotification(null), null);
    // Payloads without `push` still notify.
    const withoutPush = mention();
    delete withoutPush.push;
    assert.ok(mentionNotification(withoutPush));
});

test('long bodies are cut to 140 characters', () => {
    const spec = mentionNotification(mention({ server_name: '', channel_name: '', excerpt: 'x'.repeat(300) }));
    assert.equal(spec.options.body.length, 140);
    assert.ok(spec.options.body.endsWith('…'));
});

test('dmNotification names the author, and the group for group DMs', () => {
    assert.deepEqual(dmNotification(dm()), {
        title: 'Alice',
        options: { body: 'Lunch tomorrow?', icon: '/avatars/alice.png', tag: 'dm-12' },
        url: '/dms/3#message-12',
    });

    assert.equal(dmNotification(dm({ is_group: true, title: 'Weekend plans' })).title, 'Alice in Weekend plans');
    assert.equal(dmNotification(dm({ is_group: true, title: '' })).title, 'Alice');
    assert.equal(dmNotification(dm({ author_name: '' })).title, 'Someone');
});

test('dmNotification describes messages without text', () => {
    assert.equal(dmNotification(dm({ excerpt: '', has_attachments: true })).options.body, 'Sent an attachment');
    assert.equal(dmNotification(dm({ excerpt: '' })).options.body, 'Sent a message');
    assert.equal(dmNotification({}), null);
    assert.equal(dmNotification(null), null);
});

// --- Listening -----------------------------------------------------------------------

test('it listens on the user channel for mentions and DMs', () => {
    const { channel } = setup();
    assert.equal(typeof channel.notification, 'function');
    assert.equal(typeof channel.listeners[DM_EVENT], 'function');
});

test('a mention while the tab is hidden shows a notification', () => {
    const { channel, Notification } = setup();

    channel.notification(mention());

    assert.equal(Notification.shown.length, 1);
    assert.equal(Notification.shown[0].title, 'Alice mentioned you');
    assert.equal(Notification.shown[0].options.tag, 'mention-n-1');
});

test('a DM while another window has focus shows a notification', () => {
    const doc = fakeDocument();
    doc.hasFocus = () => false;
    const { channel, Notification } = setup({ doc });

    channel.listeners[DM_EVENT](dm());

    assert.deepEqual(Notification.shown.map((n) => n.title), ['Alice']);
});

test('nothing shows while the tab is in focus', () => {
    const doc = fakeDocument();
    doc.hasFocus = () => true;
    const { channel, Notification } = setup({ doc });

    channel.notification(mention());
    channel.listeners[DM_EVENT](dm());

    assert.equal(Notification.shown.length, 0);
});

test('nothing shows without permission, after opting out, or on Do Not Disturb', () => {
    for (const options of [
        { permission: 'default' },
        { permission: 'denied' },
        { storage: fakeStorage({ [OPT_OUT_KEY]: '1' }) },
        { status: 'dnd' },
    ]) {
        const { channel, Notification } = setup(options);
        channel.notification(mention());
        channel.listeners[DM_EVENT](dm());
        assert.equal(Notification.shown.length, 0, JSON.stringify(options));
    }
});

test('idle still notifies', () => {
    const { channel, Notification } = setup({ status: 'idle' });
    channel.notification(mention());
    assert.equal(Notification.shown.length, 1);
});

test('muted mentions do not show', () => {
    const { channel, Notification } = setup();
    channel.notification(mention({ push: false }));
    assert.equal(Notification.shown.length, 0);
});

test('permission given later is picked up without a reload', () => {
    const { channel, Notification } = setup({ permission: 'default' });
    channel.notification(mention());
    assert.equal(Notification.shown.length, 0);

    Notification.permission = 'granted';
    channel.notification(mention({ id: 'n-2' }));
    assert.equal(Notification.shown.length, 1);
});

test('clicking focuses the window, opens the message and closes it', () => {
    const { channel, Notification, navigated, focused } = setup();
    channel.notification(mention());

    let prevented = false;
    Notification.shown[0].onclick({ preventDefault: () => { prevented = true; } });

    assert.ok(prevented);
    assert.equal(focused(), 1);
    assert.deepEqual(navigated, ['/notifications/n-1']);
    assert.ok(Notification.shown[0].closed);
});

test('clicking a notification without a link only focuses', () => {
    const { handle, Notification, navigated, focused } = setup();
    handle.show({ title: 'Hi', options: {}, url: null });

    Notification.shown[0].onclick();

    assert.equal(focused(), 1);
    assert.deepEqual(navigated, []);
});

test('a browser that refuses the constructor is ignored', () => {
    const { channel, Notification } = setup({ throws: true });
    assert.doesNotThrow(() => channel.notification(mention()));
    assert.equal(Notification.shown.length, 0);
});

test('without the API, Echo or a user nothing is set up', () => {
    const base = { document: fakeDocument(), storage: fakeStorage() };
    assert.equal(initPushNotifications({ ...base, echo: fakeEcho(), userId: '1', Notification: null }), null);
    assert.equal(initPushNotifications({ ...base, echo: null, userId: '1', Notification: fakeNotificationApi() }), null);
    assert.equal(initPushNotifications({ ...base, echo: fakeEcho(), userId: null, Notification: fakeNotificationApi() }), null);
});

// --- The settings switch -----------------------------------------------------------

function settingsPage() {
    const status = make('p', { pushStatus: '' });
    const toggle = make('button', { pushToggle: '' });
    toggle.hidden = true;
    const root = make('section', { pushSettings: '' }, [status, toggle]);
    return { doc: fakeDocument(make('body', {}, [root])), root, status, toggle };
}

test('the settings switch asks for permission on click', async () => {
    const page = settingsPage();
    const Notification = fakeNotificationApi('default');
    const handle = initPushSettings({ document: page.doc, Notification, storage: fakeStorage() });

    assert.equal(page.root.dataset.pushState, 'default');
    assert.equal(page.toggle.hidden, false);
    assert.equal(page.toggle.textContent, 'Enable desktop notifications');

    assert.equal(await handle.click(), 'on');
    assert.equal(Notification.asked, 1);
    assert.match(page.status.textContent, /on in this browser/);
    assert.equal(page.toggle.textContent, 'Turn off desktop notifications');
});

test('the click listener asks too', async () => {
    const page = settingsPage();
    const Notification = fakeNotificationApi('default');
    initPushSettings({ document: page.doc, Notification, storage: fakeStorage() });

    page.toggle.dispatchEvent({ type: 'click' });
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(Notification.asked, 1);
    assert.equal(page.root.dataset.pushState, 'on');
});

test('a refused prompt shows how to unblock', async () => {
    const page = settingsPage();
    const Notification = fakeNotificationApi('default', { answer: 'denied' });
    const handle = initPushSettings({ document: page.doc, Notification, storage: fakeStorage() });

    assert.equal(await handle.click(), 'denied');
    assert.equal(page.toggle.hidden, true);
    assert.match(page.status.textContent, /blocked/);
});

test('a dismissed or failing prompt stays at default', async () => {
    const page = settingsPage();
    const Notification = fakeNotificationApi('default', { answer: 'default' });
    const handle = initPushSettings({ document: page.doc, Notification, storage: fakeStorage() });
    assert.equal(await handle.click(), 'default');

    const failing = fakeNotificationApi('default');
    failing.requestPermission = () => Promise.reject(new Error('nope'));
    const other = settingsPage();
    const otherHandle = initPushSettings({ document: other.doc, Notification: failing, storage: fakeStorage() });
    assert.equal(await otherHandle.click(), 'default');
});

test('turning off and on again only flips the opt-out', async () => {
    const page = settingsPage();
    const storage = fakeStorage();
    const Notification = fakeNotificationApi('granted');
    const handle = initPushSettings({ document: page.doc, Notification, storage });

    assert.equal(await handle.click(), 'off');
    assert.equal(storage.data[OPT_OUT_KEY], '1');
    assert.equal(page.toggle.textContent, 'Turn on desktop notifications');

    assert.equal(await handle.click(), 'on');
    assert.equal(OPT_OUT_KEY in storage.data, false);
    assert.equal(Notification.asked, 0);
});

test('unsupported browsers get a note and no button', () => {
    const page = settingsPage();
    initPushSettings({ document: page.doc, Notification: null, storage: fakeStorage() });

    assert.equal(page.toggle.hidden, true);
    assert.match(page.status.textContent, /does not support/);
});

test('storage that throws does not break the switch', async () => {
    const page = settingsPage();
    const handle = initPushSettings({ document: page.doc, Notification: fakeNotificationApi('granted'), storage: brokenStorage });

    assert.equal(await handle.click(), 'on');
});

test('pages without the section are left alone', () => {
    assert.equal(initPushSettings({ document: fakeDocument(), Notification: fakeNotificationApi(), storage: fakeStorage() }), null);
});

// --- Edge cases ----------------------------------------------------------------------

test('only an opt-out of exactly "1" turns notifications off', () => {
    for (const value of ['0', '', 'true', 'off']) {
        assert.equal(pushState(fakeNotificationApi('granted'), fakeStorage({ [OPT_OUT_KEY]: value })), 'on', value);
    }
    // The opt-out does not matter before permission is given.
    assert.equal(pushState(fakeNotificationApi('default'), fakeStorage({ [OPT_OUT_KEY]: '1' })), 'default');
});

test('tabUnfocused: a visible page without hasFocus counts as focused', () => {
    const doc = fakeDocument();
    doc.visibilityState = 'visible';
    delete doc.hasFocus;
    assert.equal(tabUnfocused(doc), false);
});

test('bodies collapse spaces and tabs but keep the line break, and stop at 140', () => {
    const spec = mentionNotification(mention({ server_name: 'Pings', channel_name: '', excerpt: '  a \t\t b  ' }));
    assert.equal(spec.options.body, 'Pings\n a b');

    const exact = mentionNotification(mention({ server_name: '', channel_name: '', excerpt: 'y'.repeat(140) }));
    assert.equal(exact.options.body, 'y'.repeat(140));

    const over = dmNotification(dm({ excerpt: 'z'.repeat(141) }));
    assert.equal(over.options.body, `${'z'.repeat(139)}…`);
});

test('mentionNotification with only a channel or only a server', () => {
    assert.equal(mentionNotification(mention({ server_name: '' })).options.body, '#general\nhey @bob');
    assert.equal(mentionNotification(mention({ channel_name: null })).options.body, 'Pings\nhey @bob');
});

test('dmNotification accepts message id 0 and the same message keeps its tag', () => {
    assert.equal(dmNotification(dm({ message_id: 0 })).options.tag, 'dm-0');
    assert.equal(dmNotification(dm()).options.tag, dmNotification(dm({ excerpt: 'edited' })).options.tag);
    assert.equal(dmNotification(dm({ avatar_url: null })).options.icon, undefined);
    assert.equal(dmNotification(dm({ url: '' })).url, null);
});

test('the status is read for every event, not once', () => {
    let status = 'dnd';
    const Notification = fakeNotificationApi('granted');
    const echo = fakeEcho();
    initPushNotifications({
        echo, userId: '7', document: unfocusedDocument(), Notification, storage: fakeStorage(), getStatus: () => status,
    });
    const channel = echo.channels['App.Models.User.7'];

    channel.notification(mention());
    status = 'online';
    channel.listeners[DM_EVENT](dm());

    assert.deepEqual(Notification.shown.map((n) => n.options.tag), ['dm-12']);
});

test('opting out in another tab takes effect without a reload', () => {
    const { channel, Notification, storage } = setup();
    channel.notification(mention());

    storage.setItem(OPT_OUT_KEY, '1');
    channel.notification(mention({ id: 'n-2' }));
    channel.listeners[DM_EVENT](dm());

    assert.equal(Notification.shown.length, 1);
});

test('focusing the tab between events stops the next one', () => {
    const doc = unfocusedDocument();
    const { channel, Notification } = setup({ doc });

    channel.listeners[DM_EVENT](dm());
    doc.visibilityState = 'visible';
    doc.hasFocus = () => true;
    channel.listeners[DM_EVENT](dm({ message_id: 13 }));

    assert.deepEqual(Notification.shown.map((n) => n.options.tag), ['dm-12']);
});

test('broadcasts that are not mentions or are malformed show nothing', () => {
    const { channel, Notification } = setup();
    channel.notification({ type: 'friend.request', id: 'x' });
    channel.notification(undefined);
    channel.listeners[DM_EVENT]({ conversation_id: 3 });
    channel.listeners[DM_EVENT](undefined);
    assert.equal(Notification.shown.length, 0);
});

test('clicking the switch when blocked or unsupported does nothing', async () => {
    const page = settingsPage();
    const Notification = fakeNotificationApi('denied');
    const storage = fakeStorage();
    const handle = initPushSettings({ document: page.doc, Notification, storage });

    assert.equal(await handle.click(), 'denied');
    assert.equal(Notification.asked, 0);
    assert.deepEqual(storage.data, {});

    const other = settingsPage();
    const otherHandle = initPushSettings({ document: other.doc, Notification: null, storage });
    assert.equal(await otherHandle.click(), 'unsupported');
});

test('enabling clears an old opt-out so it ends up on', async () => {
    const page = settingsPage();
    const storage = fakeStorage({ [OPT_OUT_KEY]: '1' });
    const Notification = fakeNotificationApi('default');
    const handle = initPushSettings({ document: page.doc, Notification, storage });

    assert.equal(await handle.click(), 'on');
    assert.equal(OPT_OUT_KEY in storage.data, false);
});

test('a section without status or button still renders its state', () => {
    const root = make('section', { pushSettings: '' });
    const doc = fakeDocument(make('body', {}, [root]));
    const handle = initPushSettings({ document: doc, Notification: fakeNotificationApi('granted'), storage: fakeStorage() });

    assert.equal(handle.render(), 'on');
    assert.equal(root.dataset.pushState, 'on');
});
