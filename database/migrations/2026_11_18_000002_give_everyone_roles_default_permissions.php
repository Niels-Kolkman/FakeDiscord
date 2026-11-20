<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data only. Before permission flags existed, @everyone roles
 * were created with no bits; give them the defaults new servers get, and
 * create @everyone for any server without one.
 */
return new class extends Migration
{
    /**
     * App\Enums\Permission::everyoneDefaults() at the time of writing
     * (Create Invite, Add Reactions, View Channels, Send Messages, Attach
     * Files), copied so later changes to the enum do not change this
     * migration.
     */
    private const EVERYONE_DEFAULTS = (1 << 0) | (1 << 6) | (1 << 10) | (1 << 11) | (1 << 15);

    public function up(): void
    {
        DB::table('roles')
            ->where('is_default', true)
            ->where('permissions', 0)
            ->update(['permissions' => self::EVERYONE_DEFAULTS]);

        $missing = DB::table('servers')
            ->whereNotExists(fn ($query) => $query
                ->from('roles')
                ->whereColumn('roles.server_id', 'servers.id')
                ->where('roles.is_default', true))
            ->pluck('id');

        foreach ($missing as $serverId) {
            DB::table('roles')->insert([
                'server_id' => $serverId,
                'name' => '@everyone',
                'permissions' => self::EVERYONE_DEFAULTS,
                'position' => 0,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Nothing to undo: the flags are kept, and the backfilled roles are
     * what every server is meant to have.
     */
    public function down(): void {}
};
