<?php

namespace Tests\Feature\Layout;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Text colours in the views and scripts stay readable (WCAG AA)
 * in both themes. The token values themselves are checked by
 * tests/js/contrast.test.js.
 */
class ContrastTest extends TestCase
{
    /** @return array<string, string> relative path => contents */
    private function sources(): array
    {
        $sources = [];
        $files = Finder::create()->files()->in([resource_path('views'), resource_path('js')])->name(['*.blade.php', '*.js']);
        foreach ($files as $file) {
            $sources[$file->getRelativePathname()] = $file->getContents();
        }

        return $sources;
    }

    /**
     * Red text from Tailwind's palette is too light on the light theme's
     * backgrounds; text-danger-fg is the red for both themes.
     */
    public function test_red_text_uses_the_danger_token(): void
    {
        $offenders = [];
        foreach ($this->sources() as $path => $contents) {
            if (preg_match_all('/(?<![\w-])(?:hover:)?text-(?:red-[45]00|\[#f23f43\])(?![\w-])/i', $contents, $m)) {
                $offenders[] = $path.': '.implode(', ', array_unique($m[0]));
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * Placeholders are text too: fg-dim and fg-faint are below 4.5:1.
     */
    public function test_placeholders_use_a_readable_token(): void
    {
        $offenders = [];
        foreach ($this->sources() as $path => $contents) {
            if (preg_match('/placeholder-fg-(?:dim|faint)\b/', $contents)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * Alert boxes with light text on a dark tint (text-red-300 on
     * bg-red-900/40, ...) need a light theme variant, or they turn into
     * pale text on white.
     */
    public function test_dark_only_alert_colours_have_a_light_variant(): void
    {
        $offenders = [];
        foreach ($this->sources() as $path => $contents) {
            preg_match_all('/class="([^"]*)"/', $contents, $classes);
            foreach ($classes[1] as $list) {
                if (preg_match('/(?<![\w:-])text-(?:red|green|yellow|amber)-[123]00\b/', $list) && ! str_contains($list, 'in-data-[theme=light]:text-')) {
                    $offenders[] = $path.': '.$list;
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * fg-faint only reaches 3:1, enough for decorative icons but not for
     * text, so whatever uses it is hidden from screen readers.
     */
    public function test_the_faint_token_is_only_used_for_decorative_icons(): void
    {
        $offenders = [];
        foreach ($this->sources() as $path => $contents) {
            $lines = explode("\n", $contents);
            foreach ($lines as $index => $line) {
                if (! str_contains($line, 'text-fg-faint')) {
                    continue;
                }
                $next = $lines[$index + 1] ?? '';
                if (! str_contains($line, 'aria-hidden="true"') && ! str_contains($next, "setAttribute('aria-hidden', 'true')")) {
                    $offenders[] = $path.':'.($index + 1);
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
