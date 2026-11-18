<?php

namespace Tests\Feature\Roles;

use App\Actions\Roles\AssignRole;
use App\Actions\Roles\CreateRole;
use App\Actions\Roles\MoveRole;
use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * ISSUE-26 edge cases: the role actions called directly, colours and names
 * that must not leak into the HTML, the role limit boundary, renames to
 * the reserved name and the @everyone update rules.
 */
class RoleEdgeCasesTest extends TestCase
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
        $this->server = app(CreateServer::class)->create($this->owner, 'Edge Server');
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $this->member->id]);
    }

    // --- Role model -------------------------------------------------------

    public function test_display_color_only_returns_a_valid_hex_colour(): void
    {
        $valid = ['#5865f2', '#ABCDEF', '#000000'];
        foreach ($valid as $color) {
            $this->assertSame($color, (new Role(['color' => $color]))->displayColor(), $color);
        }

        $invalid = [null, '', 'red', '#12345', '#1234567', '#GGGGGG', '5865f2', 'red;background:url(x)'];
        foreach ($invalid as $color) {
            $this->assertNull((new Role(['color' => $color]))->displayColor(), var_export($color, true));
        }
    }

    public function test_a_colour_with_surrounding_whitespace_is_stored_clean(): void
    {
        // The `$` in the colour regex also matches before a trailing "\n";
        // the TrimStrings middleware is what keeps that out of the database.
        $this->actingAs($this->owner)
            ->post(route('roles.store', $this->server), ['name' => 'Padded', 'color' => " #5865F2\n"])
            ->assertSessionHasNoErrors();

        $this->assertSame('#5865f2', $this->server->roles()->where('name', 'Padded')->sole()->color);
    }

    public function test_has_permission_and_permission_list_read_the_mask(): void
    {
        $role = new Role(['permissions' => Permission::mask(Permission::BanMembers, Permission::ManageRoles)]);

        $this->assertTrue($role->hasPermission(Permission::BanMembers));
        $this->assertTrue($role->hasPermission(Permission::ManageRoles));
        $this->assertFalse($role->hasPermission(Permission::KickMembers));
        $this->assertSame([Permission::BanMembers, Permission::ManageRoles], $role->permissionList());

        $this->assertSame([], (new Role)->permissionList(), 'no mask means no permissions');
        $this->assertFalse((new Role)->hasPermission(Permission::CreateInvite));
    }

    public function test_a_bad_stored_colour_never_reaches_the_style_attribute(): void
    {
        $this->makeRole('Sneaky', color: 'red;background:url(https://evil.test/x)');

        $html = (string) $this->actingAs($this->owner)
            ->get(route('roles.index', $this->server))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('evil.test', $html);
        $this->assertStringContainsString('background-color: #99aab5', $html);
    }

    public function test_role_names_are_escaped(): void
    {
        $role = $this->makeRole('<script>alert(1)</script>');

        $this->actingAs($this->owner)
            ->get(route('roles.index', $this->server))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

        $this->actingAs($this->owner)
            ->get(route('roles.edit', [$this->server, $role]))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // --- Validation ---------------------------------------------------------

    public function test_names_are_trimmed_before_validation(): void
    {
        foreach (["   \t ", ' @everyone ', "@EVERYONE\n"] as $name) {
            $this->actingAs($this->owner)
                ->post(route('roles.store', $this->server), ['name' => $name])
                ->assertSessionHasErrors('name');
        }

        // Exactly the maximum length is fine, once the padding is gone.
        $this->actingAs($this->owner)
            ->post(route('roles.store', $this->server), ['name' => '  '.str_repeat('a', Role::MAX_NAME_LENGTH).'  '])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->server->roles()->count());
    }

    public function test_a_role_cannot_be_renamed_to_everyone(): void
    {
        $role = $this->makeRole('Mods');

        $this->actingAs($this->owner)
            ->patch(route('roles.update', [$this->server, $role]), ['name' => '@Everyone'])
            ->assertSessionHasErrors('name');
        $this->actingAs($this->owner)
            ->patch(route('roles.update', [$this->server, $role]), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame('Mods', $role->fresh()?->name);
    }

    public function test_non_numeric_permission_values_are_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('roles.store', $this->server), ['name' => 'X', 'permissions' => ['ManageRoles']])
            ->assertSessionHasErrors(['permissions.0' => 'Unknown permission.']);

        $this->assertSame(1, $this->server->roles()->count());
    }

    public function test_the_same_permission_twice_is_stored_once(): void
    {
        $value = Permission::KickMembers->value;

        $this->actingAs($this->owner)
            ->post(route('roles.store', $this->server), ['name' => 'Twice', 'permissions' => [$value, (string) $value]])
            ->assertSessionHasNoErrors();

        $this->assertSame($value, $this->server->roles()->where('name', 'Twice')->sole()->permissions);
    }

    public function test_everyone_permissions_are_validated_and_can_be_cleared(): void
    {
        $everyone = $this->server->defaultRole();

        $this->actingAs($this->owner)
            ->patch(route('roles.update', [$this->server, $everyone]), ['permissions' => [3]])
            ->assertSessionHasErrors('permissions.0');
        $this->assertSame(Permission::everyoneDefaults(), $everyone->fresh()?->permissions);

        // No name is needed for @everyone, and no boxes means no permissions.
        $this->actingAs($this->owner)
            ->patch(route('roles.update', [$this->server, $everyone]), [])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('roles.edit', [$this->server, $everyone]));
        $everyone->refresh();
        $this->assertSame(0, $everyone->permissions);
    }

    public function test_a_move_needs_a_direction(): void
    {
        $role = $this->makeRole('Mods');

        $this->actingAs($this->owner)
            ->post(route('roles.move', [$this->server, $role]), [])
            ->assertSessionHasErrors('direction');
    }

    // --- Role limit and positions -------------------------------------------

    public function test_the_last_free_role_slot_can_still_be_used(): void
    {
        // @everyone plus these leaves exactly one free slot.
        Role::factory()->count(Role::MAX_PER_SERVER - 2)->create(['server_id' => $this->server->id]);

        $this->actingAs($this->owner)
            ->post(route('roles.store', $this->server), ['name' => 'Last one'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Role::MAX_PER_SERVER, $this->server->roles()->count());
    }

    public function test_new_roles_go_above_the_highest_remaining_role(): void
    {
        $this->makeRole('Middle', position: 2);
        $top = $this->makeRole('Top', position: 7);
        $top->delete();

        $created = app(CreateRole::class)->create($this->server, 'Fresh', null, 0);

        $this->assertSame(3, $created->position);
        $this->assertSame(['Fresh', 'Middle', Role::EVERYONE], $this->server->rolesInOrder()->pluck('name')->all());
    }

    public function test_a_new_role_is_never_placed_at_or_below_everyone(): void
    {
        // Older data: a normal role stuck at position 0, next to @everyone.
        $this->makeRole('Stuck', position: 0);

        $created = app(CreateRole::class)->create($this->server, 'Fresh', '#abcdef', Permission::all());

        $this->assertSame(1, $created->position);
        $this->assertSame('#abcdef', $created->color);
        $this->assertSame(Permission::all(), $created->permissions);
    }

    public function test_the_role_limit_counts_only_this_servers_roles(): void
    {
        $other = Server::factory()->create();
        Role::factory()->count(Role::MAX_PER_SERVER)->create(['server_id' => $other->id]);

        $created = app(CreateRole::class)->create($this->server, 'Fine', null, 0);

        $this->assertTrue($created->server->is($this->server));
    }

    // --- MoveRole -----------------------------------------------------------

    public function test_move_role_rejects_an_unknown_direction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(MoveRole::class)->move($this->makeRole('Mods'), 'sideways');
    }

    public function test_move_role_reports_whether_anything_moved(): void
    {
        $high = $this->makeRole('High', position: 2);
        $low = $this->makeRole('Low', position: 1);
        $moves = app(MoveRole::class);

        $this->assertFalse($moves->move($this->server->defaultRole(), MoveRole::UP));
        $this->assertFalse($moves->move($high, MoveRole::UP));
        $this->assertFalse($moves->move($low, MoveRole::DOWN));

        $this->assertTrue($moves->move($low, MoveRole::UP));
        $this->assertSame(2, $low->position, 'the passed model is refreshed');
        $this->assertSame(1, $high->fresh()?->position);
        $this->assertSame(0, $this->server->defaultRole()->position);
    }

    public function test_moving_leaves_other_servers_alone(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Other');
        $foreign = Role::factory()->create(['server_id' => $other->id, 'position' => 9]);
        $a = $this->makeRole('A', position: 5);
        $b = $this->makeRole('B', position: 4);

        app(MoveRole::class)->move($b, MoveRole::UP);

        $this->assertSame(9, $foreign->fresh()?->position);
        $this->assertSame([2, 1], [$b->fresh()?->position, $a->fresh()?->position]);
    }

    // --- AssignRole ---------------------------------------------------------

    public function test_assign_role_cannot_give_or_take_everyone(): void
    {
        $everyone = $this->server->defaultRole();
        $roles = app(AssignRole::class);

        foreach (['assign', 'remove'] as $method) {
            try {
                $roles->{$method}($everyone, $this->member);
                $this->fail("{$method} should refuse @everyone");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('user_id', $e->errors());
            }
        }

        $this->assertSame(0, DB::table('role_user')->count());
    }

    public function test_assign_role_reports_whether_anything_changed(): void
    {
        $role = $this->makeRole('Helpers');
        $roles = app(AssignRole::class);

        $this->assertFalse($roles->remove($role, $this->member), 'nothing to take away yet');
        $this->assertTrue($roles->assign($role, $this->member));
        $this->assertFalse($roles->assign($role, $this->member));
        $this->assertTrue($roles->remove($role, $this->member));
        $this->assertFalse($roles->remove($role, $this->member));
    }

    public function test_the_owner_can_give_a_role_to_themselves(): void
    {
        $role = $this->makeRole('Boss');

        $this->actingAs($this->owner)
            ->post(route('roles.members.store', [$this->server, $role]), ['user_id' => $this->owner->id])
            ->assertSessionHasNoErrors();

        $this->assertTrue($role->users()->whereKey($this->owner->id)->exists());
    }

    public function test_a_member_of_another_server_cannot_be_given_the_role(): void
    {
        $other = app(CreateServer::class)->create(User::factory()->create(), 'Other');
        $outsider = User::factory()->create();
        ServerMember::factory()->create(['server_id' => $other->id, 'user_id' => $outsider->id]);
        $role = $this->makeRole('Helpers');

        $this->actingAs($this->owner)
            ->post(route('roles.members.store', [$this->server, $role]), ['user_id' => $outsider->id])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(0, DB::table('role_user')->count());
    }

    public function test_the_remove_route_on_everyone_is_a_404(): void
    {
        // Nobody holds @everyone through role_user, so the scoped {user} never matches.
        $this->actingAs($this->owner)
            ->delete(route('roles.members.destroy', [$this->server, $this->server->defaultRole(), $this->member]))
            ->assertNotFound();
    }

    public function test_only_the_owner_can_take_a_role_away(): void
    {
        $role = $this->makeRole('Helpers');
        $role->users()->attach($this->member);

        $this->actingAs($this->member)
            ->delete(route('roles.members.destroy', [$this->server, $role, $this->member]))
            ->assertForbidden();

        $this->assertTrue($role->users()->whereKey($this->member->id)->exists());
    }

    public function test_the_edit_page_splits_holders_from_candidates(): void
    {
        $role = $this->makeRole('Helpers');
        $role->users()->attach($this->member);

        $html = (string) $this->actingAs($this->owner)
            ->get(route('roles.edit', [$this->server, $role]))
            ->assertOk()
            ->assertSee('data-role-member="'.$this->member->username.'"', false)
            ->getContent();

        $this->assertStringNotContainsString('<option value="'.$this->member->id.'"', $html, 'a holder is not offered again');
        $this->assertStringContainsString('<option value="'.$this->owner->id.'"', $html);
    }

    // --- Deletion -----------------------------------------------------------

    public function test_deleting_a_role_keeps_the_order_of_the_rest(): void
    {
        $a = $this->makeRole('A', position: 3);
        $b = $this->makeRole('B', position: 2);
        $c = $this->makeRole('C', position: 1);

        $this->actingAs($this->owner)->delete(route('roles.destroy', [$this->server, $b]))->assertRedirect();

        $this->assertSame(['A', 'C', Role::EVERYONE], $this->server->rolesInOrder()->pluck('name')->all());
        $this->assertSame([3, 1], [$a->fresh()?->position, $c->fresh()?->position]);
    }

    public function test_deleting_the_user_drops_their_role_rows(): void
    {
        $role = $this->makeRole('Helpers');
        $role->users()->attach($this->member);

        $this->member->delete();

        $this->assertSame(0, DB::table('role_user')->count());
        $this->assertNotNull($role->fresh());
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
}
