<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ISSUE-3: the Discord schema migrates on SQLite, with its foreign keys,
 * cascades, unique constraints and indexes in place.
 */
class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN_TABLES = [
        'users', 'servers', 'server_members', 'channels', 'messages',
        'message_attachments', 'reactions', 'friendships', 'conversations',
        'conversation_participants', 'roles', 'role_user', 'invites', 'bans',
        'channel_categories',
    ];

    public function test_all_domain_tables_exist(): void
    {
        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");
        }
    }

    public function test_sqlite_foreign_key_enforcement_is_on(): void
    {
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    public function test_messages_are_indexed_by_channel_and_created_at(): void
    {
        $this->assertHasIndex('messages', ['channel_id', 'created_at']);
        $this->assertHasIndex('messages', ['conversation_id', 'created_at']);
    }

    public function test_lookup_and_uniqueness_indexes_exist(): void
    {
        $this->assertHasIndex('server_members', ['server_id', 'user_id'], unique: true);
        $this->assertHasIndex('conversation_participants', ['conversation_id', 'user_id'], unique: true);
        $this->assertHasIndex('reactions', ['message_id', 'emoji', 'user_id'], unique: true);
        $this->assertHasIndex('friendships', ['user_id', 'friend_id'], unique: true);
        $this->assertHasIndex('bans', ['server_id', 'user_id'], unique: true);
        $this->assertHasIndex('invites', ['code'], unique: true);
        $this->assertHasIndex('users', ['username'], unique: true);
        $this->assertHasIndex('channels', ['server_id', 'position']);
        $this->assertHasIndex('channels', ['category_id']);
        $this->assertHasIndex('channel_categories', ['server_id', 'position']);
    }

    public function test_every_foreign_key_column_leads_an_index(): void
    {
        foreach (self::DOMAIN_TABLES as $table) {
            $leading = array_map(fn ($index) => $index['columns'][0], Schema::getIndexes($table));

            foreach (Schema::getForeignKeys($table) as $fk) {
                $this->assertContains(
                    $fk['columns'][0],
                    $leading,
                    "FK column [{$table}.{$fk['columns'][0]}] has no index starting with it.",
                );
            }
        }
    }

    public function test_foreign_keys_reference_the_expected_tables(): void
    {
        $expected = [
            'servers' => ['owner_id' => ['users', 'cascade']],
            'channels' => ['server_id' => ['servers', 'cascade'], 'category_id' => ['channel_categories', 'set null']],
            'channel_categories' => ['server_id' => ['servers', 'cascade']],
            'roles' => ['server_id' => ['servers', 'cascade']],
            'server_members' => ['server_id' => ['servers', 'cascade'], 'user_id' => ['users', 'cascade']],
            'role_user' => ['role_id' => ['roles', 'cascade'], 'user_id' => ['users', 'cascade']],
            'conversations' => ['owner_id' => ['users', 'set null']],
            'conversation_participants' => ['conversation_id' => ['conversations', 'cascade'], 'user_id' => ['users', 'cascade']],
            'messages' => [
                'channel_id' => ['channels', 'cascade'],
                'conversation_id' => ['conversations', 'cascade'],
                'user_id' => ['users', 'set null'],
                'reply_to_id' => ['messages', 'set null'],
                'pinned_by_id' => ['users', 'set null'],
            ],
            'message_attachments' => ['message_id' => ['messages', 'cascade']],
            'reactions' => ['message_id' => ['messages', 'cascade'], 'user_id' => ['users', 'cascade']],
            'friendships' => ['user_id' => ['users', 'cascade'], 'friend_id' => ['users', 'cascade']],
            'invites' => ['server_id' => ['servers', 'cascade'], 'channel_id' => ['channels', 'set null'], 'inviter_id' => ['users', 'set null']],
            'bans' => ['server_id' => ['servers', 'cascade'], 'user_id' => ['users', 'cascade'], 'banned_by_id' => ['users', 'set null']],
        ];

        foreach ($expected as $table => $columns) {
            $actual = [];
            foreach (Schema::getForeignKeys($table) as $fk) {
                $actual[$fk['columns'][0]] = [$fk['foreign_table'], strtolower($fk['on_delete'])];
            }
            ksort($actual);
            ksort($columns);

            $this->assertSame($columns, $actual, "Foreign keys of [{$table}] differ.");
        }
    }

    public function test_inserting_a_row_with_a_dangling_foreign_key_fails(): void
    {
        $this->expectException(QueryException::class);

        DB::table('channels')->insert(['server_id' => 999, 'name' => 'general']);
    }

    public function test_deleting_a_server_cascades_to_its_content(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('member');
        $server = DB::table('servers')->insertGetId(['owner_id' => $owner, 'name' => 'Test']);
        $channel = DB::table('channels')->insertGetId(['server_id' => $server, 'name' => 'general']);
        $role = DB::table('roles')->insertGetId(['server_id' => $server, 'name' => '@everyone', 'is_default' => true]);
        DB::table('server_members')->insert(['server_id' => $server, 'user_id' => $member]);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $member]);
        DB::table('invites')->insert(['server_id' => $server, 'channel_id' => $channel, 'code' => 'abc123']);
        DB::table('bans')->insert(['server_id' => $server, 'user_id' => $this->user('troll')]);
        $message = DB::table('messages')->insertGetId(['channel_id' => $channel, 'user_id' => $member, 'content' => 'hi']);
        DB::table('reactions')->insert(['message_id' => $message, 'user_id' => $owner, 'emoji' => '👍']);
        DB::table('message_attachments')->insert([
            'message_id' => $message, 'path' => 'a.png', 'original_name' => 'a.png',
            'mime_type' => 'image/png', 'size' => 10, 'metadata' => json_encode(['width' => 1]),
        ]);

        DB::table('servers')->where('id', $server)->delete();

        foreach (['channels', 'roles', 'server_members', 'role_user', 'invites', 'bans', 'messages', 'reactions', 'message_attachments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "[{$table}] should be emptied by the cascade.");
        }
        $this->assertSame(3, DB::table('users')->count());
    }

    public function test_deleting_a_user_keeps_their_messages_but_removes_personal_rows(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $dm = DB::table('conversations')->insertGetId(['type' => 'direct']);
        DB::table('conversation_participants')->insert([
            ['conversation_id' => $dm, 'user_id' => $alice],
            ['conversation_id' => $dm, 'user_id' => $bob],
        ]);
        DB::table('friendships')->insert(['user_id' => $alice, 'friend_id' => $bob, 'status' => 'accepted']);
        $original = DB::table('messages')->insertGetId(['conversation_id' => $dm, 'user_id' => $alice, 'content' => 'hey']);
        $reply = DB::table('messages')->insertGetId([
            'conversation_id' => $dm, 'user_id' => $bob, 'reply_to_id' => $original, 'content' => 'yo',
        ]);

        DB::table('users')->where('id', $alice)->delete();

        $this->assertNull(DB::table('messages')->where('id', $original)->value('user_id'));
        $this->assertSame(0, DB::table('friendships')->count());
        $this->assertSame(1, DB::table('conversation_participants')->count());

        DB::table('messages')->where('id', $original)->delete();

        $this->assertNull(DB::table('messages')->where('id', $reply)->value('reply_to_id'));
    }

    public function test_a_user_can_join_a_server_only_once(): void
    {
        $user = $this->user('dup');
        $server = DB::table('servers')->insertGetId(['owner_id' => $user, 'name' => 'S']);
        DB::table('server_members')->insert(['server_id' => $server, 'user_id' => $user]);

        $this->expectException(QueryException::class);

        DB::table('server_members')->insert(['server_id' => $server, 'user_id' => $user]);
    }

    public function test_role_permissions_store_a_64_bit_bitmask(): void
    {
        $server = DB::table('servers')->insertGetId(['owner_id' => $this->user('o'), 'name' => 'S']);
        $mask = (1 << 62) | (1 << 3) | 1;
        $role = DB::table('roles')->insertGetId(['server_id' => $server, 'name' => 'Admin', 'permissions' => $mask]);

        $this->assertSame($mask, (int) DB::table('roles')->where('id', $role)->value('permissions'));
    }

    private function user(string $username): int
    {
        return DB::table('users')->insertGetId([
            'name' => ucfirst($username),
            'username' => $username,
            'email' => "{$username}@example.test",
            'password' => 'x',
        ]);
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertHasIndex(string $table, array $columns, bool $unique = false): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === $columns && (! $unique || $index['unique'])) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail(sprintf('No %sindex on [%s](%s).', $unique ? 'unique ' : '', $table, implode(', ', $columns)));
    }
}
