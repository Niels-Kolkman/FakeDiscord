<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ISSUE-3 acceptance: `php artisan migrate:fresh` succeeds on a real SQLite
 * file, and every migration's down() reverses its up().
 *
 * This runs against a temp file instead of RefreshDatabase, because
 * migrate:fresh VACUUMs the database and SQLite refuses to do that inside
 * RefreshDatabase's wrapping transaction.
 */
class MigrationRoundTripTest extends TestCase
{
    private const DOMAIN_TABLES = [
        'servers', 'server_members', 'channels', 'messages', 'message_attachments',
        'reactions', 'friendships', 'conversations', 'conversation_participants',
        'roles', 'role_user', 'invites', 'bans', 'channel_categories',
        'channel_permission_overrides',
    ];

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fakediscord-schema-'.uniqid('', true).'.sqlite';
        touch($this->databasePath);

        config()->set('database.connections.sqlite.database', $this->databasePath);
        DB::purge('sqlite');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        @unlink($this->databasePath);

        parent::tearDown();
    }

    public function test_migrate_fresh_succeeds_twice_in_a_row(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->artisan('migrate:fresh', ['--force' => true, '--seed' => true])->assertSuccessful();

        $this->assertDomainTables(exist: true);
        $this->assertSame(1, DB::table('users')->where('username', 'testuser')->count());
    }

    public function test_rolling_back_everything_drops_the_domain_tables(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->artisan('migrate:reset', ['--force' => true])->assertSuccessful();

        $this->assertDomainTables(exist: false);
        $this->assertFalse(Schema::hasTable('users'));

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertDomainTables(exist: true);
    }

    public function test_each_migration_rolls_back_on_its_own_in_reverse_order(): void
    {
        $this->artisan('migrate', ['--force' => true, '--step' => true])->assertSuccessful();
        $total = DB::table('migrations')->count();
        $this->assertSame(count(glob(database_path('migrations/*.php'))), $total);

        for ($remaining = $total; $remaining > 0; $remaining--) {
            $latest = DB::table('migrations')->orderByDesc('id')->value('migration');

            $this->artisan('migrate:rollback', ['--force' => true, '--step' => 1])->assertSuccessful();

            $this->assertSame($remaining - 1, DB::table('migrations')->count(), "Rolling back [{$latest}] did not remove it.");
            $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        }

        $this->assertDomainTables(exist: false);
    }

    public function test_migrate_fresh_wipes_existing_rows(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $user = DB::table('users')->insertGetId(['name' => 'A', 'username' => 'a', 'email' => 'a@example.test', 'password' => 'x']);
        DB::table('servers')->insert(['owner_id' => $user, 'name' => 'S']);

        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('servers')->count());
    }

    private function assertDomainTables(bool $exist): void
    {
        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertSame($exist, Schema::hasTable($table), "Table [{$table}] should ".($exist ? '' : 'not ').'exist.');
        }
    }
}
