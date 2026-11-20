<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Column defaults, NOT NULL / unique constraints and the delete paths
 * not covered by DatabaseSchemaTest, exercised against SQLite with real rows.
 */
class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_column_defaults_are_applied_on_insert(): void
    {
        $user = $this->user('alice');
        $server = $this->server($user);
        $channel = DB::table('channels')->insertGetId(['server_id' => $server, 'name' => 'general']);
        $role = DB::table('roles')->insertGetId(['server_id' => $server, 'name' => 'Member']);
        $member = DB::table('server_members')->insertGetId(['server_id' => $server, 'user_id' => $user]);
        $invite = DB::table('invites')->insertGetId(['server_id' => $server, 'code' => 'def']);
        $dm = DB::table('conversations')->insertGetId(['name' => null]);
        $friendship = DB::table('friendships')->insertGetId(['user_id' => $user, 'friend_id' => $this->user('bob')]);
        $message = DB::table('messages')->insertGetId(['channel_id' => $channel, 'user_id' => $user, 'content' => 'x']);
        $attachment = DB::table('message_attachments')->insertGetId([
            'message_id' => $message, 'path' => 'p', 'original_name' => 'o', 'mime_type' => 'text/plain', 'size' => 1,
        ]);

        $this->assertSame('offline', DB::table('users')->where('id', $user)->value('status'));
        $this->assertSame('text', DB::table('channels')->where('id', $channel)->value('type'));
        $this->assertSame(0, (int) DB::table('channels')->where('id', $channel)->value('position'));

        $roleRow = DB::table('roles')->where('id', $role)->first();
        $this->assertSame(0, (int) $roleRow->permissions);
        $this->assertSame(0, (int) $roleRow->position);
        $this->assertSame(0, (int) $roleRow->is_default);

        $this->assertNotNull(DB::table('server_members')->where('id', $member)->value('joined_at'));

        $inviteRow = DB::table('invites')->where('id', $invite)->first();
        $this->assertSame(0, (int) $inviteRow->uses);
        $this->assertNull($inviteRow->max_uses);
        $this->assertNull($inviteRow->expires_at);

        $this->assertSame('direct', DB::table('conversations')->where('id', $dm)->value('type'));
        $this->assertSame('pending', DB::table('friendships')->where('id', $friendship)->value('status'));
        $this->assertSame('public', DB::table('message_attachments')->where('id', $attachment)->value('disk'));
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function requiredColumns(): array
    {
        return [
            'users.username' => ['users', ['name' => 'N', 'email' => 'n@example.test', 'password' => 'x']],
            'users.email' => ['users', ['name' => 'N', 'username' => 'n', 'password' => 'x']],
            'servers.name' => ['servers', ['owner_id' => '{user}']],
            'channels.name' => ['channels', ['server_id' => '{server}']],
            'roles.name' => ['roles', ['server_id' => '{server}']],
            'messages.content' => ['messages', ['channel_id' => '{channel}']],
            'invites.code' => ['invites', ['server_id' => '{server}']],
            'reactions.emoji' => ['reactions', ['message_id' => '{message}', 'user_id' => '{user}']],
            'message_attachments.size' => ['message_attachments', [
                'message_id' => '{message}', 'path' => 'p', 'original_name' => 'o', 'mime_type' => 'text/plain',
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('requiredColumns')]
    public function test_required_columns_reject_missing_values(string $table, array $row): void
    {
        $row = $this->resolve($row, $this->fixtures());

        $this->expectException(QueryException::class);

        DB::table($table)->insert($row);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, array<string, mixed>}>
     */
    public static function duplicateRows(): array
    {
        return [
            'users.username' => ['users',
                ['name' => 'A', 'username' => 'taken', 'email' => 'a1@example.test', 'password' => 'x'],
                ['email' => 'a2@example.test']],
            'users.email' => ['users',
                ['name' => 'A', 'username' => 'u1', 'email' => 'same@example.test', 'password' => 'x'],
                ['username' => 'u2']],
            'invites.code' => ['invites', ['server_id' => '{server}', 'code' => 'dupcode'], []],
            'bans' => ['bans', ['server_id' => '{server}', 'user_id' => '{other}'], []],
            'role_user' => ['role_user', ['role_id' => '{role}', 'user_id' => '{user}'], []],
            'conversation_participants' => ['conversation_participants', ['conversation_id' => '{conversation}', 'user_id' => '{user}'], []],
            'reactions' => ['reactions', ['message_id' => '{message}', 'user_id' => '{user}', 'emoji' => '🔥'], []],
            'friendships' => ['friendships', ['user_id' => '{user}', 'friend_id' => '{other}'], []],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $secondOverrides  columns that differ on the second insert
     */
    #[DataProvider('duplicateRows')]
    public function test_unique_constraints_reject_duplicates(string $table, array $row, array $secondOverrides): void
    {
        $row = $this->resolve($row, $this->fixtures());
        DB::table($table)->insert($row);

        $this->expectException(QueryException::class);

        DB::table($table)->insert(array_merge($row, $secondOverrides));
    }

    public function test_a_user_may_react_with_several_emoji_and_others_may_reuse_one(): void
    {
        $refs = $this->fixtures();

        DB::table('reactions')->insert([
            ['message_id' => $refs['{message}'], 'user_id' => $refs['{user}'], 'emoji' => '🔥'],
            ['message_id' => $refs['{message}'], 'user_id' => $refs['{user}'], 'emoji' => '👍'],
            ['message_id' => $refs['{message}'], 'user_id' => $refs['{other}'], 'emoji' => '🔥'],
        ]);

        $this->assertSame(3, DB::table('reactions')->count());
    }

    public function test_a_user_may_be_banned_from_several_servers(): void
    {
        $refs = $this->fixtures();
        $second = $this->server($refs['{user}']);

        DB::table('bans')->insert([
            ['server_id' => $refs['{server}'], 'user_id' => $refs['{other}']],
            ['server_id' => $second, 'user_id' => $refs['{other}']],
        ]);

        $this->assertSame(2, DB::table('bans')->count());
    }

    public function test_deleting_a_channel_removes_its_messages_and_detaches_invites(): void
    {
        $refs = $this->fixtures();
        $invite = DB::table('invites')->insertGetId([
            'server_id' => $refs['{server}'], 'channel_id' => $refs['{channel}'], 'code' => 'chan',
        ]);
        DB::table('reactions')->insert(['message_id' => $refs['{message}'], 'user_id' => $refs['{user}'], 'emoji' => '🔥']);

        DB::table('channels')->where('id', $refs['{channel}'])->delete();

        $this->assertSame(0, DB::table('messages')->count());
        $this->assertSame(0, DB::table('reactions')->count());
        $this->assertNull(DB::table('invites')->where('id', $invite)->value('channel_id'));
        $this->assertSame(1, DB::table('servers')->count());
    }

    public function test_deleting_a_role_only_removes_its_assignments(): void
    {
        $refs = $this->fixtures();
        DB::table('role_user')->insert(['role_id' => $refs['{role}'], 'user_id' => $refs['{user}']]);

        DB::table('roles')->where('id', $refs['{role}'])->delete();

        $this->assertSame(0, DB::table('role_user')->count());
        $this->assertSame(1, DB::table('server_members')->where('user_id', $refs['{user}'])->count());
    }

    public function test_deleting_a_conversation_removes_participants_and_messages(): void
    {
        $refs = $this->fixtures();
        DB::table('conversation_participants')->insert(['conversation_id' => $refs['{conversation}'], 'user_id' => $refs['{user}']]);
        DB::table('messages')->insert(['conversation_id' => $refs['{conversation}'], 'user_id' => $refs['{user}'], 'content' => 'dm']);

        DB::table('conversations')->where('id', $refs['{conversation}'])->delete();

        $this->assertSame(0, DB::table('conversation_participants')->count());
        $this->assertSame(0, DB::table('messages')->whereNotNull('conversation_id')->count());
        $this->assertSame(1, DB::table('messages')->whereNotNull('channel_id')->count());
    }

    public function test_deleting_a_user_nulls_their_invites_bans_issued_and_group_dm_ownership(): void
    {
        $refs = $this->fixtures();
        $moderator = $this->user('mod');
        DB::table('server_members')->insert(['server_id' => $refs['{server}'], 'user_id' => $moderator]);
        $invite = DB::table('invites')->insertGetId(['server_id' => $refs['{server}'], 'inviter_id' => $moderator, 'code' => 'modinv']);
        $ban = DB::table('bans')->insertGetId(['server_id' => $refs['{server}'], 'user_id' => $refs['{other}'], 'banned_by_id' => $moderator]);
        $group = DB::table('conversations')->insertGetId(['type' => 'group', 'name' => 'Squad', 'owner_id' => $moderator]);
        DB::table('role_user')->insert(['role_id' => $refs['{role}'], 'user_id' => $moderator]);
        DB::table('reactions')->insert(['message_id' => $refs['{message}'], 'user_id' => $moderator, 'emoji' => '🔥']);

        DB::table('users')->where('id', $moderator)->delete();

        $this->assertNull(DB::table('invites')->where('id', $invite)->value('inviter_id'));
        $this->assertNull(DB::table('bans')->where('id', $ban)->value('banned_by_id'));
        $this->assertNull(DB::table('conversations')->where('id', $group)->value('owner_id'));
        $this->assertSame(0, DB::table('server_members')->where('user_id', $moderator)->count());
        $this->assertSame(0, DB::table('role_user')->where('user_id', $moderator)->count());
        $this->assertSame(0, DB::table('reactions')->count());
    }

    public function test_deleting_a_user_deletes_the_servers_they_own(): void
    {
        $refs = $this->fixtures();

        DB::table('users')->where('id', $refs['{user}'])->delete();

        $this->assertSame(0, DB::table('servers')->count());
        $this->assertSame(0, DB::table('channels')->count());
        $this->assertSame(0, DB::table('messages')->count());
    }

    public function test_attachment_metadata_round_trips_as_json_text(): void
    {
        $refs = $this->fixtures();
        $metadata = ['width' => 1920, 'height' => 1080, 'tags' => ['a', 'ü'], 'spoiler' => false];
        $size = 5 * 1024 * 1024 * 1024; // > 4 GiB

        $id = DB::table('message_attachments')->insertGetId([
            'message_id' => $refs['{message}'], 'path' => 'p.png', 'original_name' => 'p.png',
            'mime_type' => 'image/png', 'size' => $size, 'metadata' => json_encode($metadata),
        ]);
        $row = DB::table('message_attachments')->where('id', $id)->first();

        $this->assertIsString($row->metadata);
        $this->assertSame($metadata, json_decode($row->metadata, true));
        $this->assertSame($size, (int) $row->size);
    }

    public function test_role_permissions_keep_every_documented_safe_bit(): void
    {
        $refs = $this->fixtures();
        $all = PHP_INT_MAX; // bits 0–62

        DB::table('roles')->where('id', $refs['{role}'])->update(['permissions' => $all]);

        $this->assertSame($all, (int) DB::table('roles')->where('id', $refs['{role}'])->value('permissions'));
    }

    /**
     * A small connected graph: {user} owns and belongs to {server}, which has
     * {channel} with {message} and {role}; plus {other} and an empty {conversation}.
     *
     * @return array<string, int>
     */
    private function fixtures(): array
    {
        $user = $this->user('owner');
        $other = $this->user('other');
        $server = $this->server($user);
        DB::table('server_members')->insert(['server_id' => $server, 'user_id' => $user]);
        $channel = DB::table('channels')->insertGetId(['server_id' => $server, 'name' => 'general']);

        return [
            '{user}' => $user,
            '{other}' => $other,
            '{server}' => $server,
            '{channel}' => $channel,
            '{role}' => DB::table('roles')->insertGetId(['server_id' => $server, 'name' => 'Member']),
            '{message}' => DB::table('messages')->insertGetId(['channel_id' => $channel, 'user_id' => $user, 'content' => 'hi']),
            '{conversation}' => DB::table('conversations')->insertGetId(['type' => 'direct']),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $refs
     * @return array<string, mixed>
     */
    private function resolve(array $row, array $refs): array
    {
        return array_map(fn ($value) => is_string($value) && isset($refs[$value]) ? $refs[$value] : $value, $row);
    }

    private function server(int $owner): int
    {
        return DB::table('servers')->insertGetId(['owner_id' => $owner, 'name' => 'Server']);
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
}
