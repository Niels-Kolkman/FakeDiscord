// ISSUE-36: message markdown (resources/js/markdown.js). Run with `npm test`.
// The same cases as tests/Feature/Messages/MarkdownTest.php, read from
// tests/fixtures/markdown.json, so the browser builds exactly what
// App\Support\Markdown renders on the server. The DOM nodes are built in
// the shared fake DOM (innerHTML throws) and written out as HTML here.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { fakeDocument, fakeElement, textOf } from './helpers/fake-dom.js';
import { fillMessageContent, isPlain, LINK_REL, MAX_DEPTH, parseMarkdown, renderMarkdown } from '../../resources/js/markdown.js';
import { renderMessage } from '../../resources/js/channel-messages.js';

const fixtures = JSON.parse(readFileSync(new URL('../fixtures/markdown.json', import.meta.url), 'utf8'));

// Like PHP's e(): htmlspecialchars with ENT_QUOTES.
const escape = (text) => String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

function toHtml(node) {
    if (!node.tagName) return escape(node.textContent);
    const tag = node.tagName.toLowerCase();
    const attributes = Object.entries(node.attributes).map(([name, value]) => ` ${name}="${escape(value)}"`).join('');
    return `<${tag}${attributes}>${node.children.map(toHtml).join('')}</${tag}>`;
}

const doc = fakeDocument();

for (const fixture of fixtures) {
    test(`markdown: ${fixture.name}`, () => {
        assert.equal(renderMarkdown(fixture.input, doc).map(toHtml).join(''), fixture.html);
    });
}

test('the fixtures cover every node type', () => {
    const types = new Set();
    const walk = (nodes) => nodes.forEach((node) => {
        types.add(node.type);
        walk(node.children ?? []);
    });
    fixtures.forEach((fixture) => walk(parseMarkdown(fixture.input)));
    assert.deepEqual([...types].sort(), ['code', 'codeblock', 'em', 'link', 'quote', 'strike', 'strong', 'text', 'underline']);
});

test('only http and https URLs become links, with a safe rel', () => {
    const links = [];
    const walk = (nodes) => nodes.forEach((node) => {
        if (node.type === 'link') links.push(node.href);
        walk(node.children ?? []);
    });
    fixtures.forEach((fixture) => walk(parseMarkdown(fixture.input)));
    assert.ok(links.length > 0);
    links.forEach((href) => assert.match(href, /^https?:\/\//i));
    assert.equal(LINK_REL, 'noopener noreferrer nofollow ugc');
});

test('every kind of formatting can be nested inside a quote', () => {
    // Quote > strong > underline > em > strike > em is as deep as the rules go.
    const html = renderMarkdown('> **__*~~_x_~~*__**', doc).map(toHtml).join('');
    assert.equal(html, '<blockquote><strong><u><em><s><em>x</em></s></em></u></strong></blockquote>');
    assert.ok(MAX_DEPTH >= 6);
});

test('long and unclosed input is parsed quickly', () => {
    const started = Date.now();
    parseMarkdown('**'.repeat(1000));
    parseMarkdown('*a '.repeat(666));
    parseMarkdown('`'.repeat(2000));
    parseMarkdown('> '.repeat(1000));
    parseMarkdown('_'.repeat(2000));
    assert.ok(Date.now() - started < 2000);
});

test('isPlain is true for text only', () => {
    assert.equal(isPlain(parseMarkdown('')), true);
    assert.equal(isPlain(parseMarkdown('just text, 2 * 3')), true);
    assert.equal(isPlain(parseMarkdown('**bold**')), false);
    assert.equal(isPlain(parseMarkdown('https://example.com')), false);
});

test('renderMessage formats live messages like messages/item.blade.php', () => {
    const previous = globalThis.document;
    globalThis.document = doc;
    try {
        const item = renderMessage({
            id: 5,
            channel_id: 1,
            content: '**hi** <b>x</b> https://example.com',
            created_at: '2026-01-01T10:00:00+00:00',
            author: { id: 2, name: 'Bob' },
            reactions: [],
            attachments: [],
        }, { viewerId: '9' });

        const body = item.querySelector('[data-message-body]');
        assert.equal(body.tagName, 'DIV');
        assert.match(body.className, /\bmessage-markdown\b/);
        const content = item.querySelector('[data-message-content]');
        assert.equal(content.dataset.source, '**hi** <b>x</b> https://example.com');
        assert.equal(
            content.children.map(toHtml).join(''),
            '<strong>hi</strong> &lt;b&gt;x&lt;/b&gt; <a href="https://example.com" target="_blank" rel="noopener noreferrer nofollow ugc">https://example.com</a>',
        );
    } finally {
        globalThis.document = previous;
    }
});

test('fillMessageContent keeps the source and sets plain text as textContent', () => {
    const element = fakeElement('span');
    fillMessageContent(element, '<b>hi</b>', doc);
    assert.equal(element.dataset.source, '<b>hi</b>');
    assert.equal(element.textContent, '<b>hi</b>');
    assert.equal(element.children.length, 0);
});

test('fillMessageContent builds nodes for formatted text and replaces old ones', () => {
    const element = fakeElement('span');
    fillMessageContent(element, 'a **b**', doc);
    assert.equal(element.children.map(toHtml).join(''), 'a <strong>b</strong>');
    assert.equal(textOf(element), 'a b');

    fillMessageContent(element, 'plain again', doc);
    assert.equal(element.children.length, 0);
    assert.equal(element.textContent, 'plain again');
    assert.equal(element.dataset.source, 'plain again');

    fillMessageContent(element, null, doc);
    assert.equal(element.dataset.source, '');
    assert.equal(element.textContent, '');
});
