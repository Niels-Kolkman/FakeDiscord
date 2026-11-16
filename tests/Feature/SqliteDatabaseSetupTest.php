<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ISSUE-1: the app runs on SQLite everywhere, and `php artisan migrate`
 * creates and fills the SQLite file.
 */
class SqliteDatabaseSetupTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fakediscord-'.uniqid('', true).'.sqlite';

        config()->set('database.connections.sqlite.database', $this->databasePath);
        DB::purge('sqlite');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        if (is_file($this->databasePath)) {
            @unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_sqlite_is_the_default_connection(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
    }

    public function test_sqlite_database_defaults_to_database_sqlite_when_db_database_is_unset(): void
    {
        $config = $this->withoutEnv('DB_DATABASE', fn () => require base_path('config/database.php'));

        $this->assertSame(
            database_path('database.sqlite'),
            $config['connections']['sqlite']['database'],
        );
    }

    public function test_migrate_creates_the_missing_sqlite_file_after_confirmation(): void
    {
        $this->assertFileDoesNotExist($this->databasePath);

        $this->artisan('migrate')
            ->expectsConfirmation('Would you like to create it?', 'yes')
            ->assertSuccessful();

        $this->assertFileExists($this->databasePath);
        $this->assertCoreTablesExist();
    }

    public function test_migrate_with_force_creates_the_sqlite_file_without_prompting(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertFileExists($this->databasePath);
        $this->assertCoreTablesExist();
    }

    public function test_migrate_runs_against_an_existing_empty_sqlite_file(): void
    {
        // This is the path `composer setup` takes: it touches the file first.
        touch($this->databasePath);

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertCoreTablesExist();
    }

    public function test_declining_the_prompt_does_not_create_the_sqlite_file(): void
    {
        try {
            $this->artisan('migrate')
                ->expectsConfirmation('Would you like to create it?', 'no')
                ->run();
        } catch (\RuntimeException) {
            // Laravel aborts the migration with an exception when you decline.
        }

        $this->assertFileDoesNotExist($this->databasePath);
    }

    private function assertCoreTablesExist(): void
    {
        foreach (['users', 'sessions', 'cache', 'jobs', 'migrations'] as $table) {
            $this->assertTrue(
                Schema::connection('sqlite')->hasTable($table),
                "Expected table [{$table}] to exist after migrating.",
            );
        }
    }

    /**
     * Run the callback with an environment variable temporarily unset.
     */
    private function withoutEnv(string $key, callable $callback): mixed
    {
        $saved = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];

        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        try {
            return $callback();
        } finally {
            [$env, $server, $put] = $saved;

            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($put !== false) {
                putenv("{$key}={$put}");
            }
        }
    }
}
