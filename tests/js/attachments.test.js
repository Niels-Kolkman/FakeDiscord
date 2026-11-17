// ISSUE-19: attachment helpers of the channel page. Run with `npm test`
// (node --test, no deps). The DOM rendering is covered by the Blade tests
// (tests/Feature/Messages/AttachmentTest.php); these are the pure parts.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    formatSize,
    previewSize,
    queueFiles,
    uploadErrorMessage,
} from '../../resources/js/channel-messages.js';

const MB = 1024 * 1024;
const limits = { maxFiles: 5, maxBytes: 10 * MB };
const file = (name, size = 1) => ({ name, size });

test('previewSize matches MessageAttachment::previewSize()', () => {
    assert.deepEqual(previewSize(1600, 600), [400, 150]);
    assert.deepEqual(previewSize(1000, 1500), [200, 300]);
    assert.deepEqual(previewSize(50, 40), [50, 40]);
    assert.equal(previewSize(null, null), null);
    assert.equal(previewSize(0, 10), null);
    assert.equal(previewSize(10.5, 10), null);
});

test('formatSize matches MessageAttachment::formatSize()', () => {
    assert.equal(formatSize(0), '0 B');
    assert.equal(formatSize(1023), '1023 B');
    assert.equal(formatSize(1024), '1 KB');
    assert.equal(formatSize(1536 * 1024), '1.5 MB');
    assert.equal(formatSize(10 * MB), '10 MB');
    assert.equal(formatSize(undefined), '0 B');
});

test('queueFiles adds files up to the limit', () => {
    const result = queueFiles([file('a.png')], [file('b.png'), file('c.pdf')], limits);
    assert.deepEqual(result.files.map((f) => f.name), ['a.png', 'b.png', 'c.pdf']);
    assert.equal(result.error, null);
});

test('queueFiles stops at maxFiles and says so', () => {
    const current = ['1', '2', '3', '4'].map((n) => file(`${n}.txt`));
    const result = queueFiles(current, [file('5.txt'), file('6.txt'), file('7.txt')], limits);
    assert.equal(result.files.length, 5);
    assert.equal(result.files[4].name, '5.txt');
    assert.equal(result.error, 'You can attach at most 5 files to a message.');
});

test('queueFiles leaves out files over maxBytes, keeps the rest', () => {
    const result = queueFiles([], [file('big.zip', 10 * MB + 1), file('ok.zip', 10 * MB)], limits);
    assert.deepEqual(result.files.map((f) => f.name), ['ok.zip']);
    assert.equal(result.error, 'big.zip is larger than 10 MB.');
});

test('queueFiles does not change the current list', () => {
    const current = [file('a.png')];
    queueFiles(current, [file('b.png')], limits);
    assert.equal(current.length, 1);
});

test('queueFiles accepts a FileList-like iterable or nothing', () => {
    assert.deepEqual(queueFiles([], null, limits), { files: [], error: null });
    const set = new Set([file('x.txt')]);
    assert.equal(queueFiles([], set, limits).files.length, 1);
});

test('uploadErrorMessage prefers the text error, then the first file error', () => {
    const response = (status, errors) => ({ response: { status, data: { errors } } });

    assert.equal(uploadErrorMessage(response(422, { content: ['Type a message first.'] })), 'Type a message first.');
    assert.equal(
        uploadErrorMessage(response(422, { 'attachments.1': ['b.exe is not a file type you can upload.'], 'attachments.0': ['a.svg is not a file type you can upload.'] })),
        'a.svg is not a file type you can upload.',
    );
    assert.equal(
        uploadErrorMessage(response(422, { attachments: ['You can attach at most 5 files to a message.'] })),
        'You can attach at most 5 files to a message.',
    );
    assert.match(uploadErrorMessage(response(413, {})), /too large/);
    assert.match(uploadErrorMessage(response(429, {})), /too quickly/);
    assert.match(uploadErrorMessage(response(403, {})), /cannot send/);
    assert.equal(uploadErrorMessage(new Error('network')), 'Your message could not be sent. Try again.');
});
