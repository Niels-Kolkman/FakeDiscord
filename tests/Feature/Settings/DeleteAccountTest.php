<?php

namespace Tests\Feature\Settings;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ISSUE-9: deleting an account anonymises the user's messages instead of
 * removing them.
 */
class DeleteAccountTest extends TestCase
{
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
}
