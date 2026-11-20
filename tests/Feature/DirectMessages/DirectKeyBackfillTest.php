<?php

namespace Tests\Feature\DirectMessages;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The migration that adds conversations.direct_key and
 * last_message_at fills them in for conversations that already exist.
 *
 * Runs against a temp SQLite file (like MigrationRoundTripTest), because
 * it rolls back and re-runs a migration.
 */
class DirectKeyBackfillTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fakediscord-backfill-'.uniqid('', true).'.sqlite';
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

    public function test_existing_conversations_get_their_key_and_latest_message_time(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        // Only this migration, by path, so later migrations do not get in the way.
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_11_18_000001_add_direct_key_and_last_message_at_to_conversations_table.php',
            '--force' => true,
        ])->assertSuccessful();

        $now = '2026-01-01 00:00:00';
        foreach ([1, 2, 3, 4] as $id) {
            DB::table('users')->insert([
                'id' => $id, 'name' => "User {$id}", 'username' => "user{$id}", 'email' => "user{$id}@example.com",
                'password' => 'x', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $conversation = fn (int $id, string $type) => DB::table('conversations')->insert(['id' => $id, 'type' => $type, 'created_at' => $now, 'updated_at' => $now]);
        $join = fn (int $conversationId, array $userIds) => DB::table('conversation_participants')->insert(array_map(
            fn (int $userId): array => ['conversation_id' => $conversationId, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now],
            $userIds,
        ));

        $conversation(1, 'direct'); // 3 and 1 talk
        $join(1, [3, 1]);
        $conversation(2, 'direct'); // the same pair again
        $join(2, [1, 3]);
        $conversation(3, 'group');
        $join(3, [1, 2, 4]);
        $conversation(4, 'direct'); // broken: one participant left
        $join(4, [2]);

        DB::table('messages')->insert([
            ['conversation_id' => 1, 'user_id' => 1, 'content' => 'a', 'created_at' => '2026-02-01 10:00:00', 'updated_at' => $now, 'deleted_at' => null],
            ['conversation_id' => 1, 'user_id' => 3, 'content' => 'b', 'created_at' => '2026-02-03 10:00:00', 'updated_at' => $now, 'deleted_at' => null],
            // Deleted messages are not activity.
            ['conversation_id' => 1, 'user_id' => 3, 'content' => 'c', 'created_at' => '2026-02-09 10:00:00', 'updated_at' => $now, 'deleted_at' => $now],
            ['conversation_id' => 3, 'user_id' => 2, 'content' => 'd', 'created_at' => '2026-02-05 10:00:00', 'updated_at' => $now, 'deleted_at' => null],
        ]);

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $rows = DB::table('conversations')->orderBy('id')->get(['id', 'direct_key', 'last_message_at'])->keyBy('id');

        $this->assertSame('1:3', $rows[1]->direct_key);
        $this->assertSame('2026-02-03 10:00:00', $rows[1]->last_message_at);
        // The oldest conversation of a pair keeps the key; the unique index allows only one.
        $this->assertNull($rows[2]->direct_key);
        $this->assertNull($rows[2]->last_message_at);
        $this->assertNull($rows[3]->direct_key);
        $this->assertSame('2026-02-05 10:00:00', $rows[3]->last_message_at);
        $this->assertNull($rows[4]->direct_key);
    }
}
