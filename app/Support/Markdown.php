<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Message markdown (ISSUE-36): a small, Discord-like subset that is safe
 * by construction. Message text is never treated as HTML: parse() turns it
 * into a tree of known nodes, and toHtml() writes only the tags below,
 * escaping every piece of text and every attribute with e(). So "<b>" or
 * "<script>" in a message shows up as typed, and a link is only made from
 * an http(s) URL.
 *
 *   **bold**  *italic* / _italic_  __underline__  ~~strike~~
 *   `code`  ```lang\ncode block```
 *   > quote (every line starting with "> ")   >>> quote (the rest)
 *   \* escapes a symbol; https://… and <https://…> become links
 *
 * Headings, lists, images, masked links ([text](url)) and raw HTML are
 * not supported: they are left as plain text.
 *
 * resources/js/markdown.js is the same parser and builds DOM nodes for the
 * messages that arrive live. Keep the two in sync: both are checked
 * against tests/fixtures/markdown.json (MarkdownTest.php, markdown.test.js).
 *
 * @phpstan-type Node array{type: string, text?: string, href?: string, language?: string|null, children?: list<array<string, mixed>>}
 */
final class Markdown
{
    /** Formatting nested deeper than this is left as plain text. */
    public const MAX_DEPTH = 8;

    /** rel of every link: a new tab, no referrer, not endorsed. */
    public const LINK_REL = 'noopener noreferrer nofollow ugc';

    // Whitespace is spelled out ([ \t\r\n]) so PHP and JS agree on it.
    private const QUOTE_REST = '/^>>> ([\s\S]*)$/u';

    private const QUOTE_LINES = '/^(?:> [^\n]*(?:\n|$))+/u';

    private const CODE_BLOCK = '/^```(?:([A-Za-z0-9_+\-.#]+)\n)?\n*([^\n][\s\S]*?)\n*```\n?/u';

    private const CODE = '/^(`+)([\s\S]*?[^`])\1(?!`)/u';

    private const ESCAPE = '/^\\\\([^\p{L}\p{N} \t\r\n])/u';

    private const AUTOLINK = '/^<(https?:\/\/[^ \t\r\n<>]+)>/iu';

    private const URL = '/^https?:\/\/[^ \t\r\n<]*[^<.,:;"\'`)\]!?*_~| \t\r\n]/iu';

    private const STRONG = '/^\*\*([\s\S]+?)\*\*(?!\*)/u';

    private const UNDERLINE = '/^__([\s\S]+?)__(?!_)/u';

    private const EM_STAR = '/^\*(?=[^ \t\r\n])((?:\*\*|\\\\[\s\S]|[ \t\r\n]+(?:\\\\[\s\S]|[^ \t\r\n*\\\\]|\*\*)|[^ \t\r\n*\\\\])+?)\*(?!\*)/u';

    private const EM_UNDERSCORE = '/^_((?:__|\\\\[\s\S]|[^\\\\_])+?)_(?![\p{L}\p{N}_])/u';

    private const STRIKE = '/^~~([\s\S]+?)~~/u';

    private const WORD = '/^[\p{L}\p{N}]+(?:_+[\p{L}\p{N}]+)*/u';

    private const CHAR = '/^[\s\S]/u';

    /**
     * The message as HTML, safe to print unescaped ({!! !!}).
     */
    public static function toHtml(?string $source): HtmlString
    {
        return new HtmlString(self::render(self::parse((string) $source)));
    }

    /**
     * @return list<Node>
     */
    public static function parse(string $source): array
    {
        if ($source === '') {
            return [];
        }

        // The patterns need valid UTF-8 (Laravel's validation ensures it).
        if (! mb_check_encoding($source, 'UTF-8')) {
            return [['type' => 'text', 'text' => $source]];
        }

        return self::parseNodes($source, 0, true);
    }

