// Reply quotes and the pinned messages panel of the channel page.
// Run with `npm test` (node --test, no deps). The Blade markup is covered by
// tests/Feature/Messages/ReplyTest.php and PinTest.php; the renderers are
// checked here against a minimal fake DOM.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    applyPinned,
    excerpt,
    isBlockedAuthor,
    previewText,
    renderMessage,
    renderPin,
    renderReplyPreview,
    uploadErrorMessage,
} from '../../resources/js/channel-messages.js';

// Just enough of `document` for el(). innerHTML throws, so a test fails
// loudly if a renderer ever parsed user text as HTML.
function fakeNode(tag) {
    return {
        tagName: tag.toUpperCase(),
        className: '',
        textContent: '',
        hidden: false,
        style: {},
        dataset: {},
        attributes: {},
        children: [],
        parentNode: null,
        setAttribute(name, value) { this.attributes[name] = String(value); },
        removeAttribute(name) { delete this.attributes[name]; },
        appendChild(child) { child.parentNode = this; this.children.push(child); return child; },
        append(...children) { children.forEach((child) => this.appendChild(child)); },
        replaceChildren(...children) { this.children = []; this.append(...children); },
        remove() {
            if (!this.parentNode) return;
            this.parentNode.children = this.parentNode.children.filter((child) => child !== this);
            this.parentNode = null;
        },
        // Only the selectors applyPinned() uses: [data-foo-bar] and tag[name="x"].
        querySelector(selector) {
            const data = /^\[data-([a-z-]+)\]$/.exec(selector);
            const named = /^([a-z]+)\[name="([^"]+)"\]$/.exec(selector);
            if (!data && !named) throw new Error(`Unsupported selector ${selector}`);
            const key = data?.[1].replace(/-([a-z])/g, (_, c) => c.toUpperCase());
            const matches = (node) => (data
                ? node.dataset !== undefined && key in node.dataset
                : node.tagName === named[1].toUpperCase() && node.name === named[2]);
            const walk = (node) => {
                for (const child of node.children ?? []) {
                    if (matches(child)) return child;
                    const hit = walk(child);
                    if (hit) return hit;
                }
                return null;
            };
            return walk(this);
        },
        get innerHTML() { throw new Error('innerHTML must not be used'); },
        set innerHTML(_) { throw new Error('innerHTML must not be used'); },
    };
}

function withFakeDocument(fn) {
    const previous = globalThis.document;
    globalThis.document = { createElement: fakeNode };
    try {
        return fn();
    } finally {
        if (previous === undefined) delete globalThis.document;
        else globalThis.document = previous;
    }
}

const find = (node, predicate) => {
    if (predicate(node)) return node;
    for (const child of node.children ?? []) {
        const hit = find(child, predicate);
        if (hit) return hit;
    }
    return null;
};
const byData = (key) => (node) => node.dataset !== undefined && key in node.dataset;

test('excerpt matches Message::excerpt()', () => {
    assert.equal(excerpt('  Short  '), 'Short');
    assert.equal(excerpt('one\n\ntwo   three'), 'one two three');
    assert.equal(excerpt(null), '');

    const long = excerpt(`start ${'x'.repeat(200)}`);
    assert.equal([...long].length, 100);
    assert.ok(long.endsWith('…'));

    // Counts characters, not UTF-16 units.
    assert.equal([...excerpt('😀'.repeat(150))].length, 100);
});

test('previewText matches Message::previewText()', () => {
    assert.equal(previewText({ content: 'Hi', has_attachments: true }), 'Hi');
    assert.equal(previewText({ content: '', has_attachments: true }), 'Click to see attachment');
    assert.equal(previewText({ content: '', has_attachments: false }), '');
});

test('renderReplyPreview links to the original', () => withFakeDocument(() => {
    assert.equal(renderReplyPreview(null), null);

    const row = renderReplyPreview({
        id: 7,
        deleted: false,
        author: { id: 1, name: '<b>Alice</b>' },
        content: '<img src=x onerror=alert(1)>',
        has_attachments: false,
    });

    assert.equal(row.dataset.replyToId, '7');
    assert.equal(find(row, byData('replyJump')).href, '#message-7');
    assert.equal(find(row, byData('replyAuthor')).textContent, '<b>Alice</b>');
    assert.equal(find(row, byData('replyContent')).textContent, '<img src=x onerror=alert(1)>');
}));

test('renderReplyPreview says when the original is gone', () => withFakeDocument(() => {
    const row = renderReplyPreview({ id: 7, deleted: true, author: null, content: '', has_attachments: false });

    assert.equal(find(row, byData('replyJump')), null);
    assert.equal(find(row, byData('replyDeleted')).textContent, 'Original message was deleted');
}));

test('renderReplyPreview names deleted authors', () => withFakeDocument(() => {
    const row = renderReplyPreview({ id: 7, deleted: false, author: null, content: 'Hi', has_attachments: false });

    assert.equal(find(row, byData('replyAuthor')).textContent, 'Deleted User');
}));

// --- Blocking -------------------------------------------------

