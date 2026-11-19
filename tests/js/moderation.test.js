// ISSUE-29: kicks, bans and timeouts, live. Run with `npm test`
// (node --test, no deps). The server side is covered by
// tests/Feature/Moderation; the script is checked here against a minimal
// fake DOM and a fake Echo.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    MODERATION_EVENT,
    actionFor,
    currentServerId,
    initModeration,
    noticeText,
    pageChannels,
} from '../../resources/js/moderation.js';

// --- Fakes ---------------------------------------------------------------

// Only "[data-x]" selectors are needed. innerHTML throws, so a test fails
// loudly if the script ever parsed the server name as HTML.
const camel = (name) => name.replace(/-([a-z])/g, (_, c) => c.toUpperCase());

function fakeNode(tag = 'div', dataset = {}) {
    const node = {
        tagName: tag.toUpperCase(),
        className: '',
        textContent: '',
        href: undefined,
        dataset: { ...dataset },
        attributes: {},
        childNodes: [],
        parentNode: null,
        setAttribute(name, value) {
            this.attributes[name] = String(value);
        },
        appendChild(child) {
            child.parentNode = this;
            this.childNodes.push(child);
            return child;
        },
        remove() {
            if (!this.parentNode) return;
            this.parentNode.childNodes = this.parentNode.childNodes.filter((child) => child !== this);
            this.parentNode = null;
        },
        querySelector(selector) {
            const match = /^\[data-([a-z-]+)\]$/.exec(selector);
            if (!match) throw new Error(`Unsupported selector ${selector}`);
            const key = camel(match[1]);
            const walk = (parent) => {
                for (const child of parent.childNodes) {
                    if (key in child.dataset) return child;
                    const found = walk(child);
                    if (found) return found;
                }
                return null;
            };
            return walk(this);
        },
    };
    Object.defineProperty(node, 'innerHTML', {
        set() {
            throw new Error('innerHTML must not be used');
        },
    });
    return node;
}

function fakeDocument({ serverId = null, channels = null } = {}) {
    const body = fakeNode('body');
    if (serverId !== null) body.appendChild(fakeNode('nav', { channelSidebar: '', serverId: String(serverId) }));
    if (channels) body.appendChild(fakeNode('section', { channelMessages: '', ...channels }));

    return {
        body,
        createElement: (tag) => fakeNode(tag),
        querySelector: (selector) => body.querySelector(selector),
    };
}

function fakeEcho() {
    const echo = {
        listeners: {},
        left: [],
        private(name) {
            return {
                listen(event, callback) {
                    echo.listeners[`${name} ${event}`] = callback;
                    return this;
                },
            };
        },
        leave(name) {
            echo.left.push(name);
        },
    };
    return echo;
}

const fakeLocation = () => ({
    reloads: 0,
    reload() {
        this.reloads++;
    },
});

const event = (type, id = 7, name = 'Mod Server') => ({ type, server: { id, name }, until: null });

// --- Pure helpers --------------------------------------------------------

test('the open server comes from the channel sidebar', () => {
    assert.equal(currentServerId(fakeDocument({ serverId: 7 })), 7);
    assert.equal(currentServerId(fakeDocument()), null);
    assert.equal(currentServerId(fakeDocument({ serverId: 'abc' })), null);
});

test('only the page of that server reacts', () => {
    assert.equal(actionFor(event('kicked'), 7), 'leave');
    assert.equal(actionFor(event('banned'), 7), 'leave');
    assert.equal(actionFor(event('timed_out'), 7), 'reload');
    assert.equal(actionFor(event('timeout_removed'), 7), 'reload');

    assert.equal(actionFor(event('banned', 8), 7), null);
    assert.equal(actionFor(event('banned'), null), null);
    assert.equal(actionFor(event('promoted'), 7), null);
    assert.equal(actionFor(null, 7), null);
});

test('the notice names the server and what happened', () => {
    assert.equal(noticeText(event('banned')), 'You have been banned from Mod Server.');
    assert.equal(noticeText(event('kicked')), 'You have been kicked from Mod Server.');
    assert.equal(noticeText({ type: 'kicked', server: {} }), 'You have been kicked from this server.');
});

test('the page channels are the message panel broadcast and typing channels', () => {
    const document = fakeDocument({ serverId: 7, channels: { broadcastChannel: 'channel.3', typingChannel: 'typing.3' } });

    assert.deepEqual(pageChannels(document), ['channel.3', 'typing.3']);
    assert.deepEqual(pageChannels(fakeDocument({ serverId: 7 })), []);
});

// --- initModeration ------------------------------------------------------