    /**
     * @return list<Node>
     */
    private static function parseNodes(string $source, int $depth, bool $quotes): array
    {
        $nodes = [];
        $rest = $source;
        $lineStart = true;

        while ($rest !== '') {
            [$node, $length] = self::next($rest, $depth, $quotes && $lineStart);
            $lineStart = substr($rest, $length - 1, 1) === "\n";
            $rest = substr($rest, $length);

            $last = array_key_last($nodes);

            if ($node['type'] === 'text') {
                if ($last !== null && $nodes[$last]['type'] === 'text') {
                    $nodes[$last]['text'] = ($nodes[$last]['text'] ?? '').($node['text'] ?? '');
                } else {
                    $nodes[] = $node;
                }

                continue;
            }

            // A block starts on its own line anyway: drop the line break before it.
            if (in_array($node['type'], ['codeblock', 'quote'], true) && $last !== null && $nodes[$last]['type'] === 'text' && str_ends_with($nodes[$last]['text'] ?? '', "\n")) {
                $nodes[$last]['text'] = substr($nodes[$last]['text'] ?? '', 0, -1);

                if ($nodes[$last]['text'] === '') {
                    array_pop($nodes);
                }
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * The node at the start of $rest and how many bytes it takes.
     *
     * @return array{0: Node, 1: int}
     */
    private static function next(string $rest, int $depth, bool $quotes): array
    {
        $nested = $depth < self::MAX_DEPTH;

        if ($quotes && $nested) {
            if (preg_match(self::QUOTE_REST, $rest, $m) === 1) {
                return [['type' => 'quote', 'children' => self::parseNodes($m[1], $depth + 1, false)], strlen($m[0])];
            }

            if (preg_match(self::QUOTE_LINES, $rest, $m) === 1) {
                $lines = explode("\n", rtrim($m[0], "\n"));
                $inner = implode("\n", array_map(fn (string $line): string => substr($line, 2), $lines));

                return [['type' => 'quote', 'children' => self::parseNodes($inner, $depth + 1, false)], strlen($m[0])];
            }
        }

        if (preg_match(self::CODE_BLOCK, $rest, $m) === 1) {
            return [['type' => 'codeblock', 'language' => $m[1] === '' ? null : $m[1], 'text' => $m[2]], strlen($m[0])];
        }

        if (preg_match(self::CODE, $rest, $m) === 1) {
            return [['type' => 'code', 'text' => self::trim($m[2])], strlen($m[0])];
        }

        if (preg_match(self::ESCAPE, $rest, $m) === 1) {
            return [['type' => 'text', 'text' => $m[1]], strlen($m[0])];
        }

        if (preg_match(self::AUTOLINK, $rest, $m) === 1) {
            return [['type' => 'link', 'href' => $m[1], 'text' => $m[1]], strlen($m[0])];
        }

        if (preg_match(self::URL, $rest, $m) === 1) {
            return [['type' => 'link', 'href' => $m[0], 'text' => $m[0]], strlen($m[0])];
        }

        if ($nested) {
            $rules = [
                'strong' => self::STRONG,
                'underline' => self::UNDERLINE,
                'em' => self::EM_STAR,
                'em_' => self::EM_UNDERSCORE,
                'strike' => self::STRIKE,
            ];

            foreach ($rules as $type => $pattern) {
                if (preg_match($pattern, $rest, $m) === 1) {
                    return [['type' => rtrim($type, '_'), 'children' => self::parseNodes($m[1], $depth + 1, false)], strlen($m[0])];
                }
            }
        }

        if (preg_match(self::WORD, $rest, $m) === 1 || preg_match(self::CHAR, $rest, $m) === 1) {
            return [['type' => 'text', 'text' => $m[0]], strlen($m[0])];
        }

        // Not reached for valid UTF-8; always make progress.
        return [['type' => 'text', 'text' => $rest[0]], 1];
    }

    private static function trim(string $text): string
    {
        return (string) preg_replace('/^[ \t\r\n]+|[ \t\r\n]+$/', '', $text);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private static function render(array $nodes): string
    {
        $html = '';

        foreach ($nodes as $node) {
            $text = e((string) ($node['text'] ?? ''));
            $children = fn (): string => self::render($node['children'] ?? []);

            $html .= match ($node['type']) {
                'text' => $text,
                'code' => '<code>'.$text.'</code>',
                'codeblock' => '<pre><code'.(isset($node['language']) ? ' data-language="'.e((string) $node['language']).'"' : '').'>'.$text.'</code></pre>',
                'link' => '<a href="'.e((string) ($node['href'] ?? '')).'" target="_blank" rel="'.self::LINK_REL.'">'.$text.'</a>',
                'strong' => '<strong>'.$children().'</strong>',
                'em' => '<em>'.$children().'</em>',
                'underline' => '<u>'.$children().'</u>',
                'strike' => '<s>'.$children().'</s>',
                'quote' => '<blockquote>'.$children().'</blockquote>',
                default => $text,
            };
        }

        return $html;
    }
}
