<?php

namespace Tests\Feature\Roles;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ISSUE-26: the owner creates, edits, reorders and deletes roles, and gives
 * them to members. @everyone only has its permissions changed.
 */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create(['name' => 'Mia Member']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Role Test');
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $this->member->id]);
    }

    public function test_a_new_server_gets_everyone_with_the_default_permissions(): void
    {
        $everyone = $this->server->roles()->sole();

        $this->assertTrue($everyone->is_default);
        $this->assertSame(Role::EVERYONE, $everyone->name);
        $this->assertSame(0, $everyone->position);
        $this->assertSame(Permission::everyoneDefaults(), $everyone->permissions);
        $this->assertTrue($everyone->hasPermission(Permission::SendMessages));
        $this->assertTrue($everyone->hasPermission(Permission::CreateInvite));
        $this->assertFalse($everyone->hasPermission(Permission::ManageMessages));
        $this->assertTrue($this->server->defaultRole()->is($everyone));
    }

    public function test_default_role_creates_everyone_for_a_server_without_one(): void
    {
        $bare = Server::factory()->create();
        $this->assertSame(0, $bare->roles()->count());

        $everyone = $bare->defaultRole();

        $this->assertTrue($everyone->is_default);
        $this->assertSame(Permission::everyoneDefaults(), $everyone->permissions);
        $this->assertTrue($bare->defaultRole()->is($everyone));
        $this->assertSame(1, $bare->roles()->count());
    }

    public function test_only_the_owner_can_reach_the_role_pages(): void
    {
        $role = $this->makeRole('Mods');
        $stranger = User::factory()->create();

        $this->actingAs($this->owner)->get(route('roles.index', $this->server))->assertOk();
        $this->actingAs($this->owner)->get(route('roles.create', $this->server))->assertOk();
        $this->actingAs($this->owner)->get(route('roles.edit', [$this->server, $role]))->assertOk();

        foreach ([$this->member, $stranger] as $user) {
            $this->actingAs($user)->get(route('roles.index', $this->server))->assertForbidden();
            $this->actingAs($user)->get(route('roles.create', $this->server))->assertForbidden();
            $this->actingAs($user)->get(route('roles.edit', [$this->server, $role]))->assertForbidden();
            $this->actingAs($user)->post(route('roles.store', $this->server), ['name' => 'Hax'])->assertForbidden();
            $this->actingAs($user)->patch(route('roles.update', [$this->server, $role]), ['name' => 'Hax'])->assertForbidden();
            $this->actingAs($user)->delete(route('roles.destroy', [$this->server, $role]))->assertForbidden();
            $this->actingAs($user)->post(route('roles.move', [$this->server, $role]), ['direction' => 'up'])->assertForbidden();
            $this->actingAs($user)->post(route('roles.members.store', [$this->server, $role]), ['user_id' => $user->id])->assertForbidden();
        }

        $this->assertSame('Mods', $role->fresh()?->name);
        $this->assertSame(0, DB::table('role_user')->count());
        $this->assertSame(2, $this->server->roles()->count());
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('roles.index', $this->server))->assertRedirect(route('login'));
        $this->post(route('roles.store', $this->server), ['name' => 'X'])->assertRedirect(route('login'));
    }

    public function test_a_role_of_another_server_is_a_404(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Other');
        $foreign = Role::factory()->create(['server_id' => $other->id]);

        $this->actingAs($this->owner)->get(route('roles.edit', [$this->server, $foreign]))->assertNotFound();
        $this->actingAs($this->owner)->patch(route('roles.update', [$this->server, $foreign]), ['name' => 'X'])->assertNotFound();
        $this->actingAs($this->owner)->delete(route('roles.destroy', [$this->server, $foreign]))->assertNotFound();
        $this->actingAs($this->owner)
            ->post(route('roles.members.store', [$this->server, $foreign]), ['user_id' => $this->member->id])
            ->assertNotFound();

        $this->assertNotNull($foreign->fresh());
        $this->assertSame(0, DB::table('role_user')->count());
    }

    public function test_the_index_lists_roles_highest_first_with_everyone_last(): void
    {
        $low = $this->makeRole('Low', position: 1);
        $high = $this->makeRole('High', position: 5);
        $low->users()->attach($this->member);

        $this->actingAs($this->owner)
            ->get(route('roles.index', $this->server))
            ->assertOk()
            ->assertSeeInOrder(['High', 'Low', Role::EVERYONE])
            ->assertSee('1 member')
            ->assertSee('Every member');

        $this->assertSame(
            [$high->id, $low->id, $this->server->defaultRole()->id],
            $this->server->rolesInOrder()->pluck('id')->all(),
        );
    }

    public function test_the_owner_creates_a_role_at_the_top(): void
    {
        $this->makeRole('Existing', position: 3);

        $this->actingAs($this->owner)
            ->post(route('roles.store', $this->server), [
                'name' => '  Super   Mods ',
                'color' => '#E67E22',
                'permissions' => [(string) Permission::ManageMessages->value, Permission::KickMembers->value],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'The role "Super Mods" has been created.');

        $role = $this->server->roles()->where('name', 'Super Mods')->sole();
        $this->assertSame('#e67e22', $role->color);
        $this->assertSame(4, $role->position);
        $this->assertFalse($role->is_default);
        $this->assertSame(Permission::mask(Permission::ManageMessages, Permission::KickMembers), $role->permissions);
        $this->assertSame([Permission::KickMembers, Permission::ManageMessages], $role->permissionList());
    }

    public function test_a_role_without_permissions_or_colour(): void
    {
        $this->actingAs($this->owner)
            ->post(route('roles.store', $this->server), ['name' => 'Plain', 'color' => '#ffffff', 'no_color' => '1'])
            ->assertRedirect();

        $role = $this->server->roles()->where('name', 'Plain')->sole();
        $this->assertNull($role->color);
        $this->assertSame(0, $role->permissions);
        $this->assertSame(1, $role->position, 'the first role sits right above @everyone');
    }

    public function test_role_input_is_validated(): void
    {
        $cases = [
            ['name', ['name' => '']],
            ['name', ['name' => str_repeat('a', Role::MAX_NAME_LENGTH + 1)]],
            ['name', ['name' => '@EveryOne']],
            ['color', ['name' => 'X', 'color' => 'red']],
            ['color', ['name' => 'X', 'color' => '#12345']],
            ['permissions', ['name' => 'X', 'permissions' => 'all']],
            ['permissions.0', ['name' => 'X', 'permissions' => [3]]],
            ['permissions.0', ['name' => 'X', 'permissions' => [1 << 62]]],
        ];

        foreach ($cases as [$field, $input]) {
            $this->actingAs($this->owner)
                ->from(route('roles.create', $this->server))
                ->post(route('roles.store', $this->server), $input)
                ->assertRedirect(route('roles.create', $this->server))
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(1, $this->server->roles()->count());
    }

    public function test_a_server_has_at_most_the_maximum_number_of_roles(): void
    {
        Role::factory()->count(Role::MAX_PER_SERVER - 1)->create(['server_id' => $this->server->id]);

        $this->actingAs($this->owner)
            ->post(route('roles.store', $this->server), ['name' => 'One too many'])
            ->assertSessionHasErrors(['name' => 'A server can have at most '.Role::MAX_PER_SERVER.' roles.']);

        $this->assertSame(Role::MAX_PER_SERVER, $this->server->roles()->count());
    }

    public function test_the_owner_edits_a_role(): void
    {
        $role = $this->makeRole('Old', color: '#111111', permissions: Permission::all());

        $this->actingAs($this->owner)
            ->patch(route('roles.update', [$this->server, $role]), [
                'name' => 'New',
                'color' => '#22AA33',
                'permissions' => [Permission::ManageChannels->value],
            ])
            ->assertRedirect(route('roles.edit', [$this->server, $role]))
            ->assertSessionHas('status', 'Role saved.');

        $role->refresh();
        $this->assertSame('New', $role->name);
        $this->assertSame('#22aa33', $role->color);
        $this->assertSame(Permission::ManageChannels->value, $role->permissions);

        // Leaving every box unticked clears the permissions.
        $this->actingAs($this->owner)->patch(route('roles.update', [$this->server, $role]), ['name' => 'New', 'no_color' => '1']);
        $role->refresh();
        $this->assertSame(0, $role->permissions);
        $this->assertNull($role->color);
    }

    public function test_everyone_only_has_its_permissions_changed(): void
    {
        $everyone = $this->server->defaultRole();

        $this->actingAs($this->owner)
            ->get(route('roles.edit', [$this->server, $everyone]))
            ->assertOk()
            ->assertDontSee('name="name"', false)
            ->assertDontSee('Delete role')
            ->assertSee('Every member of the server has these permissions.');

        $this->actingAs($this->owner)
            ->patch(route('roles.update', [$this->server, $everyone]), [
                'name' => 'Renamed',
                'color' => '#ff0000',
                'permissions' => [Permission::SendMessages->value],
            ])
            ->assertSessionHasNoErrors();

        $everyone->refresh();
        $this->assertSame(Role::EVERYONE, $everyone->name);
        $this->assertNull($everyone->color);
        $this->assertSame(0, $everyone->position);
        $this->assertSame(Permission::SendMessages->value, $everyone->permissions);
    }

    public function test_everyone_cannot_be_deleted_moved_or_assigned(): void
    {
        $everyone = $this->server->defaultRole();

        $this->actingAs($this->owner)->delete(route('roles.destroy', [$this->server, $everyone]))->assertSessionHasErrors('role');
        $this->actingAs($this->owner)->post(route('roles.move', [$this->server, $everyone]), ['direction' => 'up'])->assertSessionHasErrors('direction');
        $this->actingAs($this->owner)
            ->post(route('roles.members.store', [$this->server, $everyone]), ['user_id' => $this->member->id])
            ->assertSessionHasErrors(['user_id' => 'Every member has the @everyone role.']);

        $this->assertSame(0, $everyone->fresh()?->position);
        $this->assertSame(0, DB::table('role_user')->count());
    }

    public function test_deleting_a_role_takes_it_from_its_members(): void
    {
        $role = $this->makeRole('Doomed');
        $keep = $this->makeRole('Kept');
        $role->users()->attach($this->member);
        $keep->users()->attach($this->member);

        $this->actingAs($this->owner)
            ->delete(route('roles.destroy', [$this->server, $role]))
            ->assertRedirect(route('roles.index', $this->server))
            ->assertSessionHas('status', 'The role "Doomed" has been deleted.');

        $this->assertNull($role->fresh());
        $this->assertSame([$keep->id], $this->member->roles()->pluck('roles.id')->all());
    }

    public function test_roles_move_up_and_down_one_place(): void
    {
        $a = $this->makeRole('A', position: 3);
        $b = $this->makeRole('B', position: 2);
        $c = $this->makeRole('C', position: 2); // a tie, broken by id

        $this->assertSame(['A', 'B', 'C', Role::EVERYONE], $this->orderedNames());

        $this->actingAs($this->owner)
            ->post(route('roles.move', [$this->server, $c]), ['direction' => 'up'])
            ->assertRedirect(route('roles.index', $this->server));
        $this->assertSame(['A', 'C', 'B', Role::EVERYONE], $this->orderedNames());
        // Renumbered 1..n, so the tie is gone.
        $this->assertSame([3, 2, 1, 0], $this->server->rolesInOrder()->pluck('position')->all());

        $this->actingAs($this->owner)->post(route('roles.move', [$this->server, $a]), ['direction' => 'down']);
        $this->assertSame(['C', 'A', 'B', Role::EVERYONE], $this->orderedNames());

        // Already at the ends: nothing changes.
        $this->actingAs($this->owner)->post(route('roles.move', [$this->server, $c]), ['direction' => 'up'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('roles.move', [$this->server, $b]), ['direction' => 'down'])->assertSessionHasNoErrors();
        $this->assertSame(['C', 'A', 'B', Role::EVERYONE], $this->orderedNames());

        $this->actingAs($this->owner)->post(route('roles.move', [$this->server, $b]), ['direction' => 'sideways'])->assertSessionHasErrors('direction');
    }

    public function test_the_owner_gives_a_role_to_a_member_and_takes_it_away(): void
    {
        $role = $this->makeRole('Helpers');

        $this->actingAs($this->owner)
            ->post(route('roles.members.store', [$this->server, $role]), ['user_id' => $this->member->id])
            ->assertRedirect(route('roles.edit', [$this->server, $role]))
            ->assertSessionHas('status', 'Mia Member now has the role "Helpers".');
        $this->assertTrue($role->users()->whereKey($this->member->id)->exists());

        $this->actingAs($this->owner)
            ->post(route('roles.members.store', [$this->server, $role]), ['user_id' => $this->member->id])
            ->assertSessionHas('status', 'Mia Member already has the role "Helpers".');
        $this->assertSame(1, DB::table('role_user')->count());

        $this->actingAs($this->owner)
            ->get(route('roles.edit', [$this->server, $role]))
            ->assertSee('data-role-member="'.$this->member->username.'"', false);

        $this->actingAs($this->owner)
            ->delete(route('roles.members.destroy', [$this->server, $role, $this->member]))
            ->assertRedirect(route('roles.edit', [$this->server, $role]))
            ->assertSessionHas('status', 'Mia Member no longer has the role "Helpers".');
        $this->assertSame(0, DB::table('role_user')->count());

        // {user} must hold the role.
        $this->actingAs($this->owner)
            ->delete(route('roles.members.destroy', [$this->server, $role, $this->member]))
            ->assertNotFound();
    }

    public function test_only_members_can_be_given_a_role(): void
    {
        $role = $this->makeRole('Helpers');
        $stranger = User::factory()->create();

        $this->actingAs($this->owner)
            ->post(route('roles.members.store', [$this->server, $role]), ['user_id' => $stranger->id])
            ->assertSessionHasErrors(['user_id' => 'Only members of this server can be given a role.']);
        $this->actingAs($this->owner)
            ->post(route('roles.members.store', [$this->server, $role]), ['user_id' => 999999])
            ->assertSessionHasErrors('user_id');
        $this->actingAs($this->owner)
            ->post(route('roles.members.store', [$this->server, $role]), [])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(0, DB::table('role_user')->count());
    }

    public function test_an_assigned_role_groups_the_member_in_the_member_list(): void
    {
        $role = $this->makeRole('Crew', color: '#e91e63');
        $this->member->forceFill(['status' => 'online'])->save();

        $this->actingAs($this->owner)->post(route('roles.members.store', [$this->server, $role]), ['user_id' => $this->member->id]);

        $group = collect($this->server->memberList()->groups)->firstWhere('key', "role-{$role->id}");
        $this->assertNotNull($group, 'online members are listed under their new role');
        $this->assertSame('Crew', $group->label);
    }

    public function test_the_role_links_show_for_the_owner_only(): void
    {
        $url = route('roles.index', $this->server);

        $this->actingAs($this->owner)->get(route('servers.show', $this->server))->assertSee($url, false);
        $this->actingAs($this->owner)->get(route('servers.edit', $this->server))->assertSee('Manage roles');
        $this->actingAs($this->member)->get(route('servers.show', $this->server))->assertDontSee($url, false);
    }

    public function test_the_forms_show_every_permission_and_keep_the_current_ones_ticked(): void
    {
        $role = $this->makeRole('Ticked', permissions: Permission::BanMembers->value);

        $response = $this->actingAs($this->owner)->get(route('roles.edit', [$this->server, $role]))->assertOk();

        foreach (Permission::cases() as $permission) {
            $response->assertSee($permission->label());
        }
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/data-permission="BanMembers"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-permission="KickMembers"\s+checked/', $html);

        $this->actingAs($this->owner)->get(route('roles.create', $this->server))->assertOk()->assertSee('Kick Members');
    }

    private function makeRole(string $name, int $position = 1, ?string $color = null, int $permissions = 0): Role
    {
        return Role::factory()->create([
            'server_id' => $this->server->id,
            'name' => $name,
            'color' => $color,
            'position' => $position,
            'permissions' => $permissions,
        ]);
    }

    /**
     * @return list<string>
     */
    private function orderedNames(): array
    {
        /** @var list<string> */
        return $this->server->rolesInOrder()->pluck('name')->all();
    }
}
