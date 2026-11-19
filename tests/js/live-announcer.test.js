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

// --- Further edge cases (ISSUE-38 test pass) ---

test('a code block is read as its code, without the language or fences, apart from the words around it', () => {
    assert.equal(
        announcementText({ author: alice, content: 'before```js\nconst x = 1\n```after' }),
        'New message from Alice: before const x = 1 after',
    );
    assert.equal(plainText(parseMarkdown('```js\nc\n```')).trim(), 'c');
});

test('a quote over several lines does not run into the text after it', () => {
    assert.equal(announcementText({ author: alice, content: '> q1\n> q2\nend' }), 'New message from Alice: q1 q2 end');
});

test('nested formatting keeps every word once', () => {
    assert.equal(
        announcementText({ author: alice, content: '***both*** and **bold _inner_ more**' }),
        'New message from Alice: both and bold inner more',
    );
});

test('text that is only formatting characters still says who wrote it', () => {
    const line = announcementText({ author: alice, content: '****' });
    assert.ok(line.startsWith('New message from Alice'), line);
});

test('the cut never leaves a space before the ellipsis', () => {
    const content = `${'x'.repeat(MAX_ANNOUNCED_LENGTH - 2)}   yyyy`;
    const text = announcementText({ author: alice, content }).slice('New message from Alice: '.length);
    assert.equal(text, `${'x'.repeat(MAX_ANNOUNCED_LENGTH - 2)}…`);
});

test('long text with attachments is cut before the attachment count is added', () => {
    const line = announcementText({ author: alice, content: 'z'.repeat(MAX_ANNOUNCED_LENGTH * 2), attachments: [{}, {}, {}] });
    assert.ok(line.endsWith('…, with 3 attachments'), line);
});

test('a mention with only attachments, and a mention with no text at all', () => {
    assert.equal(announcementText({ author: alice, content: '', attachments: [{}] }, { mention: true }), 'Alice mentioned you: 1 attachment');
    assert.equal(announcementText({ author: alice, content: '' }, { mention: true }), 'Alice mentioned you');
});

test('an author without a name is read as Deleted User; missing content and attachments are fine', () => {
    assert.equal(announcementText({ author: {}, content: 'hi' }), 'New message from Deleted User: hi');
    assert.equal(announcementText({ author: alice }), 'New message from Alice');
    assert.equal(announcementText(undefined), '');
});

// Known bug: the cut counts UTF-16 code units, so an emoji right at the
// limit is split and the line ends in half a surrogate pair (read as a
// replacement character, or skipped). Marked todo so it is reported
// without failing the suite.
test('the cut never splits an emoji', { todo: 'announcementText slices by code unit and can leave a lone surrogate before "…"' }, () => {
    const content = `${'a'.repeat(MAX_ANNOUNCED_LENGTH - 2)}😀${'b'.repeat(10)}`;
    const line = announcementText({ author: alice, content });
    assert.equal(line.isWellFormed(), true, JSON.stringify(line.slice(-3)));
});

test('the announcer can keep a different number of lines', () => {
    const doc = fakeDocument();
    const region = make('div');
    const announcer = createAnnouncer(region, { doc, keep: 1 });
    announcer.announce('first');
    announcer.announce('second');
    assert.deepEqual(region.children.map((line) => line.textContent), ['second']);
});

test('the announcer ignores null and undefined text', () => {
    const doc = fakeDocument();
    const region = make('div');
    const announcer = createAnnouncer(region, { doc });
    announcer.announce(null);
    announcer.announce(undefined);
    assert.equal(region.children.length, 0);
});

test('the same line twice is added twice (each new message is read)', () => {
    const doc = fakeDocument();
    const region = make('div');
    const announcer = createAnnouncer(region, { doc });
    announcer.announce('New message from Alice: ok');
    announcer.announce('New message from Alice: ok');
    assert.equal(region.children.length, 2);
});

test('the announcer ignores empty text and a missing region', () => {
    const doc = fakeDocument();
    const region = make('div');
    createAnnouncer(region, { doc }).announce('');
    assert.equal(region.children.length, 0);

    assert.doesNotThrow(() => createAnnouncer(null, { doc }).announce('hi'));
});
