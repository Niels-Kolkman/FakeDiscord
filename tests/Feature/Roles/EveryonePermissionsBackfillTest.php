<?php

namespace Tests\Feature\Roles;

use App\Enums\Permission;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The data migration gives existing @everyone roles the default
 * permissions and creates @everyone for servers without one.
 *
 * Runs against a temp SQLite file (like DirectKeyBackfillTest), because it
 * rolls back and re-runs a migration.
 */
class EveryonePermissionsBackfillTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_11_18_000002_give_everyone_roles_default_permissions.php';

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fakediscord-roles-'.uniqid('', true).'.sqlite';
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

    public function test_existing_servers_get_everyone_with_the_default_permissions(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true])->assertSuccessful();

        $now = '2026-01-01 00:00:00';
        DB::table('users')->insert([
            'id' => 1, 'name' => 'Owner', 'username' => 'owner', 'email' => 'owner@example.com',
            'password' => 'x', 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([1, 2, 3] as $id) {
            DB::table('servers')->insert(['id' => $id, 'owner_id' => 1, 'name' => "Server {$id}", 'created_at' => $now, 'updated_at' => $now]);
        }

        $role = fn (int $server, string $name, int $permissions, bool $default) => DB::table('roles')->insertGetId([
            'server_id' => $server, 'name' => $name, 'permissions' => $permissions, 'position' => $default ? 0 : 1,
            'is_default' => $default, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $old = $role(1, '@everyone', 0, true);        // from before flags existed
        $custom = $role(2, '@everyone', 1 << 11, true); // already set: kept
        $plain = $role(1, 'Members', 0, false);       // not @everyone: kept at 0
        // Server 3 has no @everyone at all.

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertSame(Permission::everyoneDefaults(), (int) DB::table('roles')->where('id', $old)->value('permissions'));
        $this->assertSame(1 << 11, (int) DB::table('roles')->where('id', $custom)->value('permissions'));
        $this->assertSame(0, (int) DB::table('roles')->where('id', $plain)->value('permissions'));

        $created = DB::table('roles')->where('server_id', 3)->sole();
        $this->assertSame('@everyone', $created->name);
        $this->assertTrue((bool) $created->is_default);
        $this->assertSame(0, (int) $created->position);
        $this->assertSame(Permission::everyoneDefaults(), (int) $created->permissions);

        foreach ([1, 2, 3] as $server) {
            $this->assertSame(1, DB::table('roles')->where('server_id', $server)->where('is_default', true)->count());
        }
    }
}
