<?php

namespace Tests\Feature\Servers;

use App\Actions\Servers\CreateServer;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\MemberGroup;
use App\Support\MemberList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The right-hand member sidebar, grouped by role and online status.
 */
class MemberListTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner', 'status' => 'online']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Test Server');
    }

    private function member(string $name, string $status, ?string $nickname = null): User
    {
        $user = User::factory()->create(['name' => $name, 'status' => $status]);
        ServerMember::factory()->create([
            'server_id' => $this->server->id,
            'user_id' => $user->id,
            'nickname' => $nickname,
        ]);

        return $user;
    }

    private function role(string $name, int $position, ?string $color = '#e67e22'): Role
    {
        return Role::factory()->create([
            'server_id' => $this->server->id,
            'name' => $name,
            'position' => $position,
            'color' => $color,
        ]);
    }

    /**
     * @return array<string, list<string>> group label => display names
     */
    private function memberGroups(): array
    {
        $list = MemberList::for($this->server->fresh());

        return collect($list->groups)
            ->mapWithKeys(fn (MemberGroup $group) => [$group->label => $group->members->map->displayName()->all()])
            ->all();
    }

    // --- Grouping --------------------------------------------------------

    public function test_a_new_server_lists_only_the_owner_under_online(): void
    {
        $this->assertSame(['Online' => ['Owner']], $this->memberGroups());
    }

    public function test_online_members_are_grouped_under_their_highest_role_then_online_then_offline(): void
    {
        $admin = $this->role('Admin', 10);
        $mod = $this->role('Moderator', 5);

        $alice = $this->member('Alice', 'online');
        $bob = $this->member('Bob', 'idle');
        $carol = $this->member('Carol', 'dnd');
        $dave = $this->member('Dave', 'offline');

        // Alice has both roles: she is listed once, under the higher one.
        $admin->users()->attach($alice);
        $mod->users()->attach([$alice->id, $bob->id]);
        // Offline members go under Offline whatever their roles.
        $admin->users()->attach($dave);

        $this->assertSame([
            'Admin' => ['Alice'],
            'Moderator' => ['Bob'],
            'Online' => ['Carol', 'Owner'],
            'Offline' => ['Dave'],
        ], $this->memberGroups());
    }

    public function test_role_groups_follow_position_with_id_as_tie_breaker(): void
    {
        $low = $this->role('Low', 1);
        $first = $this->role('Tied first', 7);
        $second = $this->role('Tied second', 7);

        $low->users()->attach($this->member('A', 'online'));
        $second->users()->attach($this->member('B', 'online'));
        $first->users()->attach($this->member('C', 'online'));
        // Holds both tied roles: the lower id wins.
        $both = $this->member('D', 'online');
        $first->users()->attach($both);
        $second->users()->attach($both);

        $this->assertSame(
            ['Tied first', 'Tied second', 'Low', 'Online'],
            array_keys($this->memberGroups()),
        );
        $this->assertSame(['C', 'D'], $this->memberGroups()['Tied first']);
    }

    public function test_empty_groups_are_left_out(): void
    {
        $this->role('Nobody', 3);
        $this->member('Zed', 'offline');

        $this->assertSame(['Online' => ['Owner'], 'Offline' => ['Zed']], $this->memberGroups());
    }

    public function test_the_everyone_role_is_not_a_group(): void
    {
        $everyone = $this->server->roles()->where('is_default', true)->firstOrFail();
        $everyone->users()->attach($this->member('Eve', 'online'));

        $this->assertSame(['Online' => ['Eve', 'Owner']], $this->memberGroups());
    }

    public function test_roles_of_other_servers_do_not_group_members(): void
    {
        $user = $this->member('Alice', 'online');
        $elsewhere = Role::factory()->create(['name' => 'Elsewhere', 'position' => 99]);
        $elsewhere->users()->attach($user);

        $this->assertSame(['Online' => ['Alice', 'Owner']], $this->memberGroups());
    }

    public function test_members_are_sorted_case_insensitively_by_nickname_or_name(): void
    {
        $this->member('zoe', 'online');
        $this->member('Bram', 'online', nickname: 'aardvark');
        $this->member('Charlie', 'online');

        $this->assertSame(['aardvark', 'Charlie', 'Owner', 'zoe'], $this->memberGroups()['Online']);
    }

    public function test_unknown_statuses_count_as_offline(): void
    {
        $this->member('Weird', 'invisible');

        $this->assertSame(['Weird'], $this->memberGroups()['Offline']);
    }

    public function test_counts(): void
    {
        $this->member('A', 'online');
        $this->member('B', 'offline');
        $this->member('C', 'offline');

        $list = MemberList::for($this->server);

        $this->assertSame(4, $list->total);
        $this->assertSame(2, $list->online);
    }

    public function test_member_list_uses_a_fixed_number_of_queries(): void
    {
        $role = $this->role('Mod', 1);
        foreach (range(1, 10) as $i) {
            $role->users()->attach($this->member("User {$i}", 'online'));
        }

        $this->server->getConnection()->enableQueryLog();
        MemberList::for($this->server);

        // Memberships, their users, roles and role_user.
        $this->assertCount(4, $this->server->getConnection()->getQueryLog());
    }

    // --- Rendering -------------------------------------------------------

    public function test_the_server_page_shows_the_member_sidebar(): void
    {
        $mod = $this->role('Moderator', 1, '#e67e22');
        $mod->users()->attach($this->member('Alice', 'online'));
        $this->member('Dave', 'offline');

        $response = $this->actingAs($this->owner)->get("/servers/{$this->server->id}");

        $response->assertOk()
            ->assertSee('aria-label="Members of Test Server"', false)
            ->assertSeeInOrder(['Moderator — 1', 'Alice', 'Online — 1', 'Owner', 'Offline — 1', 'Dave'])
            ->assertSee('style="color: #e67e22"', false)
            ->assertSee('data-owner-badge', false);
    }

    public function test_channel_pages_show_the_member_sidebar_too(): void
    {
        $channel = $this->server->channels()->firstOrFail();
        $this->member('Alice', 'online');

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}/channels/{$channel->id}")
            ->assertOk()
            ->assertSee('data-member-list', false)
            ->assertSee('Alice');
    }

    public function test_offline_members_are_not_shown_in_their_role_color(): void
    {
        $mod = $this->role('Moderator', 1, '#123456');
        $mod->users()->attach($this->member('Dave', 'offline'));

        // The role's section carries the colour for presence.js,
        // but Dave's name is not painted with it.
        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}")
            ->assertDontSee('style="color: #123456"', false)
            ->assertSee('data-role-color="#123456"', false);
    }

    public function test_a_role_color_that_is_not_a_hex_value_is_not_put_in_the_style_attribute(): void
    {
        // Role colours are not validated until roles can be edited.
        $role = $this->role('Evil', 1, 'red;x');
        $role->users()->attach($this->member('Alice', 'online'));

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}")
            ->assertOk()
            ->assertDontSee('color: red;x', false);
    }

    public function test_nicknames_and_names_are_escaped(): void
    {
        $this->member('<b>Bold</b>', 'online');

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}")
            ->assertDontSee('<b>Bold</b>', false)
            ->assertSee('&lt;b&gt;Bold&lt;/b&gt;', false);
    }

    public function test_non_members_cannot_see_the_member_list(): void
    {
        $this->actingAs(User::factory()->create())
            ->get("/servers/{$this->server->id}")
            ->assertForbidden();
    }
}
