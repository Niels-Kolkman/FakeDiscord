<?php

namespace Tests\Feature\Channels;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Channel;
use App\Models\ChannelCategory;
use App\Models\ChannelPermissionOverride;
use App\Models\Message;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * Private channels and role overrides over HTTP and the
 * websocket auth endpoint, and the channel permissions page.
 */
class PrivateChannelsTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    private Channel $general;

    private Channel $secret;

    private Role $staff;

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
        $this->server = app(CreateServer::class)->create($this->owner, 'Private');
        /** @var Channel $general */
        $general = $this->server->channels()->firstOrFail();
        $this->general = $general;
        $this->secret = $this->server->channels()->create([
            'name' => 'staff-room', 'type' => Channel::TYPE_TEXT, 'position' => 1, 'is_private' => true,
        ]);
        $this->staff = $this->makeRole($this->server, 'Staff', 0);
        $this->overrideChannel($this->secret, $this->staff, allow: Permission::ViewChannels->value);
    }

    private function staffMember(): User
    {
        $user = $this->joinServer($this->server);
        $this->giveRole($user, $this->staff);

        return $user;
    }

    /**
     * @return TestResponse<Response>
     */
    private function authorizeSocket(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678']);
    }

    public function test_a_hidden_private_channel_is_not_returned_anywhere(): void
    {
        $member = $this->joinServer($this->server);
        Message::factory()->inChannel($this->secret)->create(['user_id' => $this->owner->id, 'content' => 'Raise for everyone']);
        $secret = [$this->server, $this->secret];

        $this->actingAs($member)->get(route('servers.show', $this->server))
            ->assertOk()
            ->assertSee('general')
            ->assertDontSee('staff-room')
            ->assertDontSee(route('channels.show', $secret));
        $this->actingAs($member)->get(route('channels.show', $secret))->assertForbidden();
        $this->actingAs($member)->getJson(route('messages.index', $secret))->assertForbidden();
        $this->actingAs($member)->getJson(route('pins.index', $secret))->assertForbidden();
        $this->actingAs($member)->postJson(route('messages.store', $secret), ['content' => 'hi'])->assertForbidden();
        $this->actingAs($member)->get(route('servers.search', [$this->server, 'q' => 'Raise']))
            ->assertOk()
            ->assertSee('No results')
            ->assertDontSee('staff-room');
        $this->actingAs($member)->get(route('servers.search', [$this->server, 'q' => 'Raise', 'channel' => $this->secret->id]))
            ->assertOk()
            ->assertSee('Choose a text channel of this server.');
    }

    public function test_a_hidden_channel_does_not_authorise_its_websocket_channels(): void
    {
        $member = $this->joinServer($this->server);

        $this->authorizeSocket($member, "private-channel.{$this->secret->id}")->assertForbidden();
        $this->authorizeSocket($member, "presence-typing.{$this->secret->id}")->assertForbidden();
        // Public channels still work for them.
        $this->authorizeSocket($member, "private-channel.{$this->general->id}")->assertOk();
    }

    public function test_a_role_allowed_to_view_sees_and_uses_the_private_channel(): void
    {
        $member = $this->staffMember();
        Message::factory()->inChannel($this->secret)->create(['user_id' => $this->owner->id, 'content' => 'Raise for everyone']);
        $secret = [$this->server, $this->secret];

        $this->actingAs($member)->get(route('servers.show', $this->server))
            ->assertOk()
            ->assertSee('staff-room')
            ->assertSee('data-private-channel', false);
        $this->actingAs($member)->get(route('channels.show', $secret))->assertOk()->assertSee('Raise for everyone');
        $this->actingAs($member)->postJson(route('messages.store', $secret), ['content' => 'hello staff'])->assertCreated();
        $this->authorizeSocket($member, "private-channel.{$this->secret->id}")->assertOk();
        $this->authorizeSocket($member, "presence-typing.{$this->secret->id}")->assertOk();
    }

    public function test_a_send_deny_lets_members_read_but_not_post(): void
    {
        $member = $this->joinServer($this->server);
        $this->overrideChannel($this->general, $this->server->defaultRole(), deny: Permission::SendMessages->value);
        $general = [$this->server, $this->general];

        $this->actingAs($member)->get(route('channels.show', $general))->assertOk();
        $this->actingAs($member)->postJson(route('messages.store', $general), ['content' => 'hi'])->assertForbidden();
        $this->authorizeSocket($member, "private-channel.{$this->general->id}")->assertOk();
    }

    public function test_the_server_opens_on_the_first_channel_the_member_can_see(): void
    {
        $this->general->update(['is_private' => true]);
        $this->secret->update(['is_private' => false]);
        $member = $this->joinServer($this->server);

        $this->actingAs($member)->get(route('servers.show', $this->server))
            ->assertOk()
            ->assertViewHas('channel', fn (?Channel $channel): bool => $channel?->is($this->secret) ?? false);
    }

    public function test_the_owner_and_administrators_see_private_channels(): void
    {
        $admin = $this->memberWith($this->server, Permission::Administrator);

        foreach ([$this->owner, $admin] as $user) {
            $this->actingAs($user)->get(route('servers.show', $this->server))->assertOk()->assertSee('staff-room');
            $this->authorizeSocket($user, "private-channel.{$this->secret->id}")->assertOk();
        }
    }

    public function test_channel_managers_cannot_open_the_settings_of_a_channel_they_cannot_see(): void
    {
        $manager = $this->memberWith($this->server, Permission::ManageChannels);
        $secret = [$this->server, $this->secret];

        $this->actingAs($manager)->get(route('channels.edit', $secret))->assertForbidden();
        $this->actingAs($manager)->patch(route('channels.update', $secret), ['name' => 'renamed'])->assertForbidden();
        $this->actingAs($manager)->delete(route('channels.destroy', $secret))->assertForbidden();
        $this->actingAs($manager)->get(route('channels.permissions.edit', $secret))->assertForbidden();
        $this->actingAs($manager)->patch(route('channels.privacy.update', $secret), ['is_private' => '0'])->assertForbidden();
        $this->secret->refresh();
        $this->assertTrue($this->secret->is_private);
        $this->assertSame('staff-room', $this->secret->name);

        // Their own visible channel still works.
        $this->actingAs($manager)->get(route('channels.edit', [$this->server, $this->general]))->assertOk()->assertSee('Permissions');
    }

    public function test_the_permissions_page_needs_manage_channels(): void
    {
        $member = $this->joinServer($this->server);
        $manager = $this->memberWith($this->server, Permission::ManageChannels);
        $general = [$this->server, $this->general];

        $this->actingAs($member)->get(route('channels.permissions.edit', $general))->assertForbidden();
        $this->actingAs($manager)->get(route('channels.permissions.edit', $general))
            ->assertOk()
            ->assertSee('Private channel')
            ->assertSee('Staff')
            ->assertSee('@everyone');
    }

    public function test_managers_can_make_a_channel_private_and_public_again(): void
    {
        $manager = $this->memberWith($this->server, Permission::ManageChannels);
        $member = $this->joinServer($this->server);
        $general = [$this->server, $this->general];

        $this->actingAs($member)->patch(route('channels.privacy.update', $general), ['is_private' => '1'])->assertForbidden();

        $this->actingAs($manager)->patch(route('channels.privacy.update', $general), ['is_private' => '1'])
            ->assertRedirect(route('channels.permissions.edit', $general))
            ->assertSessionHas('status', '#general is now private.');
        $this->assertTrue($this->general->fresh()?->is_private);
        $this->assertFalse($member->can('view', $this->general->fresh()));

        $this->actingAs($this->owner)->patch(route('channels.privacy.update', $general), ['is_private' => '0'])->assertRedirect();
        $this->assertFalse($this->general->fresh()?->is_private);

        $this->actingAs($this->owner)->patch(route('channels.privacy.update', $general), ['is_private' => 'maybe'])
            ->assertSessionHasErrors('is_private');
    }

    public function test_the_owner_sets_and_clears_a_role_override(): void
    {
        $general = [$this->server, $this->general];
        $member = $this->staffMember();

        $this->actingAs($this->owner)
            ->put(route('channels.permissions.update', [...$general, $this->staff]), ['permissions' => ['ViewChannels' => 'deny', 'SendMessages' => 'inherit']])
            ->assertRedirect(route('channels.permissions.edit', $general))
            ->assertSessionHasNoErrors();
        $this->assertFalse($member->can('view', $this->general));

        $this->actingAs($this->owner)
            ->put(route('channels.permissions.update', [...$general, $this->staff]), ['permissions' => ['ViewChannels' => 'inherit', 'SendMessages' => 'inherit']])
            ->assertRedirect();
        $this->assertTrue($member->can('view', $this->general));
        $this->assertSame(0, ChannelPermissionOverride::query()->where('channel_id', $this->general->id)->count());
    }

    public function test_role_overrides_validate_their_input(): void
    {
        $route = route('channels.permissions.update', [$this->server, $this->general, $this->staff]);

        $this->actingAs($this->owner)->put($route, [])->assertSessionHasErrors('permissions');
        $this->actingAs($this->owner)->put($route, ['permissions' => ['ViewChannels' => 'allow']])->assertSessionHasErrors('permissions.SendMessages');
        $this->actingAs($this->owner)->put($route, ['permissions' => ['ViewChannels' => 'yes', 'SendMessages' => 'allow']])->assertSessionHasErrors('permissions.ViewChannels');
        $this->actingAs($this->owner)->put($route, ['permissions' => ['ViewChannels' => 'allow', 'SendMessages' => 'allow', 'ManageMessages' => 'allow']])->assertSessionHasErrors('permissions');
        $this->assertSame(0, ChannelPermissionOverride::query()->where('channel_id', $this->general->id)->count());
    }

    public function test_role_overrides_need_manage_roles_and_a_role_below_your_own(): void
    {
        $route = fn (Role $role): string => route('channels.permissions.update', [$this->server, $this->general, $role]);
        $body = ['permissions' => ['ViewChannels' => 'allow', 'SendMessages' => 'inherit']];
        $channelsOnly = $this->memberWith($this->server, Permission::ManageChannels);
        $low = $this->makeRole($this->server, 'Low', 0);
        $manager = $this->joinServer($this->server);
        $this->giveRole($manager, $this->makeRole($this->server, 'Manager', Permission::mask(Permission::ManageChannels, Permission::ManageRoles)));
        $high = $this->makeRole($this->server, 'High', 0);

        $this->actingAs($channelsOnly)->put($route($low), $body)->assertForbidden();
        $this->actingAs($manager)->put($route($high), $body)->assertForbidden();
        $this->actingAs($manager)->put($route($low), $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($manager)->put($route($this->server->defaultRole()), $body)->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($manager)->get(route('channels.permissions.edit', [$this->server, $this->general]))
            ->assertOk()
            ->assertSee('Only members with Manage Roles can change roles below their highest role.');
    }

    public function test_you_can_only_change_flags_you_have_in_the_channel(): void
    {
        $this->overrideChannel($this->general, $this->server->defaultRole(), deny: Permission::SendMessages->value);
        $low = $this->makeRole($this->server, 'Low', 0);
        $manager = $this->joinServer($this->server);
        $this->giveRole($manager, $this->makeRole($this->server, 'Manager', Permission::mask(Permission::ManageChannels, Permission::ManageRoles)));
        $route = route('channels.permissions.update', [$this->server, $this->general, $low]);

        $this->actingAs($manager)->put($route, ['permissions' => ['ViewChannels' => 'inherit', 'SendMessages' => 'allow']])
            ->assertSessionHasErrors(['permissions.SendMessages' => 'You can only change Send Messages if you have it in this channel.']);
        $this->assertNull($this->general->permissionOverrides()->where('role_id', $low->id)->first());

        // Flags they have can still be changed, leaving the other alone.
        $this->actingAs($manager)->put($route, ['permissions' => ['ViewChannels' => 'deny', 'SendMessages' => 'inherit']])
            ->assertSessionHasNoErrors();
        $this->assertSame(Permission::ViewChannels->value, $this->general->permissionOverrides()->where('role_id', $low->id)->first()?->deny);
    }

    public function test_roles_and_channels_of_other_servers_are_a_404(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Elsewhere');
        /** @var Channel $otherChannel */
        $otherChannel = $other->channels()->firstOrFail();
        $body = ['permissions' => ['ViewChannels' => 'allow', 'SendMessages' => 'inherit']];

        $this->actingAs($this->owner)->put(route('channels.permissions.update', [$this->server, $this->general, $other->defaultRole()]), $body)->assertNotFound();
        $this->actingAs($this->owner)->put(route('channels.permissions.update', [$this->server, $otherChannel, $this->staff]), $body)->assertNotFound();
        $this->actingAs($this->owner)->get(route('channels.permissions.edit', [$this->server, $otherChannel]))->assertNotFound();
        $this->actingAs($this->owner)->patch(route('channels.privacy.update', [$this->server, $otherChannel]), ['is_private' => '1'])->assertNotFound();
        $this->assertFalse($otherChannel->fresh()?->is_private);
    }

    public function test_a_manager_who_cannot_see_every_channel_can_still_reorder_the_rest(): void
    {
        $category = ChannelCategory::factory()->create(['server_id' => $this->server->id, 'name' => 'Hidden', 'position' => 0]);
        $this->secret->update(['category_id' => $category->id, 'position' => 0]);
        $third = $this->server->channels()->create(['name' => 'third', 'type' => Channel::TYPE_TEXT, 'position' => 2]);
        $manager = $this->memberWith($this->server, Permission::ManageChannels);

        // Their sidebar has general and third, without the hidden category.
        $this->actingAs($manager)
            ->putJson(route('channels.layout', $this->server), ['uncategorized' => [$third->id, $this->general->id], 'categories' => []])
            ->assertOk();
        $this->assertSame(0, $third->fresh()?->position);
        $this->assertSame(1, $this->general->fresh()?->position);
        $this->assertSame($category->id, $this->secret->fresh()?->category_id);

        // Naming the hidden channel or category is a stale layout for them.
        $this->actingAs($manager)
            ->putJson(route('channels.layout', $this->server), [
                'uncategorized' => [$this->general->id, $third->id],
                'categories' => [['id' => $category->id, 'channels' => [$this->secret->id]]],
            ])
            ->assertUnprocessable();

        // The owner sees everything, so leaving the category out is stale for them.
        $this->actingAs($this->owner)
            ->putJson(route('channels.layout', $this->server), ['uncategorized' => [$third->id, $this->general->id], 'categories' => []])
            ->assertUnprocessable();
    }
}