test('isBlockedAuthor compares ids as strings and never blocks deleted accounts', () => {
    const blocked = new Set(['5']);

    assert.equal(isBlockedAuthor({ id: 5, name: 'Bob' }, blocked), true);
    assert.equal(isBlockedAuthor({ id: '5' }, blocked), true);
    assert.equal(isBlockedAuthor({ id: 6 }, blocked), false);
    assert.equal(isBlockedAuthor(null, blocked), false);
    assert.equal(isBlockedAuthor({ id: 5 }, null), false);
    assert.equal(isBlockedAuthor({ id: 5 }, new Set()), false);
});

test('renderReplyPreview hides a quote of someone the viewer blocked', () => withFakeDocument(() => {
    const reply = { id: 7, deleted: false, author: { id: 5, name: 'Bob' }, content: 'spam', has_attachments: false };

    const row = renderReplyPreview(reply, { blockedUserIds: new Set(['5']) });
    assert.equal(find(row, byData('replyJump')), null);
    assert.equal(find(row, byData('replyContent')), null);
    assert.equal(find(row, byData('replyBlocked')).textContent, 'Blocked message');

    // A deleted original still says so, blocked or not.
    const gone = renderReplyPreview({ ...reply, deleted: true }, { blockedUserIds: new Set(['5']) });
    assert.ok(find(gone, byData('replyDeleted')));

    // Without the block it quotes as usual.
    assert.equal(find(renderReplyPreview(reply), byData('replyContent')).textContent, 'spam');
}));

test('renderMessage passes the blocked ids on to the reply quote', () => withFakeDocument(() => {
    const item = renderMessage({
        id: 8,
        content: 'Replying',
        created_at: null,
        edited_at: null,
        pinned_at: null,
        author: { id: 2, name: 'Carol', username: 'carol', avatar_url: '', profile_url: '' },
        reply_to: { id: 7, deleted: false, author: { id: 5, name: 'Bob' }, content: 'spam', has_attachments: false },
        reactions: [],
        attachments: [],
    }, { blockedUserIds: new Set(['5']) });

    assert.ok(find(item, byData('replyBlocked')));
}));

const message = (overrides = {}) => ({
    id: 12,
    channel_id: 3,
    content: 'Hello',
    created_at: '2026-01-01T10:00:00+00:00',
    edited_at: null,
    pinned_at: null,
    author: { id: 5, name: 'Bob', username: 'bob', avatar_url: '/a.png', profile_url: '/users/bob/profile' },
    reply_to: null,
    reactions: [],
    attachments: [],
    ...overrides,
});

test('renderMessage adds the anchor, quote, pin marker and actions', () => withFakeDocument(() => {
    const context = { viewerId: '9', messagesUrl: '/m', csrfToken: 't', canSendMessages: true, canManageMessages: true };
    const item = renderMessage(message({
        pinned_at: '2026-01-01T11:00:00+00:00',
        reply_to: { id: 4, deleted: false, author: { id: 1, name: 'Alice' }, content: 'Q', has_attachments: false },
    }), context);

    assert.equal(item.id, 'message-12');
    assert.equal(item.dataset.pinUrl, '/m/12/pin');
    assert.equal(item.children[0].dataset.replyToId, '4');
    assert.equal(find(item, byData('messagePinned')).hidden, false);
    assert.ok(find(item, byData('messageReply')));

    const pinForm = find(item, byData('messagePinForm'));
    assert.equal(pinForm.action, '/m/12/pin');
    assert.equal(find(pinForm, byData('messagePin')).textContent, 'Unpin');
    assert.ok(find(pinForm, (node) => node.name === '_method' && node.value === 'DELETE'));
}));

test('renderMessage leaves out Reply and Pin for viewers who cannot use them', () => withFakeDocument(() => {
    const item = renderMessage(message(), { viewerId: '9', messagesUrl: '/m', csrfToken: 't' });

    assert.equal(find(item, byData('messagePinned')).hidden, true);
    assert.equal(find(item, byData('messageReply')), null);
    assert.equal(find(item, byData('messagePinForm')), null);
    assert.equal(find(item, byData('replyPreview')), null);
}));

test('renderPin shows the message and who pinned it', () => withFakeDocument(() => {
    const pin = message({ content: 'Rules', pinned_by: { id: 1, name: 'Owner' }, attachments: [{}, {}] });

    const member = renderPin(pin, {});
    assert.equal(member.dataset.messageId, '12');
    assert.ok(find(member, (node) => node.textContent === 'Rules'));
    assert.ok(find(member, (node) => node.textContent === 'Pinned by Owner'));
    assert.ok(find(member, (node) => node.textContent === '2 attachments'));
    assert.ok(find(member, byData('pinJump')));
    assert.equal(find(member, byData('pinUnpin')), null);

    const moderator = renderPin({ ...pin, pinned_by: null }, { canManageMessages: true });
    assert.ok(find(moderator, byData('pinUnpin')));
    assert.ok(find(moderator, (node) => node.textContent === 'Pinned'));
}));

