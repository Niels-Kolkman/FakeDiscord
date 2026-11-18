<?php

namespace Tests\Feature\Permissions;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * ISSUE-27: members with Manage Roles manage the roles below their own
 * highest role, and only hand out flags they have. The owner manages all.
 *
 * Hierarchy here: Above (3) > Managers (2, Manage Roles + Manage Messages)
 * > Below (1) > @everyone (0).
 */
class RoleHierarchyTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $plain;

    private Server $server;

    private Role $below;

    private Role $managers;

    private Role $above;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->server = app(CreateServer::class)->create($this->owner, 'Hierarchy');
        $this->below = $this->makeRole($this->server, 'Below', 0, 1);
        $this->managers = $this->makeRole($this->server, 'Managers', Permission::mask(Permission::ManageRoles, Permission::ManageMessages), 2);
        $this->above = $this->makeRole($this->server, 'Above', 0, 3);

        $this->manager = $this->joinServer($this->server);
        $this->giveRole($this->manager, $this->managers);
        $this->plain = $this->joinServer($this->server);
    }

    public function test_manage_roles_opens_the_role_pages(): void
    {
        $this->actingAs($this->plain)->get(route('roles.index', $this->server))->assertForbidden();

        $this->actingAs($this->manager)->get(route('roles.index', $this->server))
            ->assertOk()
            ->assertSee(route('roles.edit', [$this->server, $this->below]), false)
            ->assertDontSee(route('roles.edit', [$this->server, $this->above]), false)
            ->assertDontSee(route('roles.edit', [$this->server, $this->managers]), false);

        $this->actingAs($this->manager)->get(route('servers.show', $this->server))->assertOk()->assertSee(route('roles.index', $this->server), false);
    }

    public function test_only_roles_below_can_be_opened_edited_or_deleted(): void
    {
        $this->actingAs($this->manager)->get(route('roles.edit', [$this->server, $this->below]))->assertOk();
        $this->actingAs($this->manager)
            ->patch(route('roles.update', [$this->server, $this->below]), ['name' => 'Lower'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Lower', $this->below->fresh()?->name);

        foreach ([$this->managers, $this->above] as $role) {
            $this->actingAs($this->manager)->get(route('roles.edit', [$this->server, $role]))->assertForbidden();
            $this->actingAs($this->manager)->patch(route('roles.update', [$this->server, $role]), ['name' => 'Hacked'])->assertForbidden();
            $this->actingAs($this->manager)->delete(route('roles.destroy', [$this->server, $role]))->assertForbidden();
            $this->assertNotSame('Hacked', $role->fresh()?->name);
            $this->assertModelExists($role);
        }

        $this->actingAs($this->manager)->delete(route('roles.destroy', [$this->server, $this->below]))->assertRedirect();
        $this->assertModelMissing($this->below);
    }

    public function test_only_roles_below_can_be_given_or_taken(): void
    {
        $this->actingAs($this->manager)
            ->post(route('roles.members.store', [$this->server, $this->below]), ['user_id' => $this->plain->id])
            ->assertSessionHasNoErrors();
        $this->assertTrue($this->below->users()->whereKey($this->plain->id)->exists());

        $this->actingAs($this->manager)
            ->delete(route('roles.members.destroy', [$this->server, $this->below, $this->plain]))
            ->assertRedirect();
        $this->assertFalse($this->below->users()->whereKey($this->plain->id)->exists());

        foreach ([$this->managers, $this->above] as $role) {
            $this->actingAs($this->manager)
                ->post(route('roles.members.store', [$this->server, $role]), ['user_id' => $this->plain->id])
                ->assertForbidden();
            $this->assertFalse($role->users()->whereKey($this->plain->id)->exists());
        }

        // Not even from themselves: their own role is not below them.
        $this->actingAs($this->manager)
            ->delete(route('roles.members.destroy', [$this->server, $this->managers, $this->manager]))
            ->assertForbidden();
    }

    public function test_a_role_cannot_be_moved_past_the_movers_highest_role(): void
    {
        $this->actingAs($this->manager)
            ->post(route('roles.move', [$this->server, $this->below]), ['direction' => 'up'])
            ->assertSessionHasErrors(['direction' => 'You can only move roles below your own highest role.']);
        $this->assertSame(1, $this->below->fresh()?->position);

        $this->actingAs($this->manager)
            ->post(route('roles.move', [$this->server, $this->above]), ['direction' => 'down'])
            ->assertForbidden();

        // The owner can.
        $this->actingAs($this->owner)
            ->post(route('roles.move', [$this->server, $this->below]), ['direction' => 'up'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $this->below->fresh()?->position);
    }

    public function test_roles_below_can_be_moved_among_themselves(): void
    {
        $lowest = $this->makeRole($this->server, 'Lowest', 0, 1);
        $this->below->update(['position' => 2]);
        $this->managers->update(['position' => 3]);
        $this->above->update(['position' => 4]);

        $this->actingAs($this->manager)
            ->post(route('roles.move', [$this->server, $lowest]), ['direction' => 'up'])
            ->assertSessionHasNoErrors();

        $this->assertGreaterThan($this->below->fresh()?->position, $lowest->fresh()?->position);
        $this->assertGreaterThan($lowest->fresh()?->position, $this->managers->fresh()?->position);
    }

    public function test_a_manager_creates_roles_right_below_their_own(): void
    {
        $this->actingAs($this->manager)
            ->post(route('roles.store', $this->server), ['name' => 'Helpers', 'permissions' => [Permission::ManageMessages->value]])
            ->assertSessionHasNoErrors();

        $helpers = $this->server->roles()->where('name', 'Helpers')->sole();
        $managers = $this->managers->fresh();
        $this->assertNotNull($managers);

        $this->assertLessThan($managers->position, $helpers->position);
        $this->assertGreaterThan($this->below->fresh()?->position, $helpers->position);
        $this->assertGreaterThan($managers->position, $this->above->fresh()?->position);
        $this->assertTrue($this->manager->can('manage', $helpers));

        // The owner's new roles still go to the top.
        $this->actingAs($this->owner)->post(route('roles.store', $this->server), ['name' => 'Top'])->assertSessionHasNoErrors();
        $top = $this->server->roles()->where('name', 'Top')->sole();
        $this->assertSame((int) $this->server->roles()->max('position'), $top->position);
    }

    public function test_a_member_whose_manage_roles_comes_from_everyone_cannot_create_roles(): void
    {
        $everyone = $this->server->defaultRole();
        $everyone->update(['permissions' => (int) $everyone->permissions | Permission::ManageRoles->value]);
        $count = $this->server->roles()->count();

        $this->actingAs($this->plain)
            ->post(route('roles.store', $this->server), ['name' => 'Mine'])
            ->assertSessionHasErrors(['name' => 'You need a role of your own to create roles below it.']);
        $this->assertSame($count, $this->server->roles()->count());

        // They can still change @everyone, within their own flags.
        $this->actingAs($this->plain)->get(route('roles.edit', [$this->server, $everyone]))->assertOk();
    }

    public function test_only_flags_the_manager_has_can_be_changed(): void
    {
        // Granting a flag they do not have.
        $this->actingAs($this->manager)
            ->patch(route('roles.update', [$this->server, $this->below]), [
                'name' => 'Below',
                'permissions' => [Permission::ManageMessages->value, Permission::BanMembers->value],
            ])
            ->assertSessionHasErrors(['permissions' => 'You can only change permissions you have yourself: Ban Members.']);
        $this->assertSame(0, $this->below->fresh()?->permissions);

        // Creating a role with one.
        $this->actingAs($this->manager)
            ->post(route('roles.store', $this->server), ['name' => 'Admins', 'permissions' => [Permission::Administrator->value]])
            ->assertSessionHasErrors('permissions');
        $this->assertFalse($this->server->roles()->where('name', 'Admins')->exists());

        // Flags they have are fine.
        $this->actingAs($this->manager)
            ->patch(route('roles.update', [$this->server, $this->below]), ['name' => 'Below', 'permissions' => [Permission::ManageMessages->value]])
            ->assertSessionHasNoErrors();
        $this->assertSame(Permission::ManageMessages->value, $this->below->fresh()?->permissions);
    }

    public function test_flags_the_manager_lacks_cannot_be_taken_away_either_but_are_kept(): void
    {
        $this->below->update(['permissions' => Permission::mask(Permission::BanMembers)]);

        // Leaving Ban Members unticked would remove it.
        $this->actingAs($this->manager)
            ->patch(route('roles.update', [$this->server, $this->below]), ['name' => 'Below', 'permissions' => []])
            ->assertSessionHasErrors('permissions');
        $this->assertSame(Permission::BanMembers->value, $this->below->fresh()?->permissions);

        // Sending it back unchanged while adding one they have is fine.
        $this->actingAs($this->manager)
            ->patch(route('roles.update', [$this->server, $this->below]), [
                'name' => 'Below',
                'permissions' => [Permission::BanMembers->value, Permission::ManageMessages->value],
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(Permission::mask(Permission::BanMembers, Permission::ManageMessages), $this->below->fresh()?->permissions);
    }

    public function test_everyone_permissions_follow_the_same_rule(): void
    {
        $everyone = $this->server->defaultRole();

        $this->actingAs($this->manager)
            ->patch(route('roles.update', [$this->server, $everyone]), ['permissions' => [Permission::Administrator->value]])
            ->assertSessionHasErrors('permissions');
        $this->assertSame(Permission::everyoneDefaults(), $everyone->fresh()?->permissions);
    }

    public function test_the_owner_and_administrators_can_hand_out_anything_below_them(): void
    {
        $adminRole = $this->makeRole($this->server, 'Admins', Permission::mask(Permission::Administrator), 4);
        $admin = $this->joinServer($this->server);
        $this->giveRole($admin, $adminRole);

        $this->actingAs($admin)
            ->patch(route('roles.update', [$this->server, $this->above]), ['name' => 'Above', 'permissions' => [Permission::BanMembers->value]])
            ->assertSessionHasNoErrors();
        $this->assertSame(Permission::BanMembers->value, $this->above->fresh()?->permissions);

        // But not their own role, nor above it.
        $this->actingAs($admin)->get(route('roles.edit', [$this->server, $adminRole]))->assertForbidden();

        $this->actingAs($this->owner)
            ->patch(route('roles.update', [$this->server, $adminRole]), ['name' => 'Admins', 'permissions' => []])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $adminRole->fresh()?->permissions);
    }
}
