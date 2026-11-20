<?php

namespace Tests\Feature\Presence;

use App\Actions\Moderation\BanMember;
use App\Actions\Moderation\KickMember;
use App\Actions\Moderation\TimeoutMember;
use App\Actions\Profile\UpdateUserStatus;
use App\Actions\Servers\CreateServer;
use App\Actions\Servers\LeaveServer;
use App\Events\UserStatusChanged;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The "server.{id}" presence channel, the status a user picks
 * (online, idle, dnd) and how it reaches other people's member lists.
 */
class UserStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner', 'status' => 'online']);
        $this->member = User::factory()->create(['name' => 'Member', 'status' => 'online']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Test Server');
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $this->member->id]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function authorize(string $channel): TestResponse
    {
        // phpunit.xml uses the "null" broadcaster; channel auth needs Reverb.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1001',
        ]);
        require base_path('routes/channels.php');

        return $this->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678']);
    }

    // --- The presence channel ------------------------------------------------

    public function test_members_join_with_their_id_and_status_as_member_info(): void
    {
        $this->member->update(['status' => 'dnd']);

        $response = $this->actingAs($this->member)->authorize("presence-server.{$this->server->id}")->assertOk();

        $this->assertSame(
            ['user_id' => (string) $this->member->id, 'user_info' => ['id' => $this->member->id, 'status' => 'dnd']],
            json_decode((string) $response->json('channel_data'), true),
        );
    }

    public function test_a_stored_offline_status_joins_as_online(): void
    {
        $this->member->update(['status' => 'offline']);

        $response = $this->actingAs($this->member)->authorize("presence-server.{$this->server->id}")->assertOk();

        $this->assertSame('online', json_decode((string) $response->json('channel_data'), true)['user_info']['status']);
    }

    public function test_the_owner_can_join(): void
    {
        $this->actingAs($this->owner)->authorize("presence-server.{$this->server->id}")->assertOk();
    }

    public function test_non_members_cannot_join(): void
    {
        $this->actingAs(User::factory()->create())->authorize("presence-server.{$this->server->id}")->assertForbidden();
    }

    public function test_members_who_left_or_were_kicked_cannot_join_again(): void
    {
        $leaver = User::factory()->create();
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $leaver->id]);
        app(LeaveServer::class)->leave($this->server, $leaver);

        $this->actingAs($leaver)->authorize("presence-server.{$this->server->id}")->assertForbidden();

        app(KickMember::class)->kick($this->server, $this->member);
        $this->actingAs($this->member)->authorize("presence-server.{$this->server->id}")->assertForbidden();
    }

    public function test_banned_members_cannot_join_again(): void
    {
        app(BanMember::class)->ban($this->server, $this->member, $this->owner, 'spam');

        $this->actingAs($this->member)->authorize("presence-server.{$this->server->id}")->assertForbidden();
    }

    public function test_timed_out_members_still_show_as_online(): void
    {
        app(TimeoutMember::class)->timeout($this->server, $this->member, 600);

        $this->actingAs($this->member)->authorize("presence-server.{$this->server->id}")->assertOk();
    }

    public function test_a_member_cannot_join_another_servers_channel(): void
    {
        $other = app(CreateServer::class)->create(User::factory()->create(), 'Other');

        $this->actingAs($this->member)->authorize("presence-server.{$other->id}")->assertForbidden();
    }

    public function test_an_unknown_server_is_refused(): void
    {
        $this->actingAs($this->member)->authorize('presence-server.999999')->assertForbidden();
    }

    public function test_guests_cannot_join(): void
    {
        $this->authorize("presence-server.{$this->server->id}")->assertForbidden();
    }

    // --- Picking a status --------------------------------------------------------

    public function test_a_user_can_pick_idle_or_do_not_disturb_and_back(): void
    {
        Event::fake([UserStatusChanged::class]);

        foreach (['idle', 'dnd', 'online'] as $status) {
            $this->actingAs($this->member)
                ->putJson('/settings/status', ['status' => $status])
                ->assertOk()
                ->assertExactJson(['status' => $status]);

            $this->assertSame($status, $this->member->fresh()?->status);
        }

        Event::assertDispatchedTimes(UserStatusChanged::class, 3);
    }

    public function test_the_plain_form_redirects_back_with_a_message(): void
    {
        $this->actingAs($this->member)
            ->from("/servers/{$this->server->id}")
            ->put('/settings/status', ['status' => 'dnd'])
            ->assertRedirect("/servers/{$this->server->id}")
            ->assertSessionHas('status', 'Your status is now Do Not Disturb.');
    }

    public function test_only_online_idle_and_dnd_can_be_picked(): void
    {
        foreach (['offline', 'invisible', 'Online', '', 'away'] as $status) {
            $this->actingAs($this->member)
                ->putJson('/settings/status', ['status' => $status])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');
        }

        $this->actingAs($this->member)->putJson('/settings/status', ['status' => ['idle']])->assertUnprocessable();
        $this->actingAs($this->member)->putJson('/settings/status', [])->assertUnprocessable();

        $this->assertSame('online', $this->member->fresh()?->status);
    }

    public function test_the_json_reply_carries_the_saved_status_even_when_nothing_changed(): void
    {
        $this->member->update(['status' => 'idle']);

        $this->actingAs($this->member)
            ->putJson('/settings/status', ['status' => 'idle'])
            ->assertOk()
            ->assertExactJson(['status' => 'idle']);
    }

    public function test_picking_a_status_is_rate_limited(): void
    {
        foreach (range(1, 30) as $i) {
            $this->actingAs($this->member)
                ->putJson('/settings/status', ['status' => $i % 2 === 0 ? 'online' : 'idle'])
                ->assertOk();
        }

        $this->actingAs($this->member)->putJson('/settings/status', ['status' => 'dnd'])->assertTooManyRequests();
        $this->assertSame('online', $this->member->fresh()?->status);
    }

    public function test_a_status_change_does_not_touch_other_users(): void
    {
        $this->actingAs($this->member)->putJson('/settings/status', ['status' => 'dnd'])->assertOk();

        $this->assertSame('online', $this->owner->fresh()?->status);
    }

    public function test_guests_cannot_pick_a_status(): void
    {
        $this->put('/settings/status', ['status' => 'idle'])->assertRedirect('/login');
    }

    public function test_picking_the_status_you_have_saves_and_broadcasts_nothing(): void
    {
        Event::fake([UserStatusChanged::class]);

        $this->actingAs($this->member)->putJson('/settings/status', ['status' => 'online'])->assertOk();

        Event::assertNotDispatched(UserStatusChanged::class);
    }

    public function test_a_stored_offline_status_can_be_changed_to_online(): void
    {
        Event::fake([UserStatusChanged::class]);
        $this->member->forceFill(['status' => 'offline'])->save();

        $this->assertTrue(app(UpdateUserStatus::class)->update($this->member, 'online'));

        $this->assertSame('online', $this->member->fresh()?->status);
        Event::assertDispatched(UserStatusChanged::class);
    }

    public function test_the_action_refuses_unknown_statuses(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(UpdateUserStatus::class)->update($this->member, 'offline');
    }

    public function test_the_status_survives_a_reverb_outage(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1001',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        $this->actingAs($this->member)->putJson('/settings/status', ['status' => 'idle'])->assertOk();

        $this->assertSame('idle', $this->member->fresh()?->status);
    }

    // --- UserStatusChanged -------------------------------------------------------

    public function test_the_change_goes_to_each_of_the_users_servers_and_their_own_channel(): void
    {
        $other = app(CreateServer::class)->create(User::factory()->create(), 'Other');
        ServerMember::factory()->create(['server_id' => $other->id, 'user_id' => $this->member->id]);
        $unrelated = app(CreateServer::class)->create(User::factory()->create(), 'Unrelated');
        Event::fake([UserStatusChanged::class]);

        app(UpdateUserStatus::class)->update($this->member, 'dnd');

        Event::assertDispatched(UserStatusChanged::class, function (UserStatusChanged $event) use ($other, $unrelated): bool {
            $names = array_map(fn (Channel $channel): string => $channel->name, $event->broadcastOn());
            sort($names);
            $expected = [
                'presence-server.'.$this->server->id,
                'presence-server.'.$other->id,
                'private-App.Models.User.'.$this->member->id,
            ];
            sort($expected);

            return $names === $expected
                && ! in_array('presence-server.'.$unrelated->id, $names, true)
                && $event->broadcastAs() === 'user.status'
                && $event->broadcastWith() === ['user_id' => $this->member->id, 'status' => 'dnd'];
        });
    }

    public function test_a_server_the_user_left_is_no_longer_told(): void
    {
        app(LeaveServer::class)->leave($this->server, $this->member);
        Event::fake([UserStatusChanged::class]);

        app(UpdateUserStatus::class)->update($this->member, 'idle');

        Event::assertDispatched(UserStatusChanged::class, fn (UserStatusChanged $event): bool => $event->serverIds === []);
    }

    public function test_the_change_is_broadcast_on_presence_and_private_channels(): void
    {
        $channels = (new UserStatusChanged($this->member, [$this->server->id]))->broadcastOn();

        $this->assertCount(2, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertInstanceOf(PresenceChannel::class, $channels[1]);
    }

    public function test_a_user_in_no_servers_only_tells_their_own_tabs(): void
    {
        $loner = User::factory()->create();
        Event::fake([UserStatusChanged::class]);

        app(UpdateUserStatus::class)->update($loner, 'idle');

        Event::assertDispatched(UserStatusChanged::class, fn (UserStatusChanged $event): bool => count($event->broadcastOn()) === 1);
    }

    // --- The page ------------------------------------------------------------------

    public function test_pages_list_the_servers_whose_presence_channel_to_join(): void
    {
        $other = app(CreateServer::class)->create(User::factory()->create(), 'Other');
        ServerMember::factory()->create(['server_id' => $other->id, 'user_id' => $this->member->id]);
        app(CreateServer::class)->create(User::factory()->create(), 'Unrelated');
        $this->member->update(['status' => 'idle']);

        $html = (string) $this->actingAs($this->member)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="presence-servers" content="'.$this->server->id.','.$other->id.'">', $html);
        $this->assertStringContainsString('<meta name="user-status" content="idle">', $html);
        $this->assertStringContainsString('<meta name="status-url" content="'.route('status.update').'">', $html);
    }

    public function test_a_user_in_no_servers_gets_an_empty_list(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('<meta name="presence-servers" content="">', false);
    }

    public function test_guests_get_no_presence_meta_tags(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('presence-servers', false);
    }

    public function test_the_server_sidebar_has_the_status_picker_with_the_current_status(): void
    {
        $this->member->update(['status' => 'dnd']);

        $this->actingAs($this->member)
            ->get("/servers/{$this->server->id}")
            ->assertOk()
            ->assertSee('data-status-picker', false)
            ->assertSee('action="'.route('status.update').'"', false)
            ->assertSee('<option value="dnd" selected>Do Not Disturb</option>', false)
            ->assertSee('<option value="idle" >Idle</option>', false)
            ->assertDontSee('<option value="offline"', false);
    }

    // --- New users -------------------------------------------------------------------

    public function test_new_users_are_online(): void
    {
        $this->assertSame('online', User::factory()->create()->status);
        $this->assertSame('online', (new User)->chosenStatus());
    }

    public function test_registering_makes_an_online_user(): void
    {
        $this->post('/register', [
            'name' => 'New Person',
            'username' => 'newperson',
            'email' => 'new@example.com',
            'password' => 'password-123-ABC',
            'password_confirmation' => 'password-123-ABC',
        ]);

        $this->assertSame('online', User::query()->where('username', 'newperson')->value('status'));
    }
}
