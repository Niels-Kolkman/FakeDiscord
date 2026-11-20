<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

/**
 * Checks that the scaffold files (.env.example, Vite/Tailwind
 * config, npm scripts, .gitignore) stay as the setup docs describe.
 */
class ProjectScaffoldTest extends TestCase
{
    private function path(string $relative): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function read(string $relative): string
    {
        $file = $this->path($relative);
        $this->assertFileExists($file);

        return file_get_contents($file);
    }

    public function test_env_example_configures_sqlite_without_a_database_server(): void
    {
        $env = Dotenv::parse($this->read('.env.example'));

        $this->assertSame('sqlite', $env['DB_CONNECTION'] ?? null);

        // DB_DATABASE must stay unset so Laravel falls back to database/database.sqlite.
        foreach (['DB_DATABASE', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $this->assertArrayNotHasKey($key, $env, "{$key} should not be active in .env.example");
        }
    }

    public function test_env_example_sets_app_name_and_leaves_app_key_blank(): void
    {
        $env = Dotenv::parse($this->read('.env.example'));

        $this->assertSame('FakeDiscord', $env['APP_NAME'] ?? null);
        $this->assertSame('', $env['APP_KEY'] ?? null, 'APP_KEY must be empty; key:generate fills it in.');
        // Dotenv fills in VITE_APP_NAME="${APP_NAME}" while parsing.
        $this->assertSame('FakeDiscord', $env['VITE_APP_NAME'] ?? null);
    }

    public function test_vite_config_registers_laravel_entry_points_and_tailwind(): void
    {
        $config = $this->read('vite.config.js');

        $this->assertStringContainsString("from 'laravel-vite-plugin'", $config);
        $this->assertStringContainsString("from '@tailwindcss/vite'", $config);
        $this->assertStringContainsString("'resources/css/app.css'", $config);
        $this->assertStringContainsString("'resources/js/app.js'", $config);
        $this->assertMatchesRegularExpression('/tailwindcss\(\)/', $config);
    }

    public function test_app_css_imports_tailwind(): void
    {
        $this->assertMatchesRegularExpression("/@import\s+['\"]tailwindcss['\"]/", $this->read('resources/css/app.css'));
        $this->assertFileExists($this->path('resources/js/app.js'));
    }

    public function test_package_json_exposes_dev_and_build_scripts_and_frontend_deps(): void
    {
        $package = json_decode($this->read('package.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('vite', $package['scripts']['dev'] ?? null);
        $this->assertSame('vite build', $package['scripts']['build'] ?? null);

        foreach (['vite', 'laravel-vite-plugin', 'tailwindcss', '@tailwindcss/vite'] as $dependency) {
            $this->assertArrayHasKey($dependency, $package['devDependencies'] ?? []);
        }
    }

    public function test_composer_requires_laravel_12_and_setup_creates_the_sqlite_file(): void
    {
        $composer = json_decode($this->read('composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertMatchesRegularExpression('/^\^12\./', $composer['require']['laravel/framework'] ?? '');

        $setup = implode("\n", $composer['scripts']['setup'] ?? []);
        $this->assertStringContainsString("touch('database/database.sqlite')", $setup);
        $this->assertStringContainsString('artisan migrate', $setup);
    }

    public function test_sqlite_database_file_is_git_ignored(): void
    {
        $this->assertStringContainsString('*.sqlite*', $this->read('database/.gitignore'));
    }

    public function test_readme_documents_the_setup_steps(): void
    {
        $readme = $this->read('README.md');

        foreach ([
            'composer install',
            '.env.example',
            'php artisan key:generate',
            'database/database.sqlite',
            'php artisan migrate',
            'npm install',
            'php artisan serve',
            'npm run dev',
        ] as $step) {
            $this->assertStringContainsString($step, $readme, "README should mention `{$step}`");
        }
    }
}
