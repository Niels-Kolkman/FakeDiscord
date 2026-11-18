<?php

namespace Tests\Feature\Presence;

use App\Actions\Servers\CreateServer;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\MemberGroup;
use App\Support\MemberList;
use App\Support\ServerPresence;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Mockery;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use Tests\TestCase;

/**
 * ISSUE-31: who is online comes from the server's Reverb presence channel
 * (App\Support\ServerPresence), and the member list shows connected
 * members with the status they picked.
 */
class ServerPresenceTest extends TestCase
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

    private function member(string $name, string $status = 'online'): User
    {
        $user = User::factory()->create(['name' => $name, 'status' => $status]);
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $user->id, 'nickname' => null]);

        return $user;
    }

    /**
     * A fake Reverb that reports these users in the server's presence channel.
     *
     * @param  list<mixed>  $users  the "users" array of GET /channels/{channel}/users
     */
    private function reverbWith(array $users): void
    {
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('getPresenceUsers')
            ->with("presence-server.{$this->server->id}")
            ->andReturn((object) ['users' => $users]);
        $this->usePusher($pusher);
    }

    private function reverbDown(): void
    {
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('getPresenceUsers')->andThrow(new ApiErrorException('Reverb is not running'));
        $this->usePusher($pusher);
    }

    private function usePusher(Pusher $pusher): void
    {
        Broadcast::extend('fake-reverb', fn () => new PusherBroadcaster($pusher));
        config(['broadcasting.connections.fake-reverb' => ['driver' => 'fake-reverb'], 'broadcasting.default' => 'fake-reverb']);
    }

    /**
     * @param  list<int>|null  $connected
     * @return array<string, list<string>> group label => display names
     */
    private function memberGroups(?array $connected): array
    {
        return collect(MemberList::for($this->server->fresh(), $connected)->groups)
            ->mapWithKeys(fn (MemberGroup $group) => [$group->label => $group->members->map->displayName()->all()])
            ->all();
    }

    // --- ServerPresence ------------------------------------------------------

    public function test_the_channel_is_named_after_the_server(): void
    {
        $this->assertSame('server.12', ServerPresence::channelName(12));
    }

    public function test_it_asks_reverb_who_is_in_the_servers_presence_channel(): void
    {
        $alice = $this->member('Alice');
        $this->reverbWith([(object) ['id' => $alice->id]]);

        $this->assertSame([$alice->id], app(ServerPresence::class)->connectedUserIds($this->server));
    }

    public function test_the_viewer_always_counts_as_connected(): void
    {
        $alice = $this->member('Alice');
        $this->reverbWith([(object) ['id' => $alice->id], (object) ['id' => $this->owner->id]]);

        $this->assertSame([$alice->id, $this->owner->id], app(ServerPresence::class)->connectedUserIds($this->server, $this->owner));
    }

    public function test_the_viewer_counts_as_connected_before_reverb_has_seen_them(): void
    {
        $this->reverbWith([]);

        $this->assertSame([$this->owner->id], app(ServerPresence::class)->connectedUserIds($this->server, $this->owner));
    }

    public function test_ids_sent_as_strings_or_arrays_are_read_and_junk_is_ignored(): void
    {
        $this->reverbWith([(object) ['id' => '5'], ['id' => 6], (object) ['id' => 'abc'], (object) [], 'nonsense', (object) ['id' => 5]]);

        $this->assertSame([5, 6], app(ServerPresence::class)->connectedUserIds($this->server));
    }

    public function test_a_response_without_users_means_nobody_but_the_viewer(): void
    {
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('getPresenceUsers')->andReturn((object) []);
        $this->usePusher($pusher);

        $this->assertSame([$this->owner->id], app(ServerPresence::class)->connectedUserIds($this->server, $this->owner));
    }

    public function test_a_reverb_error_is_reported_and_only_the_viewer_counts(): void
    {
        $this->reverbDown();

        $this->assertSame([$this->owner->id], app(ServerPresence::class)->connectedUserIds($this->server, $this->owner));
        $this->assertSame([], app(ServerPresence::class)->connectedUserIds($this->server));
    }

    public function test_presence_is_unknown_without_a_websocket_broadcaster(): void
    {
        config(['broadcasting.default' => 'null']);
        $this->assertNull(app(ServerPresence::class)->connectedUserIds($this->server, $this->owner));

        config(['broadcasting.default' => 'log']);
        $this->assertNull(app(ServerPresence::class)->connectedUserIds($this->server, $this->owner));
    }

    // --- MemberList with presence -----------------------------------------------

    public function test_connected_members_are_online_with_the_status_they_picked(): void
    {
        $alice = $this->member('Alice', 'dnd');
        $bob = $this->member('Bob', 'idle');
        $this->member('Carol', 'online');
        $connected = [$this->owner->id, $alice->id, $bob->id];

        $list = MemberList::for($this->server->fresh(), $connected);

        $this->assertSame(['Online' => ['Alice', 'Bob', 'Owner'], 'Offline' => ['Carol']], $this->memberGroups($connected));
        $this->assertSame('dnd', $list->statuses[$alice->id]);
        $this->assertSame('idle', $list->statuses[$bob->id]);
        $this->assertSame(3, $list->online);
        $this->assertSame(4, $list->total);
    }

    public function test_members_who_are_not_connected_are_offline_whatever_their_status(): void
    {
        $this->member('Alice', 'online');
        $this->member('Bob', 'dnd');

        $this->assertSame(['Offline' => ['Alice', 'Bob', 'Owner']], $this->memberGroups([]));
    }

    public function test_a_stored_offline_or_unknown_status_shows_as_online_once_connected(): void
    {
        // users.status still defaults to "offline" in the database, from before presence.
        $alice = $this->member('Alice', 'offline');
        $bob = $this->member('Bob', 'invisible');

        $list = MemberList::for($this->server->fresh(), [$alice->id, $bob->id]);

        $this->assertSame('online', $list->statuses[$alice->id]);
        $this->assertSame('online', $list->statuses[$bob->id]);
    }

    public function test_connected_ids_of_people_who_are_not_members_are_ignored(): void
    {
        $stranger = User::factory()->create();

        $list = MemberList::for($this->server->fresh(), [$stranger->id]);

        $this->assertSame(0, $list->online);
        $this->assertSame(1, $list->total);
    }

    public function test_connected_members_go_under_their_highest_role(): void
    {
        $mods = Role::factory()->create(['server_id' => $this->server->id, 'name' => 'Mods', 'position' => 3, 'color' => '#e67e22']);
        $alice = $this->member('Alice');
        $carol = $this->member('Carol');
        $mods->users()->attach([$alice->id, $carol->id]);

        $this->assertSame(['Mods' => ['Alice'], 'Offline' => ['Carol', 'Owner']], $this->memberGroups([$alice->id]));
    }

    public function test_all_groups_lists_every_group_in_order_even_when_empty(): void
    {
        $high = Role::factory()->create(['server_id' => $this->server->id, 'name' => 'High', 'position' => 9, 'color' => '#123456']);
        $low = Role::factory()->create(['server_id' => $this->server->id, 'name' => 'Low', 'position' => 1, 'color' => 'red']);

        $list = MemberList::for($this->server->fresh(), []);

        $this->assertSame(
            ["role-{$high->id}", "role-{$low->id}", MemberGroup::ONLINE, MemberGroup::OFFLINE],
            array_map(fn (MemberGroup $group) => $group->key, $list->allGroups),
        );
        $this->assertSame([MemberGroup::OFFLINE], array_map(fn (MemberGroup $group) => $group->key, $list->groups));
        // Only #rrggbb colours are kept.
        $this->assertSame('#123456', $list->allGroups[0]->color);
        $this->assertNull($list->allGroups[1]->color);
        $this->assertNull($list->allGroups[2]->color);
    }

    public function test_the_status_of_someone_not_on_the_list_is_offline(): void
    {
        $list = MemberList::for($this->server->fresh());
        $outsider = ServerMember::factory()->make(['user_id' => 999999]);

        $this->assertSame('offline', $list->statusOf($outsider));
    }

    public function test_the_query_count_does_not_change_with_presence(): void
    {
        foreach (range(1, 6) as $i) {
            $this->member("User {$i}");
        }

        $this->server->getConnection()->enableQueryLog();
        MemberList::for($this->server, [$this->owner->id]);

        $this->assertCount(4, $this->server->getConnection()->getQueryLog());
    }

    // --- The server page ---------------------------------------------------------

    public function test_the_server_page_shows_who_reverb_says_is_connected(): void
    {
        $alice = $this->member('Alice', 'dnd');
        $this->member('Bob', 'online');
        $this->reverbWith([(object) ['id' => $alice->id]]);

        $html = (string) $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}")
            ->assertOk()
            ->assertSeeInOrder(['Online — 2', 'Alice', 'Owner', 'Offline — 1', 'Bob'])
            ->assertSee('Members (2 online of 3)')
            ->getContent();

        $this->assertMatchesRegularExpression('/data-user-id="'.$alice->id.'"[^>]*data-presence="dnd"/s', $html);
        $this->assertStringContainsString('data-member-list'."\n".'    data-server-id="'.$this->server->id.'"', $html);
    }

    public function test_the_viewer_is_online_on_their_own_page_even_if_reverb_has_not_seen_them_yet(): void
    {
        $this->owner->update(['status' => 'idle']);
        $this->reverbWith([]);

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}")
            ->assertOk()
            ->assertSeeInOrder(['Online — 1', 'Owner', ', Idle']);
    }

    public function test_the_page_still_renders_when_reverb_is_down(): void
    {
        $this->member('Alice', 'online');
        $this->reverbDown();

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}")
            ->assertOk()
            ->assertSeeInOrder(['Online — 1', 'Owner', 'Offline — 1', 'Alice']);
    }

    public function test_members_carry_what_presence_js_needs(): void
    {
        $mods = Role::factory()->create(['server_id' => $this->server->id, 'name' => 'Mods', 'position' => 3, 'color' => '#e67e22']);
        $alice = $this->member('Alice', 'idle');
        $mods->users()->attach($alice);
        ServerMember::query()->where('user_id', $alice->id)->update(['nickname' => 'Ålice']);
        $this->reverbWith([]);

        $html = (string) $this->actingAs($this->owner)->get("/servers/{$this->server->id}")->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-user-id="'.$alice->id.'"\s+data-user-status="idle"\s+data-presence="offline"\s+data-role-group="role-'.$mods->id.'"\s+data-sort-name="ålice"/u',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/data-user-id="'.$this->owner->id.'"\s+data-user-status="online"\s+data-presence="online"\s+data-role-group="online"/',
            $html,
        );
    }

    public function test_empty_groups_are_rendered_hidden_with_their_colour(): void
    {
        $mods = Role::factory()->create(['server_id' => $this->server->id, 'name' => 'Mods', 'position' => 3, 'color' => '#e67e22']);
        $this->reverbWith([]);

        $html = (string) $this->actingAs($this->owner)->get("/servers/{$this->server->id}")->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-member-group="role-'.$mods->id.'"[^>]*data-role-color="#e67e22"[^>]*hidden/s', $html);
        $this->assertMatchesRegularExpression('/data-member-group="offline"[^>]*hidden/s', $html);
        $this->assertDoesNotMatchRegularExpression('/data-member-group="online"[^>]*hidden/s', $html);
    }
}
