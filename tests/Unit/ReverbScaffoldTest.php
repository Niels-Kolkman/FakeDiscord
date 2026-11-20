<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

/**
 * Checks the Reverb / Echo setup files and the `composer dev` script.
 */
class ReverbScaffoldTest extends TestCase
{
    private function read(string $relative): string
    {
        $file = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $this->assertFileExists($file);

        return file_get_contents($file);
    }

    public function test_env_example_uses_reverb_and_exposes_its_settings_to_vite(): void
    {
        $env = Dotenv::parse($this->read('.env.example'));

        $this->assertSame('reverb', $env['BROADCAST_CONNECTION'] ?? null);

        foreach (['REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME'] as $key) {
            $this->assertNotEmpty($env[$key] ?? null, "{$key} should be set in .env.example");
        }

        // Dotenv fills in the ${REVERB_*} references while parsing.
        $this->assertSame($env['REVERB_APP_KEY'], $env['VITE_REVERB_APP_KEY'] ?? null);
        $this->assertSame($env['REVERB_HOST'], $env['VITE_REVERB_HOST'] ?? null);
        $this->assertSame($env['REVERB_PORT'], $env['VITE_REVERB_PORT'] ?? null);
        $this->assertSame($env['REVERB_SCHEME'], $env['VITE_REVERB_SCHEME'] ?? null);
    }

    public function test_composer_requires_reverb_and_dev_script_runs_all_services(): void
    {
        $composer = json_decode($this->read('composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('laravel/reverb', $composer['require'] ?? []);

        $dev = implode("\n", $composer['scripts']['dev'] ?? []);
        foreach (['artisan serve', 'artisan queue:listen', 'npm run dev', 'artisan reverb:start'] as $command) {
            $this->assertStringContainsString($command, $dev, "composer dev should run `{$command}`");
        }
        $this->assertStringContainsString('reverb', explode('--names=', $dev)[1] ?? '');
    }

    public function test_frontend_has_echo_and_pusher_and_listens_for_the_test_event(): void
    {
        $package = json_decode($this->read('package.json'), true, flags: JSON_THROW_ON_ERROR);
        $deps = ($package['dependencies'] ?? []) + ($package['devDependencies'] ?? []);

        $this->assertArrayHasKey('laravel-echo', $deps);
        $this->assertArrayHasKey('pusher-js', $deps);

        $this->assertStringContainsString("import './echo'", $this->read('resources/js/bootstrap.js'));

        $echo = $this->read('resources/js/echo.js');
        $this->assertStringContainsString("broadcaster: 'reverb'", $echo);
        $this->assertStringContainsString('import.meta.env.VITE_REVERB_APP_KEY', $echo);
        $this->assertStringContainsString("channel('test')", $echo);
        $this->assertStringContainsString("listen('.test.message'", $echo);
    }
}
