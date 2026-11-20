// Attachment helpers of the channel page. Run with `npm test`
// (node --test, no deps). The Blade markup is covered by
// tests/Feature/Messages/AttachmentTest.php; renderAttachments() is
// checked here against a minimal fake DOM.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    formatSize,
    previewSize,
    queueFiles,
    renderAttachments,
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

// --- renderAttachments, with a minimal fake DOM -----------------------
//
// Just enough of `document` for el(): nodes record textContent and
// attributes. innerHTML throws, so a test fails loudly if the renderer
// ever tried to parse a file name as HTML.

function fakeNode(tag) {
    return {
        tagName: tag.toUpperCase(),
        className: '',
        textContent: '',
        dataset: {},
        attributes: {},
        children: [],
        setAttribute(name, value) { this.attributes[name] = String(value); },
        appendChild(child) { this.children.push(child); return child; },
        append(...children) { this.children.push(...children); },
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
const byTag = (tag) => (node) => node.tagName === tag;

const imagePayload = {
    id: 1,
    name: 'cat.png',
    size: 2048,
    mime_type: 'image/png',
    is_image: true,
    width: 1600,
    height: 600,
    url: '/attachments/1/cat.png?expires=1&signature=a',
    download_url: '/attachments/1/cat.png?download=1&expires=1&signature=b',
};
const filePayload = {
    id: 2,
    name: 'report.pdf',
    size: 1536 * 1024,
    mime_type: 'application/pdf',
    is_image: false,
    width: null,
    height: null,
    url: '/attachments/2/report.pdf?expires=1&signature=c',
    download_url: '/attachments/2/report.pdf?download=1&expires=1&signature=d',
};

test('renderAttachments returns null without attachments', () => {
    withFakeDocument(() => {
        assert.equal(renderAttachments(undefined), null);
        assert.equal(renderAttachments(null), null);
        assert.equal(renderAttachments([]), null);
        assert.equal(renderAttachments('nope'), null);
    });
});

test('renderAttachments shows images as a sized, lazy preview with a download link', () => {
    const list = withFakeDocument(() => renderAttachments([imagePayload]));

    assert.equal(list.tagName, 'UL');
    assert.equal(list.attributes['aria-label'], 'Attachments');
    assert.ok('messageAttachments' in list.dataset);
    assert.equal(list.children.length, 1);

    const item = list.children[0];
    assert.ok('attachmentImage' in item.dataset);

    const img = find(item, byTag('IMG'));
    assert.equal(img.src, imagePayload.url);
    assert.equal(img.alt, 'cat.png');
    assert.equal(img.loading, 'lazy');
    assert.equal(img.width, 400);
    assert.equal(img.height, 150);

    const [open, download] = item.children;
    assert.equal(open.href, imagePayload.url);
    assert.equal(open.target, '_blank');
    assert.equal(open.rel, 'noopener');
    assert.equal(download.href, imagePayload.download_url);
    assert.ok('attachmentDownload' in download.dataset);
});

test('renderAttachments leaves the size off images without dimensions', () => {
    const list = withFakeDocument(() => renderAttachments([{ ...imagePayload, width: null, height: null }]));
    const img = find(list, byTag('IMG'));

    assert.equal(img.width, undefined);
    assert.equal(img.height, undefined);
});

test('renderAttachments shows other files as a card linking to the download URL', () => {
    const list = withFakeDocument(() => renderAttachments([filePayload]));
    const item = list.children[0];

    assert.ok('attachmentFile' in item.dataset);
    assert.equal(find(item, byTag('IMG')), null);

    const link = find(item, byTag('A'));
    assert.equal(link.href, filePayload.download_url);
    assert.equal(link.textContent, 'report.pdf');
    assert.ok(find(item, (node) => node.textContent === '1.5 MB'));
});

test('renderAttachments keeps the order and mixes previews and cards', () => {
    const list = withFakeDocument(() => renderAttachments([filePayload, imagePayload, filePayload]));

    assert.deepEqual(
        list.children.map((item) => ('attachmentImage' in item.dataset ? 'image' : 'file')),
        ['file', 'image', 'file'],
    );
});

test('renderAttachments inserts file names as text, never as HTML', () => {
    const name = '<img src=x onerror=alert(1)>.pdf';
    const list = withFakeDocument(() => renderAttachments([
        { ...filePayload, name },
        { ...imagePayload, name: '"><script>alert(1)</script>.png' },
    ]));

    assert.equal(find(list.children[0], byTag('A')).textContent, name);
    assert.equal(find(list.children[1], byTag('IMG')).alt, '"><script>alert(1)</script>.png');
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