test('it listens on the user channel and needs echo and a user', () => {
    const echo = fakeEcho();
    const document = fakeDocument();

    assert.equal(initModeration({ echo: null, userId: '5', document, location: fakeLocation() }), null);
    assert.equal(initModeration({ echo, userId: null, document, location: fakeLocation() }), null);

    const handle = initModeration({ echo, userId: '5', document, location: fakeLocation() });
    assert.equal(typeof handle, 'function');
    assert.equal(echo.listeners[`App.Models.User.5 ${MODERATION_EVENT}`], handle);
});

test('a ban on the open server leaves its channels, removes the panel and shows a notice', () => {
    const echo = fakeEcho();
    const location = fakeLocation();
    const document = fakeDocument({ serverId: 7, channels: { broadcastChannel: 'channel.3', typingChannel: 'typing.3' } });
    const handle = initModeration({ echo, userId: '5', document, location, dashboardUrl: '/dashboard' });

    assert.equal(handle(event('banned', 7, '<b>Mods</b>')), 'leave');

    // The server's presence channel too (ISSUE-31).
    assert.deepEqual(echo.left, ['server.7', 'channel.3', 'typing.3']);
    assert.equal(document.querySelector('[data-channel-messages]'), null);
    const notice = document.querySelector('[data-moderation-notice]');
    assert.ok(notice);
    assert.equal(notice.attributes.role, 'alertdialog');
    const [box] = notice.childNodes;
    assert.equal(box.childNodes[0].textContent, 'You have been banned from <b>Mods</b>.');
    assert.equal(box.childNodes[1].href, '/dashboard');
    assert.equal(location.reloads, 0);
});

test('a timeout change reloads the open server page', () => {
    const echo = fakeEcho();
    const location = fakeLocation();
    const handle = initModeration({ echo, userId: '5', document: fakeDocument({ serverId: 7 }), location });

    handle(event('timed_out'));
    handle(event('timeout_removed'));

    assert.equal(location.reloads, 2);
    assert.deepEqual(echo.left, []);
});

test('a kick on a server page without a message panel still shows the notice', () => {
    const echo = fakeEcho();
    const location = fakeLocation();
    const document = fakeDocument({ serverId: 7 });
    const handle = initModeration({ echo, userId: '5', document, location, dashboardUrl: '/home' });

    assert.equal(handle(event('kicked')), 'leave');

    assert.deepEqual(echo.left, ['server.7']);
    const notice = document.querySelector('[data-moderation-notice]');
    assert.ok(notice);
    const [box] = notice.childNodes;
    assert.equal(box.childNodes[0].textContent, 'You have been kicked from Mod Server.');
    assert.equal(box.childNodes[1].href, '/home');
    assert.equal(location.reloads, 0);
});

test('a server id sent as a string still matches the open page', () => {
    assert.equal(actionFor({ type: 'banned', server: { id: '7' } }, 7), 'leave');
    assert.equal(actionFor({ type: 'banned' }, 7), null);
});

test('a missing or empty server name falls back to "this server"', () => {
    assert.equal(noticeText({ type: 'banned', server: { name: '' } }), 'You have been banned from this server.');
    assert.equal(noticeText({ type: 'banned', server: { name: 42 } }), 'You have been banned from this server.');
    assert.equal(noticeText(null), 'You have been kicked from this server.');
});

test('a panel with only a broadcast channel leaves just that one', () => {
    const document = fakeDocument({ serverId: 7, channels: { broadcastChannel: 'channel.3', typingChannel: '' } });

    assert.deepEqual(pageChannels(document), ['channel.3']);
});

test('the server id must be a positive whole number', () => {
    assert.equal(currentServerId(fakeDocument({ serverId: 0 })), null);
    assert.equal(currentServerId(fakeDocument({ serverId: -3 })), null);
    assert.equal(currentServerId(fakeDocument({ serverId: '1.5' })), null);
});

test('events for another server leave the page alone', () => {
    const echo = fakeEcho();
    const location = fakeLocation();
    const document = fakeDocument({ serverId: 7, channels: { broadcastChannel: 'channel.3', typingChannel: 'typing.3' } });
    const handle = initModeration({ echo, userId: '5', document, location });

    assert.equal(handle(event('banned', 9)), null);
    assert.equal(handle(event('timed_out', 9)), null);

    // Only that server's presence channel (ISSUE-31), which every page joins.
    assert.deepEqual(echo.left, ['server.9']);
    assert.equal(location.reloads, 0);
    assert.ok(document.querySelector('[data-channel-messages]'));
    assert.equal(document.querySelector('[data-moderation-notice]'), null);
});
