/**
 * Message markdown, the same parser as App\Support\Markdown:
 *
 *   **bold**  *italic* / _italic_  __underline__  ~~strike~~
 *   `code`  ```lang\ncode block```
 *   > quote (every line starting with "> ")   >>> quote (the rest)
 *   \* escapes a symbol; https://… and <https://…> become links
 *
 * parseMarkdown() turns the text into a tree of known nodes and
 * renderMarkdown() builds DOM nodes from it with createElement /
 * createTextNode, so message text is never parsed as HTML. Only http(s)
 * URLs become links. Keep in sync with the PHP class: both are checked
 * against tests/fixtures/markdown.json.
 */

export const MAX_DEPTH = 8;
export const LINK_REL = 'noopener noreferrer nofollow ugc';

// Whitespace is spelled out ([ \t\r\n]) so PHP and JS agree on it.
const QUOTE_REST = /^>>> ([\s\S]*)$/u;
const QUOTE_LINES = /^(?:> [^\n]*(?:\n|$))+/u;
const CODE_BLOCK = /^```(?:([A-Za-z0-9_+\-.#]+)\n)?\n*([^\n][\s\S]*?)\n*```\n?/u;
const CODE = /^(`+)([\s\S]*?[^`])\1(?!`)/u;
const ESCAPE = /^\\([^\p{L}\p{N} \t\r\n])/u;
const AUTOLINK = /^<(https?:\/\/[^ \t\r\n<>]+)>/iu;
const URL_PATTERN = /^https?:\/\/[^ \t\r\n<]*[^<.,:;"'`)\]!?*_~| \t\r\n]/iu;
const INLINE_RULES = [
    ['strong', /^\*\*([\s\S]+?)\*\*(?!\*)/u],
    ['underline', /^__([\s\S]+?)__(?!_)/u],
    ['em', /^\*(?=[^ \t\r\n])((?:\*\*|\\[\s\S]|[ \t\r\n]+(?:\\[\s\S]|[^ \t\r\n*\\]|\*\*)|[^ \t\r\n*\\])+?)\*(?!\*)/u],
    ['em', /^_((?:__|\\[\s\S]|[^\\_])+?)_(?![\p{L}\p{N}_])/u],
    ['strike', /^~~([\s\S]+?)~~/u],
];
const WORD = /^[\p{L}\p{N}]+(?:_+[\p{L}\p{N}]+)*/u;
const CHAR = /^[\s\S]/u;

const trim = (text) => text.replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, '');

function next(rest, depth, quotes) {
    const nested = depth < MAX_DEPTH;
    let m;

    if (quotes && nested) {
        if ((m = QUOTE_REST.exec(rest))) {
            return [{ type: 'quote', children: parseNodes(m[1], depth + 1, false) }, m[0].length];
        }
        if ((m = QUOTE_LINES.exec(rest))) {
            const inner = m[0].replace(/\n+$/, '').split('\n').map((line) => line.slice(2)).join('\n');
            return [{ type: 'quote', children: parseNodes(inner, depth + 1, false) }, m[0].length];
        }
    }

    if ((m = CODE_BLOCK.exec(rest))) return [{ type: 'codeblock', language: m[1] || null, text: m[2] }, m[0].length];
    if ((m = CODE.exec(rest))) return [{ type: 'code', text: trim(m[2]) }, m[0].length];
    if ((m = ESCAPE.exec(rest))) return [{ type: 'text', text: m[1] }, m[0].length];
    if ((m = AUTOLINK.exec(rest))) return [{ type: 'link', href: m[1], text: m[1] }, m[0].length];
    if ((m = URL_PATTERN.exec(rest))) return [{ type: 'link', href: m[0], text: m[0] }, m[0].length];

    if (nested) {
        for (const [type, pattern] of INLINE_RULES) {
            if ((m = pattern.exec(rest))) return [{ type, children: parseNodes(m[1], depth + 1, false) }, m[0].length];
        }
    }

    m = WORD.exec(rest) ?? CHAR.exec(rest);
    return [{ type: 'text', text: m[0] }, m[0].length];
}

function parseNodes(source, depth, quotes) {
    const nodes = [];
    let rest = source;
    let lineStart = true;

    while (rest !== '') {
        const [node, length] = next(rest, depth, quotes && lineStart);
        lineStart = rest[length - 1] === '\n';
        rest = rest.slice(length);
        const last = nodes[nodes.length - 1];

        if (node.type === 'text') {
            if (last?.type === 'text') last.text += node.text;
            else nodes.push(node);
            continue;
        }

        // A block starts on its own line anyway: drop the line break before it.
        if ((node.type === 'codeblock' || node.type === 'quote') && last?.type === 'text' && last.text.endsWith('\n')) {
            last.text = last.text.slice(0, -1);
            if (last.text === '') nodes.pop();
        }

        nodes.push(node);
    }

    return nodes;
}

/**
 * The tree of a message's text, like App\Support\Markdown::parse().
 */
export function parseMarkdown(source) {
    const text = String(source ?? '');
    return text === '' ? [] : parseNodes(text, 0, true);
}

/**
 * Whether the tree is plain text only (nothing to format).
 */
export function isPlain(nodes) {
    return nodes.length === 0 || (nodes.length === 1 && nodes[0].type === 'text');
}

const TAGS = { strong: 'strong', em: 'em', underline: 'u', strike: 's', quote: 'blockquote' };

function renderNode(node, doc) {
    if (node.type === 'text') return doc.createTextNode(node.text);

    if (node.type === 'code') {
        const code = doc.createElement('code');
        code.appendChild(doc.createTextNode(node.text));
        return code;
    }

    if (node.type === 'codeblock') {
        const pre = doc.createElement('pre');
        const code = doc.createElement('code');
        if (node.language) code.setAttribute('data-language', node.language);
        code.appendChild(doc.createTextNode(node.text));
        pre.appendChild(code);
        return pre;
    }

    if (node.type === 'link') {
        const link = doc.createElement('a');
        link.setAttribute('href', node.href);
        link.setAttribute('target', '_blank');
        link.setAttribute('rel', LINK_REL);
        link.appendChild(doc.createTextNode(node.text));
        return link;
    }

    const element = doc.createElement(TAGS[node.type] ?? 'span');
    (node.children ?? []).forEach((child) => element.appendChild(renderNode(child, doc)));
    return element;
}

/**
 * DOM nodes for a message's text, like App\Support\Markdown::toHtml().
 */
export function renderMarkdown(source, doc = globalThis.document) {
    return parseMarkdown(source).map((node) => renderNode(node, doc));
}

/**
 * Fill a [data-message-content] element with a message's formatted text.
 * The raw text is kept in data-source (for editing). Plain text is set as
 * textContent, so there is nothing to build.
 */
export function fillMessageContent(element, source, doc = globalThis.document) {
    const text = String(source ?? '');
    element.dataset.source = text;
    const nodes = parseMarkdown(text);
    element.replaceChildren();
    if (isPlain(nodes)) {
        element.textContent = text;
        return;
    }
    element.textContent = '';
    nodes.forEach((node) => element.appendChild(renderNode(node, doc)));
}
