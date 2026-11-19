<?php

namespace Tests\Unit;

use App\Support\Markdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ISSUE-36: message markdown (App\Support\Markdown). The cases come from
 * tests/fixtures/markdown.json, which tests/js/markdown.test.js checks
 * resources/js/markdown.js against too, so both render the same.
 */
class MarkdownTest extends TestCase
{
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
