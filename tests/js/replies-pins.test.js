// ISSUE-20: reply quotes and the pinned messages panel of the channel page.
// Run with `npm test` (node --test, no deps). The Blade markup is covered by
// tests/Feature/Messages/ReplyTest.php and PinTest.php; the renderers are
// checked here against a minimal fake DOM.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    excerpt,
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
        setAttribute(name, value) { this.attributes[name] = String(value); },
        removeAttribute(name) { delete this.attributes[name]; },
        appendChild(child) { this.children.push(child); return child; },
        append(...children) { this.children.push(...children); },
        replaceChildren(...children) { this.children = [...children]; },
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
