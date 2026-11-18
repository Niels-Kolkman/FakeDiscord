<?php

namespace Tests\Feature\Permissions;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Channel;
use App\Models\ChannelCategory;
use App\Models\Invite;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * ISSUE-27: the routes follow the permission flags. A member gets in with
 * the right role and is refused without it.
 */
class PermissionEnforcementTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1001',
        ]);
        require base_path('routes/channels.php');

        $this->owner = User::factory()->create();
        $this->server = app(CreateServer::class)->create($this->owner, 'Enforcement');
        /** @var Channel $channel */
        $channel = $this->server->channels()->firstOrFail();
        $this->channel = $channel;
    }

    public function test_manage_server_lets_a_member_rename_the_server(): void
    {
        $plain = $this->joinServer($this->server);
        $manager = $this->memberWith($this->server, Permission::ManageServer);

        $this->actingAs($plain)->get(route('servers.edit', $this->server))->assertForbidden();
        $this->actingAs($plain)->patch(route('servers.update', $this->server), ['name' => 'Nope'])->assertForbidden();

        $this->actingAs($manager)->get(route('servers.edit', $this->server))->assertOk();
        $this->actingAs($manager)->patch(route('servers.update', $this->server), ['name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $this->server->fresh()?->name);

        // Deleting stays owner only.
        $this->actingAs($manager)->delete(route('servers.destroy', $this->server))->assertForbidden();
        $this->assertModelExists($this->server);
    }

    public function test_manage_channels_lets_a_member_create_edit_and_delete_channels(): void
    {
        $plain = $this->joinServer($this->server);
        $manager = $this->memberWith($this->server, Permission::ManageChannels);

        $this->actingAs($plain)->post(route('channels.store', $this->server), ['name' => 'nope'])->assertForbidden();
        $this->actingAs($plain)->post(route('categories.store', $this->server), ['name' => 'Nope'])->assertForbidden();

        $this->actingAs($manager)->post(route('channels.store', $this->server), ['name' => 'builders'])->assertSessionHasNoErrors();
        $channel = $this->server->channels()->where('name', 'builders')->sole();
        $this->actingAs($manager)->patch(route('channels.update', [$this->server, $channel]), ['name' => 'renamed'])->assertSessionHasNoErrors();
        $this->assertSame('renamed', $channel->fresh()?->name);
        $this->actingAs($manager)->post(route('categories.store', $this->server), ['name' => 'Text'])->assertSessionHasNoErrors();
        $this->assertSame(1, ChannelCategory::query()->where('server_id', $this->server->id)->count());

        $this->actingAs($plain)->delete(route('channels.destroy', [$this->server, $channel]))->assertForbidden();
        $this->actingAs($manager)->delete(route('channels.destroy', [$this->server, $channel]))->assertRedirect();
        $this->assertModelMissing($channel);
    }

    public function test_the_sidebar_shows_channel_management_only_with_manage_channels(): void
    {
        $plain = $this->joinServer($this->server);
        $manager = $this->memberWith($this->server, Permission::ManageChannels);

        $this->actingAs($plain)->get(route('servers.show', $this->server))->assertOk()->assertDontSee('Create channel');
        $this->actingAs($manager)->get(route('servers.show', $this->server))->assertOk()->assertSee('Create channel');
    }

    public function test_without_view_channels_the_channel_is_hidden_and_closed(): void
    {
        $member = $this->joinServer($this->server);
        Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id, 'content' => 'Secret plans']);
        $this->revokeFromEveryone($this->server, Permission::ViewChannels);

        // The server still opens, but without the channel.
        $this->actingAs($member)->get(route('servers.show', $this->server))
            ->assertOk()
            ->assertSee('This server has no text channels yet.')
            ->assertDontSee('Secret plans')
            ->assertDontSee(route('channels.show', [$this->server, $this->channel]));

        $this->actingAs($member)->get(route('channels.show', [$this->server, $this->channel]))->assertForbidden();
        $this->actingAs($member)->getJson(route('messages.index', [$this->server, $this->channel]))->assertForbidden();
        $this->actingAs($member)->getJson(route('pins.index', [$this->server, $this->channel]))->assertForbidden();
        $this->actingAs($member)->postJson(route('messages.store', [$this->server, $this->channel]), ['content' => 'hi'])->assertForbidden();

        // The search matches are highlighted, so look for the result count.
        $this->actingAs($member)->get(route('servers.search', [$this->server, 'q' => 'Secret']))
            ->assertOk()
            ->assertSee('No results')
            ->assertDontSee('plans');

        foreach (["private-channel.{$this->channel->id}", "presence-typing.{$this->channel->id}"] as $name) {
            $this->actingAs($member)
                ->post('/broadcasting/auth', ['channel_name' => $name, 'socket_id' => '1234.5678'])
                ->assertForbidden();
        }

        // The owner still sees everything.
        $this->actingAs($this->owner)->get(route('channels.show', [$this->server, $this->channel]))->assertOk()->assertSee('Secret plans');
        $this->actingAs($this->owner)->get(route('servers.search', [$this->server, 'q' => 'Secret']))->assertOk()->assertSee('1 result')->assertSee('plans');
    }

    public function test_view_channels_from_a_role_opens_the_channel_again(): void
    {
        $this->revokeFromEveryone($this->server, Permission::ViewChannels);
        $member = $this->memberWith($this->server, Permission::ViewChannels);

        $this->actingAs($member)->get(route('channels.show', [$this->server, $this->channel]))->assertOk();
        $this->actingAs($member)
            ->post('/broadcasting/auth', ['channel_name' => "private-channel.{$this->channel->id}", 'socket_id' => '1234.5678'])
            ->assertOk();
    }

    public function test_a_hidden_category_is_left_out_of_the_sidebar(): void
    {
        $category = ChannelCategory::factory()->create(['server_id' => $this->server->id, 'name' => 'Hidden Stuff']);
        $this->channel->update(['category_id' => $category->id]);
        $member = $this->joinServer($this->server);
        $this->revokeFromEveryone($this->server, Permission::ViewChannels);

        $this->actingAs($member)->get(route('servers.show', $this->server))->assertOk()->assertDontSee('Hidden Stuff');
        $this->actingAs($this->owner)->get(route('servers.show', $this->server))->assertOk()->assertSee('Hidden Stuff');
    }

    public function test_send_messages_is_needed_to_post(): void
    {
        $member = $this->joinServer($this->server);
        $this->revokeFromEveryone($this->server, Permission::SendMessages);

        $this->actingAs($member)->postJson(route('messages.store', [$this->server, $this->channel]), ['content' => 'hi'])->assertForbidden();
        // Reading still works, and the page has no composer.
        $this->actingAs($member)->getJson(route('messages.index', [$this->server, $this->channel]))->assertOk();
        $this->actingAs($member)->get(route('channels.show', [$this->server, $this->channel]))->assertOk()->assertDontSee('data-message-form', false);

        $poster = $this->memberWith($this->server, Permission::SendMessages);
        $this->actingAs($poster)->postJson(route('messages.store', [$this->server, $this->channel]), ['content' => 'hi'])->assertCreated();
    }

    public function test_attach_files_is_needed_to_upload(): void
    {
        Storage::fake('local');
        $member = $this->joinServer($this->server);
        $this->revokeFromEveryone($this->server, Permission::AttachFiles);
        $file = fn () => UploadedFile::fake()->create('notes.txt', 1, 'text/plain');

        $this->actingAs($member)
            ->postJson(route('messages.store', [$this->server, $this->channel]), ['content' => 'look', 'attachments' => [$file()]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments' => 'You do not have permission to attach files in this channel.']);
        $this->assertSame(0, Message::query()->count());

        // Text alone is fine, and the composer has no attach button.
        $this->actingAs($member)->postJson(route('messages.store', [$this->server, $this->channel]), ['content' => 'just text'])->assertCreated();
        $this->actingAs($member)->get(route('channels.show', [$this->server, $this->channel]))->assertOk()->assertDontSee('data-attachment-input', false);

        $uploader = $this->memberWith($this->server, Permission::AttachFiles);
        $this->actingAs($uploader)
            ->postJson(route('messages.store', [$this->server, $this->channel]), ['content' => 'look', 'attachments' => [$file()]])
            ->assertCreated();
        $this->actingAs($uploader)->get(route('channels.show', [$this->server, $this->channel]))->assertOk()->assertSee('data-attachment-input', false);
    }

    public function test_add_reactions_is_needed_for_new_emoji_only(): void
    {
        $member = $this->joinServer($this->server);
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id]);
        Reaction::factory()->create(['message_id' => $message->id, 'user_id' => $this->owner->id, 'emoji' => '👍']);
        $this->revokeFromEveryone($this->server, Permission::AddReactions);
        $url = route('reactions.store', [$this->server, $this->channel, $message]);

        $this->actingAs($member)->postJson($url, ['emoji' => '🎉'])->assertForbidden();
        $this->actingAs($member)->postJson($url, ['emoji' => '👍'])->assertOk();
        $this->actingAs($member)->deleteJson(route('reactions.destroy', [$this->server, $this->channel, $message]), ['emoji' => '👍'])->assertOk();
        $this->assertSame(1, $message->reactions()->count());
    }

    public function test_manage_messages_lets_a_member_delete_and_pin_others_messages(): void
    {
        $author = $this->joinServer($this->server);
        $plain = $this->joinServer($this->server);
        $moderator = $this->memberWith($this->server, Permission::ManageMessages);
        $first = Message::factory()->inChannel($this->channel)->create(['user_id' => $author->id]);
        $second = Message::factory()->inChannel($this->channel)->create(['user_id' => $author->id]);

        $this->actingAs($plain)->postJson(route('pins.store', [$this->server, $this->channel, $first]))->assertForbidden();
        $this->actingAs($plain)->deleteJson(route('messages.destroy', [$this->server, $this->channel, $first]))->assertForbidden();

        $this->actingAs($moderator)->postJson(route('pins.store', [$this->server, $this->channel, $first]))->assertOk();
        $this->assertNotNull($first->fresh()?->pinned_at);
        $this->actingAs($moderator)->deleteJson(route('messages.destroy', [$this->server, $this->channel, $second]))->assertNoContent();
        $this->assertSoftDeleted($second);

        // Moderators still cannot edit someone else's message.
        $this->actingAs($moderator)
            ->patchJson(route('messages.update', [$this->server, $this->channel, $first]), ['content' => 'edited'])
            ->assertForbidden();
    }

    public function test_create_invite_is_needed_to_invite(): void
    {
        $member = $this->joinServer($this->server);
        $payload = ['expires_in' => 60, 'max_uses' => 0];

        $this->actingAs($member)->post(route('servers.invites.store', $this->server), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Invite::query()->count());

        $this->revokeFromEveryone($this->server, Permission::CreateInvite);

        $this->actingAs($member)->post(route('servers.invites.store', $this->server), $payload)->assertForbidden();
        $this->actingAs($member)->get(route('servers.show', $this->server))->assertOk()->assertDontSee('Invite people');
    }

    public function test_manage_server_lets_a_member_see_and_revoke_every_invite(): void
    {
        $inviter = $this->joinServer($this->server);
        $plain = $this->joinServer($this->server);
        $manager = $this->memberWith($this->server, Permission::ManageServer);
        $invite = Invite::factory()->create(['server_id' => $this->server->id, 'inviter_id' => $inviter->id, 'channel_id' => $this->channel->id]);

        $this->actingAs($plain)->get(route('servers.invites.index', $this->server))->assertOk()->assertDontSee($invite->code);
        $this->actingAs($plain)->delete(route('servers.invites.destroy', [$this->server, $invite]))->assertForbidden();

        $this->actingAs($manager)->get(route('servers.invites.index', $this->server))->assertOk()->assertSee($invite->code);
        $this->actingAs($manager)->delete(route('servers.invites.destroy', [$this->server, $invite]))->assertRedirect();
        $this->assertModelMissing($invite);
    }

    public function test_an_administrator_can_do_what_the_owner_can_except_delete_and_transfer(): void
    {
        $this->revokeFromEveryone($this->server, ...Permission::cases());
        $admin = $this->memberWith($this->server, Permission::Administrator);

        $this->actingAs($admin)->get(route('channels.show', [$this->server, $this->channel]))->assertOk();
        $this->actingAs($admin)->postJson(route('messages.store', [$this->server, $this->channel]), ['content' => 'hi'])->assertCreated();
        $this->actingAs($admin)->get(route('servers.edit', $this->server))->assertOk();
        $this->actingAs($admin)->get(route('roles.index', $this->server))->assertOk();
        $this->actingAs($admin)->post(route('channels.store', $this->server), ['name' => 'admins'])->assertSessionHasNoErrors();

        $this->actingAs($admin)->delete(route('servers.destroy', $this->server))->assertForbidden();
        $this->actingAs($admin)->patch(route('servers.owner.update', $this->server), ['user_id' => $admin->id])->assertForbidden();
    }

    public function test_losing_a_role_takes_its_permissions_away(): void
    {
        $member = $this->joinServer($this->server);
        $role = $this->makeRole($this->server, 'Builders', Permission::mask(Permission::ManageChannels));
        $this->giveRole($member, $role);

        $this->actingAs($member)->get(route('channels.create', $this->server))->assertOk();

        $role->users()->detach($member->id);

        $this->actingAs($member)->get(route('channels.create', $this->server))->assertForbidden();
    }
}