test('uploadErrorMessage shows reply errors', () => {
    const error = { response: { status: 422, data: { errors: { reply_to_id: ['The message you are replying to was deleted.'] } } } };

    assert.equal(uploadErrorMessage(error), 'The message you are replying to was deleted.');
});

// --- Edge cases ------------------------------------------------------------

test('excerpt keeps exactly 100 characters and cuts at 101, like Message::excerpt()', () => {
    assert.equal(excerpt('x'.repeat(100)), 'x'.repeat(100));
    assert.equal(excerpt('x'.repeat(101)), `${'x'.repeat(99)}…`);

    // No space left dangling before the ellipsis.
    assert.equal(excerpt(`${'x'.repeat(98)} ${'y'.repeat(10)}`), `${'x'.repeat(98)}…`);

    // Emoji are one character each and are never split in half.
    assert.equal(excerpt('😀'.repeat(100)), '😀'.repeat(100));
    assert.equal(excerpt('😀'.repeat(101)), `${'😀'.repeat(99)}…`);

    assert.equal(excerpt(' \n\t '), '');
    assert.equal(excerpt('a b 　c'), 'a b c');
    assert.equal(excerpt(undefined), '');
    assert.equal(excerpt(42), '42');
});

test('renderReplyPreview of a file-only original says so', () => withFakeDocument(() => {
    const row = renderReplyPreview({ id: 7, deleted: false, author: { id: 1, name: 'Alice' }, content: '', has_attachments: true });

    assert.equal(find(row, byData('replyContent')).textContent, 'Click to see attachment');
}));

test('renderPin of a file-only message from a deleted user', () => withFakeDocument(() => {
    const item = renderPin(message({ content: '', author: null, attachments: [{}], pinned_by: null }));

    assert.ok(find(item, (node) => node.textContent === 'Deleted User'));
    assert.ok(find(item, (node) => node.textContent === '1 attachment'));
    // No empty content paragraph.
    assert.equal(find(item, (node) => node.tagName === 'P' && node.className.includes('whitespace-pre-wrap')), null);
}));

test('renderPin shows user text as text', () => withFakeDocument(() => {
    const item = renderPin(message({ content: '<img src=x onerror=alert(1)>', pinned_by: { id: 1, name: '<b>Mod</b>' } }));

    assert.ok(find(item, (node) => node.textContent === '<img src=x onerror=alert(1)>'));
    assert.ok(find(item, (node) => node.textContent === 'Pinned by <b>Mod</b>'));
}));

const moderatorContext = { viewerId: '9', messagesUrl: '/m', csrfToken: 't', canSendMessages: true, canManageMessages: true };
const methodInputs = (form) => form.children.filter((node) => node.name === '_method');

test('applyPinned switches the marker and the Pin form both ways', () => withFakeDocument(() => {
    const item = renderMessage(message(), moderatorContext);
    const marker = find(item, byData('messagePinned'));
    const form = find(item, byData('messagePinForm'));
    const button = find(form, byData('messagePin'));
    assert.equal(button.textContent, 'Pin');
    assert.equal(methodInputs(form).length, 0);

    applyPinned(item, '2026-01-01T11:00:00+00:00');
    assert.equal(marker.hidden, false);
    assert.equal(button.textContent, 'Unpin');
    assert.deepEqual(methodInputs(form).map((input) => [input.type, input.value]), [['hidden', 'DELETE']]);

    // Applying the same state twice (a broadcast after our own response) adds nothing.
    applyPinned(item, '2026-01-01T11:00:00+00:00');
    assert.equal(methodInputs(form).length, 1);

    applyPinned(item, null);
    assert.equal(marker.hidden, true);
    assert.equal(button.textContent, 'Pin');
    assert.equal(methodInputs(form).length, 0);

    applyPinned(item, null);
    assert.equal(methodInputs(form).length, 0);
}));

test('applyPinned only moves the marker for viewers without the Pin form', () => withFakeDocument(() => {
    const item = renderMessage(message(), { viewerId: '9', messagesUrl: '/m', csrfToken: 't' });

    applyPinned(item, '2026-01-01T11:00:00+00:00');
    assert.equal(find(item, byData('messagePinned')).hidden, false);
    applyPinned(item, '');
    assert.equal(find(item, byData('messagePinned')).hidden, true);

    // A broadcast for a message that is not on the page.
    assert.doesNotThrow(() => applyPinned(null, '2026-01-01T11:00:00+00:00'));
}));

test('applyPinned keeps the form of a server-rendered pinned message working when unpinned', () => withFakeDocument(() => {
    // Like messages/item.blade.php for a pinned message: @method('DELETE') already present.
    const item = renderMessage(message({ pinned_at: '2026-01-01T11:00:00+00:00' }), moderatorContext);
    const form = find(item, byData('messagePinForm'));
    assert.equal(methodInputs(form).length, 1);

    applyPinned(item, null);
    assert.equal(methodInputs(form).length, 0);
    assert.equal(find(form, byData('messagePin')).textContent, 'Pin');
}));
