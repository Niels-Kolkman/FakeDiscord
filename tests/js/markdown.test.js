// Message markdown (resources/js/markdown.js). Run with `npm test`.
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

// --- properties on hostile and random input -------------------------------------

const ALLOWED = { STRONG: [], EM: [], U: [], S: [], CODE: ['data-language'], PRE: [], BLOCKQUOTE: [], A: ['href', 'target', 'rel'] };

// The same seeded corpus as tests/Unit/MarkdownTest.php::fuzzInputs (BigInt,
// so the arithmetic matches PHP's 64-bit integers exactly).
function fuzzInputs(count) {
    const alphabet = ['*', '**', '_', '__', '~~', '`', '```', '> ', '>>> ', '\n', '\r\n', '\\', ' ', '\t', 'a', 'b', 'é', '😀', 'js', 'https://x.y/', 'http://a.b', 'javascript:', '<', '>', '&', '"', "'", '.', '(', ')', '[', ']', '|', '#', '<b>', '</code>', '<https://e.f/'];
    const size = BigInt(alphabet.length);
    let seed = 36n;
    const step = () => {
        seed = (seed * 1103515245n + 12345n) % 2147483648n;
        return seed;
    };
    const inputs = [];
    for (let i = 0; i < count; i += 1) {
        const length = 1 + Number(step() % 16n);
        let input = '';
        for (let j = 0; j < length; j += 1) input += alphabet[Number(step() % size)];
        inputs.push(input);
    }
    return inputs;
}

