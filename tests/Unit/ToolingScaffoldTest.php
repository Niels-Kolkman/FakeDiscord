<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ISSUE-5: Pint, Larastan and the GitHub Actions CI workflow are wired up.
 */
class ToolingScaffoldTest extends TestCase
{
    private function read(string $relative): string
    {
        $file = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $this->assertFileExists($file);

        return (string) file_get_contents($file);
    }

    public function test_composer_requires_pint_and_larastan_and_exposes_scripts(): void
    {
        $composer = json_decode($this->read('composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('laravel/pint', $composer['require-dev'] ?? []);
        $this->assertArrayHasKey('larastan/larastan', $composer['require-dev'] ?? []);

        $this->assertSame('pint --test', $composer['scripts']['lint'] ?? null);
        $this->assertStringStartsWith('phpstan analyse', $composer['scripts']['analyse'] ?? '');
    }

    public function test_pint_uses_the_laravel_preset(): void
    {
        $pint = json_decode($this->read('pint.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('laravel', $pint['preset'] ?? null);
    }

    public function test_larastan_is_loaded_and_analyses_app_and_tests(): void
    {
        $neon = $this->read('phpstan.neon');

        $this->assertStringContainsString('vendor/larastan/larastan/extension.neon', $neon);
        $this->assertMatchesRegularExpression('/^\s*level:\s*([5-9]|10|max)\s*$/m', $neon, 'Keep Larastan at level 5 or higher');
        $this->assertMatchesRegularExpression('/^\s*-\s*app\s*$/m', $neon);
        $this->assertMatchesRegularExpression('/^\s*-\s*tests\s*$/m', $neon);
    }

    public function test_ci_workflow_runs_pint_larastan_and_tests_on_every_push(): void
    {
        $workflow = $this->read('.github/workflows/ci.yml');

        $this->assertMatchesRegularExpression('/^on:\s*\n\s+push:\s*$/m', $workflow, 'CI should run on every push (no branch filter)');
        $this->assertStringContainsString('composer lint', $workflow);
        $this->assertStringContainsString('composer analyse', $workflow);
        $this->assertStringContainsString('composer test', $workflow);
        $this->assertStringContainsString('npm run build', $workflow, 'Feature tests need the Vite manifest');
    }
}
