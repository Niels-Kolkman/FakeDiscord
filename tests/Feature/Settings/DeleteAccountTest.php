<?php

namespace Tests\Feature\Settings;

use App\Models\Ban;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Invite;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Reaction;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DocumentsKnownBugs;
use Tests\TestCase;

/**
 * ISSUE-9: deleting an account anonymises the user's messages instead of
 * removing them.
 */
class DeleteAccountTest extends TestCase
{
    use DocumentsKnownBugs;
    use RefreshDatabase;

    public function test_account_can_be_deleted_with_the_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/settings/account', ['password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'account-deleted');

        $this->assertGuest();
        $this->assertModelMissing($user);
    }

    public function test_wrong_password_keeps_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/account')
            ->delete('/settings/account', ['password' => 'wrong'])
            ->assertRedirect('/settings/account')
            ->assertSessionHasErrorsIn('deleteAccount', 'password');

        $this->assertAuthenticatedAs($user);
        $this->assertModelExists($user);
    }

    public function test_missing_password_keeps_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/settings/account')
            ->assertSessionHasErrorsIn('deleteAccount', 'password');

        $this->assertModelExists($user);
    }

    public function test_channel_and_dm_messages_are_anonymised_not_deleted(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $channel = Channel::factory()->create();
        $conversation = Conversation::factory()->create();
        $conversation->users()->attach([$user->id, $other->id]);

        $channelMessage = Message::factory()->inChannel($channel)->for($user, 'author')->create(['content' => 'hello channel']);
        $dmMessage = Message::factory()->inConversation($conversation)->for($user, 'author')->create(['content' => 'hello dm']);
        $reply = Message::factory()->replyTo($channelMessage)->for($other, 'author')->create();
        $othersMessage = Message::factory()->inChannel($channel)->for($other, 'author')->create();

        $this->actingAs($user)->delete('/settings/account', ['password' => 'password']);

        $this->assertModelMissing($user);
        $channelMessage->refresh();
        $dmMessage->refresh();
        $this->assertNull($channelMessage->user_id);
        $this->assertSame('hello channel', $channelMessage->content);
        $this->assertSame($channel->id, $channelMessage->channel_id);
        $this->assertNull($dmMessage->user_id);
        $this->assertSame('hello dm', $dmMessage->content);
        $this->assertSame('Deleted User', $channelMessage->authorName());

        // Replies keep pointing at the anonymised message; others are untouched.
        $reply->refresh();
        $othersMessage->refresh();
        $this->assertSame($channelMessage->id, $reply->reply_to_id);
        $this->assertSame($other->id, $othersMessage->user_id);
        $this->assertSame($other->name, $othersMessage->authorName());
        $this->assertModelExists($conversation);
    }

    public function test_personal_data_linked_to_the_account_is_removed(): void
    {
        $user = User::factory()->create();
        $message = Message::factory()->create();
        Reaction::factory()->for($message)->for($user)->create();
        $channel = Channel::factory()->create();
        $channel->server->members()->attach($user->id, ['joined_at' => now()]);

        $this->actingAs($user)->delete('/settings/account', ['password' => 'password']);

        $this->assertDatabaseMissing('reactions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('server_members', ['user_id' => $user->id]);
        $this->assertModelExists($channel->server);
        $this->assertModelExists($message);
    }

    public function test_server_owners_must_transfer_or_delete_servers_first(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->for($user, 'owner')->create();
        $channel = Channel::factory()->for($server)->create();
        $memberMessage = Message::factory()->inChannel($channel)->create();

        $this->actingAs($user)
            ->delete('/settings/account', ['password' => 'password'])
            ->assertSessionHasErrorsIn('deleteAccount', 'password');

        $this->assertModelExists($user);
        $this->assertModelExists($server);
        $this->assertModelExists($memberMessage);
        $this->assertAuthenticatedAs($user);
    }

    public function test_all_database_sessions_of_the_user_are_removed(): void
    {
        $user = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'someone-else', 'user_id' => null, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->actingAs($user)->delete('/settings/account', ['password' => 'password']);

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'someone-else']);
    }

    public function test_deleted_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['email' => 'gone@example.test']);

        $this->actingAs($user)->delete('/settings/account', ['password' => 'password']);

        $this->post('/login', ['email' => 'gone@example.test', 'password' => 'password']);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'gone@example.test']);
    }

    public function test_confirmation_is_shown_on_the_login_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->followingRedirects()
            ->delete('/settings/account', ['password' => 'password'])
            ->assertSee('Your account has been deleted.');
    }

    public function test_failed_deletion_leaves_messages_attributed(): void
    {
        $user = User::factory()->create();
        $message = Message::factory()->for($user, 'author')->create();

        $this->actingAs($user)->delete('/settings/account', ['password' => 'wrong']);

        $this->assertSame($user->id, $message->fresh()->user_id);
    }

    public function test_refused_deletion_for_server_owner_leaves_messages_attributed(): void
    {
        $user = User::factory()->create();
        Server::factory()->for($user, 'owner')->create();
        $message = Message::factory()->for($user, 'author')->create();

        $this->actingAs($user)->delete('/settings/account', ['password' => 'password']);

        $this->assertSame($user->id, $message->fresh()->user_id);
    }

    public function test_server_owner_sees_the_transfer_ownership_message(): void
    {
        $user = User::factory()->create();
        Server::factory()->for($user, 'owner')->create();

        $this->actingAs($user)
            ->from('/settings/account')
            ->followingRedirects()
            ->delete('/settings/account', ['password' => 'password'])
            ->assertSee('You own servers. Transfer ownership or delete them before deleting your account.')
            ->assertSee('id="delete_password-error"', false);
    }

    public function test_wrong_password_shows_the_incorrect_password_message(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/account')
            ->followingRedirects()
            ->delete('/settings/account', ['password' => 'wrong'])
            ->assertSee('The provided password is incorrect.');
    }

    public function test_non_string_password_is_a_validation_error_not_a_crash(): void
    {
        $user = User::factory()->create();

        $this->knownBug('array password reaches current_password and password_verify() throws a TypeError (500); the rule list needs `bail`.', function () use ($user) {
            $this->actingAs($user)
                ->from('/settings/account')
                ->delete('/settings/account', ['password' => ['password']])
                ->assertRedirect('/settings/account')
                ->assertSessionHasErrorsIn('deleteAccount', 'password');
        });

        $this->assertModelExists($user);
    }

    public function test_pending_password_reset_tokens_are_removed(): void
    {
        $user = User::factory()->create();
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => bcrypt('token'),
            'created_at' => now(),
        ]);

        $this->actingAs($user)->delete('/settings/account', ['password' => 'password']);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_friendships_bans_roles_and_dm_participation_go_with_the_account(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();
        $sent = Friendship::factory()->accepted()->create(['user_id' => $user->id, 'friend_id' => $friend->id]);
        $received = Friendship::factory()->create(['user_id' => $friend->id, 'friend_id' => $user->id]);
        $ban = Ban::factory()->create(['user_id' => $user->id]);
        $role = Role::factory()->create();
        $user->roles()->attach($role);
        $conversation = Conversation::factory()->create();
        $conversation->users()->attach([$user->id, $friend->id]);

        $this->actingAs($user)->delete('/settings/account', ['password' => 'password']);

        $this->assertModelMissing($sent);
        $this->assertModelMissing($received);
        $this->assertModelMissing($ban);
        $this->assertDatabaseMissing('role_user', ['user_id' => $user->id]);
        $this->assertModelExists($role);
        $this->assertDatabaseMissing('conversation_participants', ['user_id' => $user->id]);
        $this->assertDatabaseHas('conversation_participants', ['conversation_id' => $conversation->id, 'user_id' => $friend->id]);
    }

    public function test_records_merely_referencing_the_user_are_kept_with_a_null_reference(): void
    {
        $user = User::factory()->create();
        $invite = Invite::factory()->create(['inviter_id' => $user->id]);
        $ban = Ban::factory()->create(['banned_by_id' => $user->id]);
        $group = Conversation::factory()->group()->create(['owner_id' => $user->id]);

        $this->actingAs($user)->delete('/settings/account', ['password' => 'password']);

        $this->assertNull($invite->fresh()->inviter_id);
        $this->assertNull($ban->fresh()->banned_by_id);
        $this->assertNull($group->fresh()->owner_id);
    }

    public function test_attachments_and_others_reactions_on_anonymised_messages_are_kept(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $message = Message::factory()->for($user, 'author')->create();
        $attachment = MessageAttachment::factory()->for($message)->create();
        $reaction = Reaction::factory()->for($message)->for($other)->create();

        $this->actingAs($user)->delete('/settings/account', ['password' => 'password']);

        $this->assertModelExists($attachment);
        $this->assertModelExists($reaction);
        $this->assertNull($message->fresh()->user_id);
    }

    public function test_deletion_after_remember_me_login_does_not_recreate_the_user(): void
    {
        // Regression guard for the controller using logoutCurrentDevice():
        // logout() would cycle the remember token and save() the deleted row.
        $user = User::factory()->create(['email' => 'remember@example.test']);

        $this->post('/login', ['email' => 'remember@example.test', 'password' => 'password', 'remember' => 'on']);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);

        $this->delete('/settings/account', ['password' => 'password'])->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('users', ['email' => 'remember@example.test']);
    }

    public function test_deletion_attempts_are_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->delete('/settings/account', ['password' => 'wrong'])->assertRedirect();
        }

        $this->actingAs($user)->delete('/settings/account', ['password' => 'password'])->assertTooManyRequests();
        $this->assertModelExists($user);
    }
}
