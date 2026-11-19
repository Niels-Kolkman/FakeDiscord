// ISSUE-38: screen reader lines for messages that arrive live
// (resources/js/live-announcer.js, used by channel-messages.js).
// Run with `npm test`. The region's markup is covered by
// tests/Feature/Layout/AccessibilityTest.php.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    KEEP_ANNOUNCEMENTS,
    MAX_ANNOUNCED_LENGTH,
    announcementText,
    createAnnouncer,
    plainText,
} from '../../resources/js/live-announcer.js';
import { parseMarkdown } from '../../resources/js/markdown.js';
import { fakeDocument, make, textOf } from './helpers/fake-dom.js';

const alice = { id: 2, name: 'Alice' };

test('a message is announced with its author and text', () => {
    assert.equal(announcementText({ author: alice, content: 'see you at 8' }), 'New message from Alice: see you at 8');
});

test('a mention says so', () => {
    assert.equal(announcementText({ author: alice, content: '@bob hi' }, { mention: true }), 'Alice mentioned you: @bob hi');
});

test('markdown characters are left out, the words stay', () => {
    assert.equal(
        announcementText({ author: alice, content: '**bold** _it_ ~~old~~ `code`' }),
        'New message from Alice: bold it old code',
    );
    assert.equal(announcementText({ author: alice, content: '> quoted\nreply' }), 'New message from Alice: quoted reply');
    assert.equal(plainText(parseMarkdown('see https://example.com')), 'see https://example.com');
    assert.equal(plainText([]), '');
    assert.equal(plainText(null), '');
});

test('line breaks and runs of spaces become single spaces', () => {
    assert.equal(announcementText({ author: alice, content: '  one\n\ntwo   three ' }), 'New message from Alice: one two three');
});

test('long text is cut with an ellipsis', () => {
    const line = announcementText({ author: alice, content: 'a'.repeat(MAX_ANNOUNCED_LENGTH + 50) });
    const text = line.slice('New message from Alice: '.length);
    assert.equal(text.length, MAX_ANNOUNCED_LENGTH);
    assert.ok(text.endsWith('…'));

    const exact = 'b'.repeat(MAX_ANNOUNCED_LENGTH);
    assert.equal(announcementText({ author: alice, content: exact }), `New message from Alice: ${exact}`);
});

test('attachments are counted, with or without text', () => {
    assert.equal(announcementText({ author: alice, content: '', attachments: [{}] }), 'New message from Alice: 1 attachment');
    assert.equal(announcementText({ author: alice, content: null, attachments: [{}, {}] }), 'New message from Alice: 2 attachments');
    assert.equal(announcementText({ author: alice, content: 'look', attachments: [{}] }), 'New message from Alice: look, with 1 attachment');
    assert.equal(announcementText({ author: alice, content: 'look', attachments: [] }), 'New message from Alice: look');
});

test('a deleted author and an empty message still give a line', () => {
    assert.equal(announcementText({ author: null, content: 'hi' }), 'New message from Deleted User: hi');
    assert.equal(announcementText({ author: alice, content: '   ' }), 'New message from Alice');
    assert.equal(announcementText(null), '');
});

test('the announcer adds one line per message as text, keeping the last few', () => {
    const doc = fakeDocument();
    const region = make('div', { messageAnnouncer: '' });
    const announcer = createAnnouncer(region, { doc });

    announcer.announce('New message from Alice: <b>hi</b>');
    assert.equal(region.children.length, 1);
    assert.equal(region.children[0].tagName, 'P');
    assert.equal(textOf(region), 'New message from Alice: <b>hi</b>', 'text, never HTML');

    for (let i = 1; i <= KEEP_ANNOUNCEMENTS + 2; i += 1) announcer.announce(`line ${i}`);
    assert.equal(region.children.length, KEEP_ANNOUNCEMENTS);
    assert.equal(region.children.at(-1).textContent, `line ${KEEP_ANNOUNCEMENTS + 2}`, 'newest last');
    assert.equal(region.children[0].textContent, 'line 3');
});

test('the announcer ignores empty text and a missing region', () => {
    const doc = fakeDocument();
    const region = make('div');
    createAnnouncer(region, { doc }).announce('');
    assert.equal(region.children.length, 0);

    assert.doesNotThrow(() => createAnnouncer(null, { doc }).announce('hi'));
});
