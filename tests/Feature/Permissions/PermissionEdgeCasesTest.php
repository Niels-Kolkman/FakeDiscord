<?php

namespace Tests\Feature\Permissions;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * Edge cases around effective permissions that the matrix does not
 * reach: stale role rows, unknown bits, deleted authors, reactions on other
 * messages, DMs, other servers' channels and the unpin route.
 */
class PermissionEdgeCasesTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private PermissionService $permissions;

    private User $owner;

    private Server $server;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permissions = app(PermissionService::class);
        $this->owner = User::factory()->create();
        $this->server = app(CreateServer::class)->create($this->owner, 'Edges');
        /** @var Channel $channel */
        $channel = $this->server->channels()->firstOrFail();
        $this->channel = $channel;
    }

    public function test_a_former_member_with_a_leftover_role_has_nothing(): void
    {
        $role = $this->makeRole($this->server, 'Admins', Permission::mask(Permission::Administrator, Permission::ManageRoles), 2);
        $below = $this->makeRole($this->server, 'Below', 0, 1);
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $role);

        ServerMember::query()->where('server_id', $this->server->id)->where('user_id', $member->id)->delete();

        $this->assertSame(0, $this->permissions->permissions($member, $this->server));
        $this->assertSame(-1, $this->permissions->highestPosition($member, $this->server));
        $this->assertFalse($this->permissions->canManageRole($member, $below));
        $this->assertFalse($member->can('view', $this->channel));
        $this->assertFalse($member->can('update', $this->server));
        $this->assertCount(0, $this->permissions->visibleChannels($member, $this->server, $this->server->channels()->get()));
    }

    public function test_unknown_bits_in_a_role_grant_nothing_known(): void
    {
        $this->revokeFromEveryone($this->server, ...Permission::cases());
        $unknown = 1 << 7; // Not a Permission case.
        $this->assertNull(Permission::tryFrom($unknown));
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $this->makeRole($this->server, 'Odd', $unknown));

        $this->assertSame([], Permission::fromMask($this->permissions->permissions($member, $this->server)));
        foreach (Permission::cases() as $permission) {
            $this->assertFalse($this->permissions->has($member, $this->server, $permission), $permission->name);
        }
        $this->assertFalse($member->can('view', $this->channel));
    }

    public function test_administrator_on_a_low_role_still_gives_everything(): void
    {
        $this->revokeFromEveryone($this->server, ...Permission::cases());
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $this->makeRole($this->server, 'Low admin', Permission::mask(Permission::Administrator), 1));
        $this->giveRole($member, $this->makeRole($this->server, 'High nothing', 0, 5));

        $this->assertSame(Permission::all(), $this->permissions->permissions($member, $this->server));
        $this->assertTrue($member->can('manageMessages', $this->channel));
    }

    public function test_visible_channels_keep_their_order_and_are_reindexed(): void
    {
        $member = $this->joinServer($this->server);
        Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'second']);
        Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'third']);
        // Reversed and with a gap in the keys.
        $channels = $this->server->channels()->get()->sortByDesc('id')->forget(0);

        $visible = $this->permissions->visibleChannels($member, $this->server, $channels);

        $this->assertSame(range(0, $channels->count() - 1), array_keys($visible->all()));
        $this->assertSame($channels->pluck('id')->values()->all(), $visible->pluck('id')->all());
    }

    public function test_roles_in_one_server_do_not_open_channels_of_another(): void
    {
        $other = app(CreateServer::class)->create(User::factory()->create(), 'Elsewhere');
        /** @var Channel $otherChannel */
        $otherChannel = $other->channels()->firstOrFail();
        $admin = $this->memberWith($this->server, Permission::Administrator);

        $this->assertSame(0, $this->permissions->channelPermissions($admin, $otherChannel));
        foreach (['view', 'sendMessages', 'attachFiles', 'addReactions', 'manageMessages'] as $ability) {
            $this->assertFalse($admin->can($ability, $otherChannel), $ability);
            $this->assertFalse($this->owner->can($ability, $otherChannel), "owner of another server: {$ability}");
        }
    }

    public function test_a_message_without_an_author_can_only_be_moderated(): void
    {
        $member = $this->joinServer($this->server);
        $moderator = $this->memberWith($this->server, Permission::ManageMessages);
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => null]);

        foreach ([$member, $moderator, $this->owner] as $user) {
            $this->assertFalse($user->can('update', $message), 'Nobody is the author.');
        }
        $this->assertFalse($member->can('delete', $message));
        $this->assertTrue($moderator->can('delete', $message));
        $this->assertTrue($moderator->can('pin', $message));
        $this->assertTrue($member->can('react', $message));
    }

    public function test_the_author_still_edits_and_deletes_without_send_messages(): void
    {
        // Pins current behaviour: your own message only needs View Channels.
        $author = $this->joinServer($this->server);
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $author->id]);
        $this->revokeFromEveryone($this->server, Permission::SendMessages);

        $this->assertTrue($author->can('update', $message));
        $this->assertTrue($author->can('delete', $message));
    }

    public function test_the_same_emoji_on_another_message_does_not_let_you_add_it(): void
    {
        $member = $this->joinServer($this->server);
        $reacted = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id]);
        $fresh = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id]);
        Reaction::factory()->create(['message_id' => $reacted->id, 'user_id' => $this->owner->id, 'emoji' => '👍']);
        $this->revokeFromEveryone($this->server, Permission::AddReactions);

        $this->assertTrue($member->can('addReaction', [$reacted, '👍']));
        $this->assertFalse($member->can('addReaction', [$fresh, '👍']));
    }

    public function test_strangers_cannot_join_an_existing_reaction(): void
    {
        $stranger = User::factory()->create();
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id]);
        Reaction::factory()->create(['message_id' => $message->id, 'user_id' => $this->owner->id, 'emoji' => '👍']);

        $this->assertFalse($stranger->can('addReaction', [$message, '👍']));
        $this->assertFalse($stranger->can('addReaction', [$message, '🎉']));
    }

    public function test_dm_participants_add_any_reaction_and_outsiders_none(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $conversation = Conversation::factory()->between($alice, $bob)->create();
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => $alice->id]);

        $this->assertTrue($bob->can('addReaction', [$message, '🎉']));
        $this->assertFalse($bob->can('delete', $message), 'No moderators in DMs.');
        $this->assertTrue($alice->can('delete', $message));
        $this->assertFalse($this->owner->can('addReaction', [$message, '🎉']));
        $this->assertFalse($this->owner->can('delete', $message));
    }

    public function test_unpinning_needs_manage_messages(): void
    {
        $plain = $this->joinServer($this->server);
        $moderator = $this->memberWith($this->server, Permission::ManageMessages);
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $plain->id, 'pinned_at' => now()]);
        $url = route('pins.destroy', [$this->server, $this->channel, $message]);

        $this->actingAs($plain)->deleteJson($url)->assertForbidden();
        $this->assertNotNull($message->refresh()->pinned_at);

        $this->actingAs($moderator)->deleteJson($url)->assertSuccessful();
        $this->assertNull($message->refresh()->pinned_at);
    }

    public function test_a_moderator_who_cannot_see_the_channel_cannot_delete_or_pin_there(): void
    {
        $author = $this->joinServer($this->server);
        $moderator = $this->memberWith($this->server, Permission::ManageMessages);
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $author->id]);
        $this->revokeFromEveryone($this->server, Permission::ViewChannels);

        $this->actingAs($moderator)->deleteJson(route('messages.destroy', [$this->server, $this->channel, $message]))->assertForbidden();
        $this->actingAs($moderator)->postJson(route('pins.store', [$this->server, $this->channel, $message]))->assertForbidden();
        $this->assertNotSoftDeleted($message);
        $this->assertNull($message->fresh()?->pinned_at);
    }

    public function test_only_verified_users_can_create_servers(): void
    {
        $this->assertTrue(User::factory()->create()->can('create', Server::class));
        $this->assertFalse(User::factory()->unverified()->create()->can('create', Server::class));
    }
}
