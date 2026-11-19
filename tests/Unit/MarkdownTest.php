<?php

namespace Tests\Unit;

use App\Support\Markdown;
use Illuminate\Contracts\Support\Htmlable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\DocumentsKnownBugs;

/**
 * ISSUE-36: message markdown (App\Support\Markdown). The cases come from
 * tests/fixtures/markdown.json, which tests/js/markdown.test.js checks
 * resources/js/markdown.js against too, so both render the same.
 */
class MarkdownTest extends TestCase
{
    use DocumentsKnownBugs;

    /**
     * @return array<string, array{string, string}>
     */
    public static function fixtures(): array
    {
        $cases = [];

        /** @var list<array{name: string, input: string, html: string}> $fixtures */
        $fixtures = json_decode((string) file_get_contents(__DIR__.'/../fixtures/markdown.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($fixtures as $fixture) {
            $cases[$fixture['name']] = [$fixture['input'], $fixture['html']];
        }

        return $cases;
    }

    #[DataProvider('fixtures')]
    public function test_fixture(string $input, string $html): void
    {
        $this->assertSame($html, Markdown::toHtml($input)->toHtml());
    }

    public function test_null_renders_nothing(): void
    {
        $this->assertSame('', Markdown::toHtml(null)->toHtml());
    }

    /**
     * The output may only hold the tags and attributes the renderer writes,
     * whatever the input: a sanitiser's allowlist, checked on hostile text.
     */
    public function test_hostile_input_only_produces_allowed_markup(): void
    {
        $inputs = [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            '**<a href="javascript:alert(1)">x</a>**',
            '[x](javascript:alert(1)) [y](data:text/html,<b>)',
            'https://example.com/"><script>alert(1)</script>',
            '<https://example.com/" onclick="alert(1)>',
            "```html\n<iframe src=//evil></iframe>\n```",
            '> <svg/onload=alert(1)>',
            '`</code><script>x</script>`',
            '<<https://a.b>>',
            'javascript://example.com/%0aalert(1)',
            'JAVASCRIPT:alert(1) HTTPS://EXAMPLE.COM',
            "&#x3C;script&#x3E; &lt;script&gt; \u{0000}",
        ];

        foreach ($inputs as $input) {
            $this->assertAllowedMarkup($input);
        }
    }

    private function assertAllowedMarkup(string $input): void
    {
        $html = Markdown::toHtml($input)->toHtml();

        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        preg_match_all('/<(\/?)([a-z0-9]+)([^>]*)>/i', $html, $tags, PREG_SET_ORDER);

        foreach ($tags as [$whole, $closing, $tag, $rest]) {
            $this->assertContains($tag, ['strong', 'em', 'u', 's', 'code', 'pre', 'blockquote', 'a'], "Unexpected <{$tag}> for {$input}");

            preg_match_all('/ ([a-z-]+)="([^"]*)"/', $rest, $attributes, PREG_SET_ORDER);
            $this->assertSame('', preg_replace('/ [a-z-]+="[^"]*"/', '', $rest), "Stray attribute text in {$whole}");

            $names = array_column($attributes, 1);
            $expected = match (true) {
                $closing === '/' => [],
                $tag === 'a' => ['href', 'target', 'rel'],
                $tag === 'code' => array_intersect($names, ['data-language']),
                default => [],
            };
            $this->assertSame(array_values($expected), $names, "Unexpected attributes in {$whole}");

            foreach ($attributes as [, $name, $value]) {
                if ($name === 'href') {
                    $this->assertMatchesRegularExpression('/^https?:\/\//i', html_entity_decode($value, ENT_QUOTES), "Unsafe link for {$input}");
                }
            }
        }
    }

    /**
     * Seeded random input built from markdown symbols, URLs and HTML
     * characters: the same every run, so a failure can be replayed.
     *
     * @return list<string>
     */
    private static function fuzzInputs(int $count): array
    {
        $alphabet = ['*', '**', '_', '__', '~~', '`', '```', '> ', '>>> ', "\n", "\r\n", '\\', ' ', "\t", 'a', 'b', 'é', '😀', 'js', 'https://x.y/', 'http://a.b', 'javascript:', '<', '>', '&', '"', "'", '.', '(', ')', '[', ']', '|', '#', '<b>', '</code>', '<https://e.f/'];
        $seed = 36;
        $inputs = [];

        for ($i = 0; $i < $count; $i++) {
            $seed = ($seed * 1103515245 + 12345) % 2147483648;
            $length = 1 + $seed % 16;
            $input = '';

            for ($j = 0; $j < $length; $j++) {
                $seed = ($seed * 1103515245 + 12345) % 2147483648;
                $input .= $alphabet[$seed % count($alphabet)];
            }

            $inputs[] = $input;
        }

        return $inputs;
    }

    /**
     * Whatever the input, the output is well-formed (every tag closed in
     * order, no raw "<" or "&") and only holds the allowed markup.
     */
    public function test_fuzzed_input_renders_balanced_allowed_markup(): void
    {
        $previous = libxml_use_internal_errors(true);

        try {
            foreach (self::fuzzInputs(2000) as $input) {
                $html = Markdown::toHtml($input)->toHtml();
                $xml = '<r>'.str_replace('&#039;', '&apos;', $html).'</r>';

                $this->assertNotFalse(simplexml_load_string($xml), 'Not well-formed for '.json_encode($input).': '.$html);
                $this->assertAllowedMarkup($input);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * The text a reader sees is never more than what was typed: rendering
     * only drops markup symbols, it never adds characters.
     */
    public function test_the_visible_text_only_comes_from_the_input(): void
    {
        foreach (self::fuzzInputs(2000) as $input) {
            $visible = html_entity_decode(strip_tags(Markdown::toHtml($input)->toHtml()), ENT_QUOTES | ENT_HTML5);

            $this->assertLessThanOrEqual(mb_strlen($input), mb_strlen($visible), 'Text grew for '.json_encode($input));
        }
    }

    public function test_text_without_markup_symbols_is_only_escaped(): void
    {
        $input = "Hello there, it's 5 o'clock & 2 + 2 = 4.\nSecond line; done!";

        $this->assertSame(e($input), Markdown::toHtml($input)->toHtml());
        $this->assertSame([['type' => 'text', 'text' => $input]], Markdown::parse($input));
    }

    public function test_parse_returns_the_node_tree(): void
    {
        $this->assertSame([], Markdown::parse(''));
        $this->assertSame([['type' => 'codeblock', 'language' => null, 'text' => 'x']], Markdown::parse("```\nx\n```"));
        $this->assertSame([['type' => 'codeblock', 'language' => 'php', 'text' => 'x']], Markdown::parse("```php\nx\n```"));
        $this->assertSame([['type' => 'link', 'href' => 'https://a.b', 'text' => 'https://a.b']], Markdown::parse('<https://a.b>'));
        $this->assertSame([
            ['type' => 'quote', 'children' => [['type' => 'strong', 'children' => [['type' => 'text', 'text' => 'x']]]]],
        ], Markdown::parse('> **x**'));
    }

    public function test_to_html_returns_an_html_string_blade_will_not_escape_again(): void
    {
        $html = Markdown::toHtml('**a** & b');

        $this->assertInstanceOf(Htmlable::class, $html);
        $this->assertSame('<strong>a</strong> &amp; b', (string) $html);
    }

    /**
     * Discord and most chat apps keep a balanced closing parenthesis in a
     * URL (Wikipedia links). Here the ")" is always cut off, so the link
     * points to a page that does not exist.
     */
    public function test_a_url_keeps_a_balanced_closing_parenthesis(): void
    {
        // An unbalanced one (a URL in parentheses) is left out, as it should be.
        $this->assertSame(
            '(<a href="https://a.b" target="_blank" rel="noopener noreferrer nofollow ugc">https://a.b</a>)',
            Markdown::toHtml('(https://a.b)')->toHtml(),
        );

        $this->knownBug('URL regex always drops a trailing ")" even when it closes a "(" in the URL', function (): void {
            $this->assertSame(
                '<a href="https://en.wikipedia.org/wiki/Foo_(bar)" target="_blank" rel="noopener noreferrer nofollow ugc">https://en.wikipedia.org/wiki/Foo_(bar)</a>',
                Markdown::toHtml('https://en.wikipedia.org/wiki/Foo_(bar)')->toHtml(),
            );
        });
    }

    public function test_links_open_in_a_new_tab_without_a_referrer(): void
    {
        $this->assertSame(
            '<a href="https://example.com" target="_blank" rel="noopener noreferrer nofollow ugc">https://example.com</a>',
            Markdown::toHtml('https://example.com')->toHtml(),
        );
    }

    public function test_invalid_utf8_is_left_as_plain_text(): void
    {
        $this->assertSame([['type' => 'text', 'text' => "**\xFF**"]], Markdown::parse("**\xFF**"));
        $this->assertStringNotContainsString('<strong>', Markdown::toHtml("**\xFF**")->toHtml());
    }

    public function test_long_or_unclosed_input_is_parsed_quickly(): void
    {
        $started = microtime(true);

        foreach ([str_repeat('**', 1000), str_repeat('*a ', 666), str_repeat('`', 2000), str_repeat('> ', 1000), str_repeat('_', 2000), str_repeat('~~a', 666)] as $input) {
            $this->assertNotSame('', Markdown::toHtml($input)->toHtml());
        }

        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    public function test_formatting_nests_inside_a_quote(): void
    {
        $this->assertSame(
            '<blockquote><strong><u><em><s><em>x</em></s></em></u></strong></blockquote>',
            Markdown::toHtml('> **__*~~_x_~~*__**')->toHtml(),
        );
    }
}