function assertAllowedNodes(nodes, input) {
    nodes.forEach((node) => {
        if (!node.tagName) {
            assert.equal(typeof node.textContent, 'string');
            return;
        }
        const allowed = ALLOWED[node.tagName];
        assert.ok(allowed, `Unexpected <${node.tagName}> for ${JSON.stringify(input)}`);
        Object.keys(node.attributes).forEach((name) => assert.ok(allowed.includes(name), `Unexpected ${name} on <${node.tagName}> for ${JSON.stringify(input)}`));
        assert.equal(node.className, '', 'no classes are set');
        if (node.tagName === 'A') {
            assert.match(node.getAttribute('href'), /^https?:\/\//i, `Unsafe link for ${JSON.stringify(input)}`);
            assert.equal(node.getAttribute('target'), '_blank');
            assert.equal(node.getAttribute('rel'), LINK_REL);
            assert.ok(node.children.every((child) => !child.tagName), 'a link only holds text');
        }
        if (node.tagName === 'CODE') {
            assert.ok(node.children.every((child) => !child.tagName), 'code only holds text');
        }
        assertAllowedNodes(node.children, input);
    });
}

test('hostile input only produces the allowed elements and attributes', () => {
    const inputs = [
        '<script>alert(1)</script>',
        '<img src=x onerror=alert(1)>',
        '**<a href="javascript:alert(1)">x</a>**',
        '[x](javascript:alert(1)) [y](data:text/html,<b>)',
        'https://example.com/"><script>alert(1)</script>',
        '<https://example.com/" onclick="alert(1)>',
        '```html\n<iframe src=//evil></iframe>\n```',
        '> <svg/onload=alert(1)>',
        '`</code><script>x</script>`',
        '<<https://a.b>>',
        'javascript://example.com/%0aalert(1)',
        'JAVASCRIPT:alert(1) HTTPS://EXAMPLE.COM',
        '&#x3C;script&#x3E; &lt;script&gt; \u0000',
        '```" onmouseover="x\ny\n```',
        '__proto__ constructor **__proto__**',
    ];
    inputs.forEach((input) => {
        const nodes = renderMarkdown(input, doc);
        assertAllowedNodes(nodes, input);
        assert.doesNotMatch(nodes.map(toHtml).join(''), /<script/i);
    });
});

test('a code block language only holds safe characters', () => {
    const [pre] = renderMarkdown('```js" onclick="x\ny\n```', doc);
    assert.equal(pre.children[0].getAttribute('data-language'), null);
    const [ok] = renderMarkdown('```c#\ny\n```', doc);
    assert.equal(ok.children[0].getAttribute('data-language'), 'c#');
});

test('fuzzed input renders only allowed markup and never adds text', () => {
    const inputs = fuzzInputs(2000);
    // The same first inputs as MarkdownTest::test_the_fuzz_corpus_is_the_one_the_js_tests_use.
    assert.deepEqual(inputs.slice(0, 3), [
        '" </code><</code> `\n**#]>😀\\',
        "http://a.b'(** ",
        '>">>> https://x.y/&*http://a.b] _"https://x.y/<._',
    ]);
    assert.ok(new Set(inputs).size > 1900);
    assert.ok(inputs.some((input) => input.includes('<https://e.f/')));
    assert.ok(inputs.some((input) => input.includes('javascript:')));
    inputs.forEach((input) => {
        const nodes = renderMarkdown(input, doc);
        assertAllowedNodes(nodes, input);
        const visible = nodes.map(textOf).join('');
        assert.ok([...visible].length <= [...input].length, `Text grew for ${JSON.stringify(input)}`);
    });
});

test('text without markup symbols is a single text node', () => {
    const input = "Hello there, it's 5 o'clock & 2 + 2 = 4.\nSecond line; done!";
    assert.deepEqual(parseMarkdown(input), [{ type: 'text', text: input }]);
    assert.equal(isPlain(parseMarkdown(input)), true);
});

test('parseMarkdown returns the node tree like Markdown::parse', () => {
    assert.deepEqual(parseMarkdown(null), []);
    assert.deepEqual(parseMarkdown(undefined), []);
    assert.deepEqual(parseMarkdown('```\nx\n```'), [{ type: 'codeblock', language: null, text: 'x' }]);
    assert.deepEqual(parseMarkdown('```php\nx\n```'), [{ type: 'codeblock', language: 'php', text: 'x' }]);
    assert.deepEqual(parseMarkdown('<https://a.b>'), [{ type: 'link', href: 'https://a.b', text: 'https://a.b' }]);
    assert.deepEqual(parseMarkdown('> **x**'), [{ type: 'quote', children: [{ type: 'strong', children: [{ type: 'text', text: 'x' }] }] }]);
    assert.deepEqual(parseMarkdown(42), [{ type: 'text', text: '42' }]);
});

test('isPlain is false for a single formatted node and for several nodes', () => {
    assert.equal(isPlain(parseMarkdown('`x`')), false);
    assert.equal(isPlain(parseMarkdown('> x')), false);
    assert.equal(isPlain([{ type: 'text', text: 'a' }, { type: 'text', text: 'b' }]), false);
});

// Discord keeps a balanced ")" in a URL (Wikipedia links); here it is cut off.
// Marked todo so it is reported without failing the suite.
test('a URL keeps a balanced closing parenthesis', { todo: 'URL regex always drops a trailing ")" even when it closes a "(" in the URL' }, () => {
    const [link] = renderMarkdown('https://en.wikipedia.org/wiki/Foo_(bar)', doc);
    assert.equal(link.getAttribute('href'), 'https://en.wikipedia.org/wiki/Foo_(bar)');
});

test('fillMessageContent replaces one formatted text with another', () => {
    const element = fakeElement('span');
    fillMessageContent(element, '**a**', doc);
    const first = element.children[0];
    fillMessageContent(element, '_b_ `c`', doc);
    assert.equal(element.children.map(toHtml).join(''), '<em>b</em> <code>c</code>');
    assert.equal(first.parentNode, null);
    assert.equal(element.dataset.source, '_b_ `c`');
});

test('fillMessageContent shows HTML-looking formatted text as text', () => {
    const element = fakeElement('span');
    fillMessageContent(element, '**<img src=x onerror=alert(1)>**', doc);
    assert.equal(element.children.map(toHtml).join(''), '<strong>&lt;img src=x onerror=alert(1)&gt;</strong>');
    assert.equal(element.dataset.source, '**<img src=x onerror=alert(1)>**');
});

test('renderMessage keeps an attachment-only message body empty', () => {
    const previous = globalThis.document;
    globalThis.document = doc;
    try {
        const item = renderMessage({
            id: 6,
            channel_id: 1,
            content: '',
            created_at: '2026-01-01T10:00:00+00:00',
            author: { id: 2, name: 'Bob' },
            reactions: [],
            attachments: [],
        }, { viewerId: '9' });
        const content = item.querySelector('[data-message-content]');
        assert.equal(content.dataset.source, '');
        assert.equal(content.textContent, '');
        assert.equal(content.children.length, 0);
    } finally {
        globalThis.document = previous;
    }
});
