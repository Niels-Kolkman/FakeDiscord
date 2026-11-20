<?php

namespace Tests\Feature\Channels;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * Edge cases not covered by PrivateChannelsTest and
 * ChannelOverridesTest: channels hidden by a role deny rather than by being
 * private, message actions in hidden channels, access changing between
 * requests, combinations of @everyone and role overrides, servers without
 * an @everyone row, and the permissions page showing what is stored.
 */
class ChannelOverrideEdgeCasesTest extends TestCase
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
        $this->server = app(CreateServer::class)->create($this->owner, 'Edges');
        /** @var Channel $general */
        $general = $this->server->channels()->firstOrFail();
        $this->general = $general;
        $this->secret = $this->server->channels()->create([
            'name' => 'staff-room', 'type' => Channel::TYPE_TEXT, 'position' => 1, 'is_private' => true,
        ]);
        $this->staff = $this->makeRole($this->server, 'Staff', 0);
        $this->overrideChannel($this->secret, $this->staff, allow: Permission::ViewChannels->value);
    }

    private function viewBit(): int
    {
        return Permission::ViewChannels->value;
    }

    private function sendBit(): int
    {
        return Permission::SendMessages->value;
    }

    /**
     * @return TestResponse<Response>
     */
    private function authorizeSocket(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678']);
    }

    // --- Hidden by a role deny, not by being private -----------------------

    public function test_a_public_channel_hidden_by_a_role_deny_is_hidden_over_http_and_the_websocket(): void
    {
        $muted = $this->makeRole($this->server, 'Muted', 0);
        $this->overrideChannel($this->general, $muted, deny: $this->viewBit());
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $muted);
        $general = [$this->server, $this->general];

        $this->actingAs($member)->get(route('servers.show', $this->server))
            ->assertOk()
            ->assertDontSee(route('channels.show', $general));
        $this->actingAs($member)->get(route('channels.show', $general))->assertForbidden();
        $this->actingAs($member)->getJson(route('messages.index', $general))->assertForbidden();
        $this->actingAs($member)->getJson(route('pins.index', $general))->assertForbidden();
        $this->actingAs($member)->postJson(route('messages.store', $general), ['content' => 'hi'])->assertForbidden();
        $this->authorizeSocket($member, "private-channel.{$this->general->id}")->assertForbidden();
        $this->authorizeSocket($member, "presence-typing.{$this->general->id}")->assertForbidden();

        // Members without the role are unaffected.
        $other = $this->joinServer($this->server);
        $this->actingAs($other)->get(route('channels.show', $general))->assertOk();
        $this->authorizeSocket($other, "private-channel.{$this->general->id}")->assertOk();
    }

    public function test_an_everyone_view_deny_hides_a_public_channel_until_a_role_allows_it(): void
    {
        $this->overrideChannel($this->general, $this->server->defaultRole(), deny: $this->viewBit());
        $member = $this->joinServer($this->server);
        $staffer = $this->joinServer($this->server);
        $this->overrideChannel($this->general, $this->staff, allow: $this->viewBit());
        $this->giveRole($staffer, $this->staff);

        $this->assertFalse($member->can('view', $this->general));
        $this->assertTrue($staffer->can('view', $this->general));
        $this->authorizeSocket($member, "private-channel.{$this->general->id}")->assertForbidden();
        $this->authorizeSocket($staffer, "private-channel.{$this->general->id}")->assertOk();
    }

    public function test_a_member_who_may_read_but_not_send_can_still_join_typing_presence(): void
    {
        $this->overrideChannel($this->general, $this->server->defaultRole(), deny: $this->sendBit());
        $member = $this->joinServer($this->server);

        $this->authorizeSocket($member, "presence-typing.{$this->general->id}")->assertOk();
    }

    // --- Message actions in a channel the member can no longer see ---------

    public function test_authors_lose_their_messages_when_a_channel_becomes_private(): void
    {
        $author = $this->joinServer($this->server);
        $message = Message::factory()->inChannel($this->general)->create(['user_id' => $author->id, 'content' => 'mine']);
        $this->actingAs($author)->postJson(route('reactions.store', [$this->server, $this->general, $message]), ['emoji' => '👍'])->assertOk();

        $this->general->update(['is_private' => true]);
        $route = [$this->server, $this->general, $message];

        $this->actingAs($author)->patchJson(route('messages.update', $route), ['content' => 'edited'])->assertForbidden();
        $this->actingAs($author)->deleteJson(route('messages.destroy', $route))->assertForbidden();
        $this->actingAs($author)->postJson(route('reactions.store', $route), ['emoji' => '🎉'])->assertForbidden();
        $this->actingAs($author)->deleteJson(route('reactions.destroy', $route), ['emoji' => '👍'])->assertForbidden();
        $this->assertSame('mine', $message->fresh()?->content);
    }

    public function test_moderators_cannot_moderate_a_channel_hidden_from_them(): void
    {
        $moderator = $this->memberWith($this->server, Permission::ManageMessages);
        $message = Message::factory()->inChannel($this->secret)->create(['user_id' => $this->owner->id]);
        $route = [$this->server, $this->secret, $message];

        $this->actingAs($moderator)->postJson(route('pins.store', $route))->assertForbidden();
        $this->actingAs($moderator)->deleteJson(route('pins.destroy', $route))->assertForbidden();
        $this->actingAs($moderator)->deleteJson(route('messages.destroy', $route))->assertForbidden();
        $this->assertNotNull($message->fresh());

        // Once their role is allowed in, Manage Messages works there.
        $this->giveRole($moderator, $this->staff);
        $this->actingAs($moderator)->postJson(route('pins.store', $route))->assertOk();
    }

    // --- Access follows changes between requests (nothing is cached) -------

    public function test_losing_the_role_closes_the_private_channel_on_the_next_request(): void
    {
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $this->staff);
        $secret = [$this->server, $this->secret];

        $this->actingAs($member)->get(route('channels.show', $secret))->assertOk();
        $this->authorizeSocket($member, "private-channel.{$this->secret->id}")->assertOk();

        $this->staff->users()->detach($member->id);

        $this->actingAs($member)->get(route('channels.show', $secret))->assertForbidden();
        $this->authorizeSocket($member, "private-channel.{$this->secret->id}")->assertForbidden();
    }

    public function test_making_a_channel_public_again_keeps_its_role_overrides(): void
    {
        $muted = $this->makeRole($this->server, 'Muted', 0);
        $this->overrideChannel($this->secret, $muted, deny: $this->sendBit());
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $muted);

        $this->actingAs($this->owner)
            ->patch(route('channels.privacy.update', [$this->server, $this->secret]), ['is_private' => '0'])
            ->assertRedirect();
        $this->secret->refresh();

        $this->assertTrue($member->can('view', $this->secret));
        $this->assertFalse($member->can('sendMessages', $this->secret));
        $this->assertSame(2, $this->secret->permissionOverrides()->count());
    }

    // --- Resolution combinations -------------------------------------------

    public function test_a_role_allow_opens_a_private_channel_even_when_the_server_withholds_view_channels(): void
    {
        $this->revokeFromEveryone($this->server, Permission::ViewChannels);
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $this->staff);

        $this->assertFalse($member->can('view', $this->general));
        $this->assertTrue($member->can('view', $this->secret));
        $this->assertTrue($member->can('sendMessages', $this->secret));
    }

    public function test_an_everyone_send_deny_still_applies_in_a_private_channel_a_role_opens(): void
    {
        $this->overrideChannel($this->secret, $this->server->defaultRole(), deny: $this->sendBit());
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $this->staff);

        $this->assertTrue($member->can('view', $this->secret));
        $this->assertFalse($member->can('sendMessages', $this->secret));
        $this->actingAs($member)
            ->postJson(route('messages.store', [$this->server, $this->secret]), ['content' => 'hi'])
            ->assertForbidden();
    }

    public function test_allowing_send_messages_without_view_channels_does_not_let_anyone_post(): void
    {
        $speakers = $this->makeRole($this->server, 'Speakers', 0);
        $this->overrideChannel($this->secret, $speakers, allow: $this->sendBit());
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $speakers);

        $this->assertTrue(app(PermissionService::class)->hasInChannel($member, $this->secret, Permission::SendMessages));
        $this->assertFalse($member->can('sendMessages', $this->secret));
        $this->actingAs($member)
            ->postJson(route('messages.store', [$this->server, $this->secret]), ['content' => 'hi'])
            ->assertForbidden();
    }

    public function test_overrides_still_apply_on_a_server_without_an_everyone_row(): void
    {
        $member = $this->joinServer($this->server);
        $staffer = $this->joinServer($this->server);
        $this->giveRole($staffer, $this->staff);
        $muted = $this->makeRole($this->server, 'Muted', 0);
        $this->overrideChannel($this->general, $muted, deny: $this->sendBit());
        $this->giveRole($member, $muted);
        // Older data: no @everyone role (its overrides go with it).
        Role::query()->where('server_id', $this->server->id)->where('is_default', true)->delete();

        $this->assertTrue($member->can('view', $this->general), '@everyone defaults include View Channels');
        $this->assertFalse($member->can('sendMessages', $this->general));
        $this->assertFalse($member->can('view', $this->secret));
        $this->assertTrue($staffer->can('view', $this->secret));
        $this->assertSame(
            ['general', 'staff-room'],
            app(PermissionService::class)->visibleChannels($staffer, $this->server, $this->server->channels()->orderBy('position')->get())->pluck('name')->all(),
        );
    }

    public function test_visible_channels_includes_private_channels_for_the_owner_and_nothing_for_outsiders(): void
    {
        $service = app(PermissionService::class);
        $channels = $this->server->channels()->orderBy('position')->get();

        $this->assertSame(['general', 'staff-room'], $service->visibleChannels($this->owner, $this->server, $channels)->pluck('name')->all());
        $this->assertSame([], $service->visibleChannels(User::factory()->create(), $this->server, $channels)->all());
    }

    public function test_search_finds_messages_in_a_private_channel_for_members_who_can_see_it(): void
    {
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $this->staff);
        Message::factory()->inChannel($this->secret)->create(['user_id' => $this->owner->id, 'content' => 'Quarterly raise plan']);

        $this->actingAs($member)->get(route('servers.search', [$this->server, 'q' => 'Quarterly']))
            ->assertOk()
            ->assertSee('data-search-result-content', false)
            ->assertSee('raise plan') // "Quarterly" is wrapped in <mark>
            ->assertDontSee('No results');
    }

    // --- The permissions page ----------------------------------------------

    public function test_the_permissions_page_shows_the_stored_states(): void
    {
        $this->overrideChannel($this->general, $this->staff, allow: $this->viewBit(), deny: $this->sendBit());

        $html = $this->actingAs($this->owner)
            ->get(route('channels.permissions.edit', [$this->server, $this->secret]))
            ->assertOk()
            ->getContent();
        $this->assertIsString($html);
        // The privacy box is ticked for the private channel.
        $this->assertMatchesRegularExpression('/name="is_private" value="1"[^>]*checked/', $html);

        $html = $this->actingAs($this->owner)
            ->get(route('channels.permissions.edit', [$this->server, $this->general]))
            ->assertOk()
            ->getContent();
        $this->assertIsString($html);
        $this->assertDoesNotMatchRegularExpression('/name="is_private" value="1"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="permissions\[ViewChannels\]" value="allow"\s[^>]*checked/', $this->roleBlock($html, $this->staff));
        $this->assertMatchesRegularExpression('/name="permissions\[SendMessages\]" value="deny"\s[^>]*checked/', $this->roleBlock($html, $this->staff));
        $everyone = $this->roleBlock($html, $this->server->defaultRole());
        $this->assertMatchesRegularExpression('/name="permissions\[ViewChannels\]" value="inherit"\s[^>]*checked/', $everyone);
        $this->assertMatchesRegularExpression('/name="permissions\[SendMessages\]" value="inherit"\s[^>]*checked/', $everyone);
    }

    public function test_a_manager_let_into_a_private_channel_by_a_role_can_manage_its_permissions(): void
    {
        $manager = $this->memberWith($this->server, Permission::ManageChannels);
        $this->giveRole($manager, $this->staff);
        $secret = [$this->server, $this->secret];

        $this->actingAs($manager)->get(route('channels.permissions.edit', $secret))->assertOk();
        $this->actingAs($manager)->patch(route('channels.privacy.update', $secret), ['is_private' => '0'])->assertRedirect();
        $this->assertFalse($this->secret->fresh()?->is_private);
    }

    public function test_you_cannot_lift_an_existing_deny_on_a_flag_you_do_not_have(): void
    {
        $low = $this->makeRole($this->server, 'Low', 0);
        $this->overrideChannel($this->general, $low, deny: $this->sendBit());
        $this->overrideChannel($this->general, $this->server->defaultRole(), deny: $this->sendBit());
        $manager = $this->joinServer($this->server);
        $this->giveRole($manager, $this->makeRole($this->server, 'Manager', Permission::mask(Permission::ManageChannels, Permission::ManageRoles)));

        $this->actingAs($manager)
            ->put(route('channels.permissions.update', [$this->server, $this->general, $low]), ['permissions' => ['ViewChannels' => 'inherit', 'SendMessages' => 'inherit']])
            ->assertSessionHasErrors('permissions.SendMessages');

        $this->assertSame($this->sendBit(), $this->general->permissionOverrides()->where('role_id', $low->id)->first()?->deny);
    }

    public function test_a_refused_change_is_shown_next_to_the_role_it_was_for(): void
    {
        $this->overrideChannel($this->general, $this->server->defaultRole(), deny: $this->sendBit());
        $low = $this->makeRole($this->server, 'Low', 0);
        $manager = $this->joinServer($this->server);
        $this->giveRole($manager, $this->makeRole($this->server, 'Manager', Permission::mask(Permission::ManageChannels, Permission::ManageRoles)));
        $page = route('channels.permissions.edit', [$this->server, $this->general]);

        $this->actingAs($manager)
            ->from($page)
            ->followingRedirects()
            ->put(route('channels.permissions.update', [$this->server, $this->general, $low]), [
                'role_id' => $low->id,
                'permissions' => ['ViewChannels' => 'deny', 'SendMessages' => 'allow'],
            ])
            ->assertOk()
            ->assertSee('You can only change Send Messages if you have it in this channel.');
    }

    /**
     * The <li> of one role on the permissions page.
     */
    private function roleBlock(string $html, Role $role): string
    {
        $this->assertSame(1, preg_match('/<li[^>]*data-role-override="'.$role->id.'".*?<\/li>/s', $html, $match));

        return $match[0];
    }
}
