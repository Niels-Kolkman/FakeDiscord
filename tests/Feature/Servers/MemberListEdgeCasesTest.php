<?php

namespace Tests\Feature\Servers;

use App\Actions\Servers\CreateServer;
use App\Actions\Servers\LeaveServer;
use App\Actions\Servers\TransferServerOwnership;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\MemberGroup;
use App\Support\MemberList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ISSUE-13 edge cases for the member sidebar: group keys, statuses,
 * stale role rows, highest-role data and the rendered markup.
 */
class MemberListEdgeCasesTest extends TestCase
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

    private function list(): MemberList
    {
        return MemberList::for($this->server->fresh());
    }

    /**
     * @return list<string>
     */
    private function keys(MemberList $list): array
    {
        return array_map(fn (MemberGroup $group) => $group->key, $list->groups);
    }

    // --- User::isOnline ----------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function statuses(): array
    {
        return [
            'online' => ['online', true],
            'idle' => ['idle', true],
            'dnd' => ['dnd', true],
            'offline' => ['offline', false],
            'unknown' => ['invisible', false],
            'wrong case' => ['Online', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('statuses')]
    public function test_which_statuses_count_as_online(string $status, bool $online): void
    {
        $this->assertSame($online, User::factory()->make(['status' => $status])->isOnline());
    }

    // --- Group keys and members ----------------------------------------------

    public function test_group_keys_identify_roles_online_and_offline(): void
    {
        $mod = $this->role('Moderator', 1);
        $mod->users()->attach($this->member('Alice', 'online'));
        $this->member('Dave', 'offline');

        $this->assertSame(["role-{$mod->id}", MemberGroup::ONLINE, MemberGroup::OFFLINE], $this->keys($this->list()));
    }

    public function test_a_member_with_many_roles_is_listed_exactly_once(): void
    {
        $alice = $this->member('Alice', 'online');
        foreach ([3, 2, 1] as $position) {
            $this->role("R{$position}", $position)->users()->attach($alice);
        }

        $ids = collect($this->list()->groups)
            ->flatMap(fn (MemberGroup $group) => $group->members->pluck('user_id'))
            ->all();

        $this->assertSame(1, array_count_values($ids)[$alice->id]);
        $this->assertCount(2, $ids);
    }

    public function test_highest_role_is_set_for_offline_members_and_null_without_roles(): void
    {
        $high = $this->role('High', 9);
        $low = $this->role('Low', 1);
        $dave = $this->member('Dave', 'offline');
        $low->users()->attach($dave);
        $high->users()->attach($dave);

        $groups = collect($this->list()->groups);
        $daveRow = $groups->firstWhere('key', MemberGroup::OFFLINE)->members->firstWhere('user_id', $dave->id);
        $ownerRow = $groups->firstWhere('key', MemberGroup::ONLINE)->members->firstWhere('user_id', $this->owner->id);

        $this->assertTrue($daveRow->highestRole()->is($high));
        $this->assertNull($ownerRow->highestRole());
    }

    public function test_role_rows_of_users_who_are_not_members_are_ignored(): void
    {
        // A leftover role_user row, e.g. from before leaving cleaned up roles.
        $this->role('Moderator', 1)->users()->attach(User::factory()->create(['status' => 'online']));

        $list = $this->list();

        $this->assertSame(1, $list->total);
        $this->assertSame([MemberGroup::ONLINE], $this->keys($list));
    }

    public function test_members_of_other_servers_are_not_listed(): void
    {
        $other = app(CreateServer::class)->create(User::factory()->create(), 'Other');
        ServerMember::factory()->create(['server_id' => $other->id, 'user_id' => User::factory()->create()->id]);

        $this->assertSame(1, $this->list()->total);
    }

    public function test_sorting_lowercases_non_ascii_names(): void
    {
        $this->member('Émile', 'online');
        $this->member('éa', 'online');
        $this->member('Ab', 'online');

        $names = collect($this->list()->groups)
            ->firstWhere('key', MemberGroup::ONLINE)
            ->members->map->displayName()->all();

        // mb_strtolower keeps "É" next to "é" instead of before "a".
        $this->assertSame(['Ab', 'Owner', 'éa', 'Émile'], $names);
    }

    public function test_a_server_where_everyone_is_offline_has_only_the_offline_group(): void
    {
        $this->owner->update(['status' => 'offline']);
        $this->role('Unused', 1)->users()->attach($this->owner);

        $list = $this->list();

        $this->assertSame([MemberGroup::OFFLINE], $this->keys($list));
        $this->assertSame(0, $list->online);
        $this->assertSame(1, $list->total);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_roles(): void
    {
        foreach (range(1, 8) as $i) {
            $this->role("Role {$i}", $i)->users()->attach($this->member("User {$i}", 'online'));
        }

        $this->server->getConnection()->enableQueryLog();
        MemberList::for($this->server);

        $this->assertCount(4, $this->server->getConnection()->getQueryLog());
    }

    // --- The list follows membership changes ---------------------------------

    public function test_a_member_who_left_disappears_from_the_sidebar(): void
    {
        $alice = $this->member('Alice', 'online');
        app(LeaveServer::class)->leave($this->server, $alice);

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}")
            ->assertOk()
            ->assertDontSee('data-member="'.$alice->username.'"', false);
    }

    public function test_the_owner_badge_moves_with_ownership(): void
    {
        $alice = $this->member('Alice', 'online');
        app(TransferServerOwnership::class)->transfer($this->server, $alice);

        $html = (string) $this->actingAs($this->owner)->get("/servers/{$this->server->id}")->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-owner-badge'));
        $this->assertMatchesRegularExpression(
            '/data-member="'.preg_quote($alice->username, '/').'"(?:(?!<\/li>).)*data-owner-badge/s',
            $html,
        );
    }

    // --- Rendering -----------------------------------------------------------

    public function test_the_heading_states_online_and_total_counts(): void
    {
        $this->member('A', 'idle');
        $this->member('B', 'offline');

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}")
            ->assertSee('Members (2 online of 3)');
    }

    public function test_status_labels_are_given_to_screen_readers(): void
    {
        $this->member('Idler', 'idle');
        $this->member('Busy', 'dnd');
        $this->member('Weird', 'invisible');

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}")
            ->assertSee(', Idle')
            ->assertSee(', Do Not Disturb')
            ->assertSee(', Online')
            ->assertSee(', Offline');
    }

    public function test_an_online_member_whose_role_has_no_color_gets_no_inline_color(): void
    {
        $this->role('Plain', 1, null)->users()->attach($this->member('Alice', 'online'));

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}")
            ->assertOk()
            ->assertSee('Plain — 1')
            ->assertDontSee('style="color:', false);
    }

    public function test_role_names_are_escaped_in_group_headings(): void
    {
        $this->role('<i>Mods</i>', 1)->users()->attach($this->member('Alice', 'online'));

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}")
            ->assertDontSee('<i>Mods</i>', false)
            ->assertSee('&lt;i&gt;Mods&lt;/i&gt;', false);
    }

    public function test_the_nickname_is_shown_instead_of_the_name(): void
    {
        $this->member('Real Name', 'online', nickname: 'Nick');

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}")
            // The visible label; the avatar's aria-label still uses the account name.
            ->assertSee('>Nick</span>', false)
            ->assertDontSee('>Real Name</span>', false);
    }
}
