<?php

namespace Tests\Feature\Permissions;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Server;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * PermissionService works out a member's effective permissions.
 */
class PermissionServiceTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private PermissionService $permissions;

    private User $owner;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permissions = app(PermissionService::class);
        $this->owner = User::factory()->create();
        $this->server = app(CreateServer::class)->create($this->owner, 'Permissions');
    }

    public function test_someone_who_is_not_a_member_has_nothing(): void
    {
        $this->assertSame(0, $this->permissions->permissions(User::factory()->create(), $this->server));
    }

    public function test_the_owner_has_everything_whatever_their_roles(): void
    {
        $this->revokeFromEveryone($this->server, ...Permission::cases());

        $this->assertSame(Permission::all(), $this->permissions->permissions($this->owner, $this->server));
    }

    public function test_a_plain_member_has_the_everyone_permissions(): void
    {
        $member = $this->joinServer($this->server);

        $this->assertSame(Permission::everyoneDefaults(), $this->permissions->permissions($member, $this->server));

        $this->revokeFromEveryone($this->server, Permission::SendMessages);

        $this->assertSame(
            Permission::everyoneDefaults() & ~Permission::SendMessages->value,
            $this->permissions->permissions($member, $this->server),
        );
    }

    public function test_roles_add_to_everyone(): void
    {
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $this->makeRole($this->server, 'Mods', Permission::mask(Permission::ManageMessages)));
        $this->giveRole($member, $this->makeRole($this->server, 'Builders', Permission::mask(Permission::ManageChannels)));

        $this->assertSame(
            Permission::everyoneDefaults() | Permission::mask(Permission::ManageMessages, Permission::ManageChannels),
            $this->permissions->permissions($member, $this->server),
        );
    }

    public function test_roles_nobody_gave_the_member_do_not_count(): void
    {
        $member = $this->joinServer($this->server);
        $this->makeRole($this->server, 'Mods', Permission::mask(Permission::ManageMessages));

        $this->assertFalse($this->permissions->has($member, $this->server, Permission::ManageMessages));
    }

    public function test_roles_of_another_server_do_not_count(): void
    {
        $member = $this->joinServer($this->server);
        $other = app(CreateServer::class)->create(User::factory()->create(), 'Other');
        $this->joinServer($other, $member);
        $this->giveRole($member, $this->makeRole($other, 'Admin there', Permission::mask(Permission::Administrator)));

        $this->assertSame(Permission::everyoneDefaults(), $this->permissions->permissions($member, $this->server));
        $this->assertSame(Permission::all(), $this->permissions->permissions($member, $other));
    }

    public function test_administrator_means_every_permission(): void
    {
        $this->revokeFromEveryone($this->server, ...Permission::cases());
        $admin = $this->memberWith($this->server, Permission::Administrator);

        $this->assertSame(Permission::all(), $this->permissions->permissions($admin, $this->server));
    }

    public function test_administrator_on_everyone_makes_every_member_an_administrator(): void
    {
        $member = $this->joinServer($this->server);
        $this->server->defaultRole()->update(['permissions' => Permission::mask(Permission::Administrator)]);

        $this->assertSame(Permission::all(), $this->permissions->permissions($member, $this->server));
    }

    public function test_a_server_without_an_everyone_row_counts_as_having_the_defaults(): void
    {
        $member = $this->joinServer($this->server);
        $this->server->roles()->where('is_default', true)->delete();

        $this->assertSame(Permission::everyoneDefaults(), $this->permissions->permissions($member, $this->server));
        $this->assertSame(0, $this->server->roles()->count(), 'Reading permissions creates nothing.');
    }

    public function test_changes_are_seen_straight_away(): void
    {
        $member = $this->joinServer($this->server);
        $role = $this->makeRole($this->server, 'Mods', Permission::mask(Permission::ManageMessages));

        $this->assertFalse($this->permissions->has($member, $this->server, Permission::ManageMessages));
        $this->giveRole($member, $role);
        $this->assertTrue($this->permissions->has($member, $this->server, Permission::ManageMessages));
        $role->users()->detach($member->id);
        $this->assertFalse($this->permissions->has($member, $this->server, Permission::ManageMessages));
    }

    public function test_channel_permissions_are_the_server_permissions_for_now(): void
    {
        $member = $this->memberWith($this->server, Permission::ManageMessages);
        /** @var Channel $channel */
        $channel = $this->server->channels()->firstOrFail();

        $this->assertSame(
            $this->permissions->permissions($member, $this->server),
            $this->permissions->channelPermissions($member, $channel),
        );
        $this->assertTrue($this->permissions->hasInChannel($member, $channel, Permission::ManageMessages));
    }

    public function test_visible_channels_need_view_channels(): void
    {
        $member = $this->joinServer($this->server);
        Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'second']);
        $channels = $this->server->channels()->get();

        $this->assertCount(2, $this->permissions->visibleChannels($member, $this->server, $channels));

        $this->revokeFromEveryone($this->server, Permission::ViewChannels);

        $this->assertCount(0, $this->permissions->visibleChannels($member, $this->server, $channels));
        $this->assertCount(2, $this->permissions->visibleChannels($this->owner, $this->server, $channels));
    }

    public function test_highest_position(): void
    {
        $member = $this->joinServer($this->server);
        $low = $this->makeRole($this->server, 'Low', 0, 1);
        $high = $this->makeRole($this->server, 'High', 0, 5);

        $this->assertSame(PHP_INT_MAX, $this->permissions->highestPosition($this->owner, $this->server));
        $this->assertSame(-1, $this->permissions->highestPosition(User::factory()->create(), $this->server));
        $this->assertSame(0, $this->permissions->highestPosition($member, $this->server));

        $this->giveRole($member, $low);
        $this->assertSame(1, $this->permissions->highestPosition($member, $this->server));

        $this->giveRole($member, $high);
        $this->assertSame(5, $this->permissions->highestPosition($member, $this->server));
    }

    public function test_managing_a_role_needs_manage_roles_and_a_higher_role(): void
    {
        $below = $this->makeRole($this->server, 'Below', 0, 1);
        $managerRole = $this->makeRole($this->server, 'Managers', Permission::mask(Permission::ManageRoles), 2);
        $above = $this->makeRole($this->server, 'Above', 0, 3);
        $everyone = $this->server->defaultRole();

        $manager = $this->joinServer($this->server);
        $this->giveRole($manager, $managerRole);
        $plain = $this->joinServer($this->server);

        $this->assertTrue($this->permissions->canManageRole($manager, $below));
        $this->assertTrue($this->permissions->canManageRole($manager, $everyone));
        $this->assertFalse($this->permissions->canManageRole($manager, $managerRole), 'Not their own highest role.');
        $this->assertFalse($this->permissions->canManageRole($manager, $above));

        foreach ([$below, $managerRole, $above, $everyone] as $role) {
            $this->assertTrue($this->permissions->canManageRole($this->owner, $role), "owner: {$role->name}");
            $this->assertFalse($this->permissions->canManageRole($plain, $role), "plain member: {$role->name}");
        }
    }

    public function test_administrators_are_still_bound_by_the_hierarchy(): void
    {
        $adminRole = $this->makeRole($this->server, 'Admins', Permission::mask(Permission::Administrator), 2);
        $below = $this->makeRole($this->server, 'Below', 0, 1);
        $above = $this->makeRole($this->server, 'Above', 0, 3);
        $admin = $this->joinServer($this->server);
        $this->giveRole($admin, $adminRole);

        $this->assertTrue($this->permissions->canManageRole($admin, $below));
        $this->assertFalse($this->permissions->canManageRole($admin, $adminRole));
        $this->assertFalse($this->permissions->canManageRole($admin, $above));
    }
}
