<?php

namespace Tests\Feature\Moderation;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Events\MemberModerated;
use App\Events\MessageDeleted;
use App\Models\Ban;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Invite;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Support\RealtimeSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * Kicking, banning and timing out members, the role hierarchy
 * that limits who may do it, invites for banned users, and telling the
 * affected user's browser (MemberModerated) and closing their sockets
 * (RealtimeSessions).
 */
class ModerationTest extends TestCase
{
    use GivesPermissions, RefreshDatabase;

    private User $owner;

    private Server $server;

    private Channel $channel;

    private User $moderator;

    private User $member;

    /** @var list<int> user ids passed to RealtimeSessions::disconnect() */
    private array $disconnected = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Mod Server');
        /** @var Channel $channel */
        $channel = $this->server->channels()->firstOrFail();
        $this->channel = $channel;

        $this->moderator = $this->memberWith($this->server, Permission::KickMembers, Permission::BanMembers, Permission::TimeoutMembers);
        $this->member = $this->joinServer($this->server, User::factory()->create(['name' => 'Member']));

        $this->mock(RealtimeSessions::class, function (MockInterface $mock): void {
            $mock->shouldReceive('disconnect')->andReturnUsing(function (User $user): void {
                $this->disconnected[] = (int) $user->id;
            });
        });
    }

    // --- Kick ------------------------------------------------------------

    public function test_a_moderator_kicks_a_member_and_their_roles_go_with_the_membership(): void
    {
        Event::fake([MemberModerated::class]);
        $this->moderator->roles()->firstOrFail()->update(['position' => 5]);
        $this->giveRole($this->member, $this->makeRole($this->server, 'Low', 0, 1));

        $this->actingAs($this->moderator)
            ->post(route('members.kick', [$this->server, $this->member]))
            ->assertRedirect(route('members.index', $this->server))
            ->assertSessionHas('status', 'Member has been kicked from the server.');

        $this->assertFalse($this->server->hasMember($this->member));
        $this->assertSame(0, DB::table('role_user')->where('user_id', $this->member->id)->count());
        $this->assertFalse($this->server->hasBanned($this->member));

        Event::assertDispatched(MemberModerated::class, fn (MemberModerated $e): bool => $e->type === MemberModerated::KICKED
            && $e->recipient->is($this->member)
            && $e->server->is($this->server));
        $this->assertSame([$this->member->id], $this->disconnected);
    }

    public function test_a_kicked_member_can_rejoin_with_an_invite(): void
    {
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));
        $invite = Invite::factory()->create(['server_id' => $this->server->id, 'inviter_id' => $this->owner->id]);

        $this->actingAs($this->member)->post("/invite/{$invite->code}")->assertRedirect(route('servers.show', $this->server));

        $this->assertTrue($this->server->hasMember($this->member));
    }

    public function test_kicking_needs_kick_members(): void
    {
        $other = $this->memberWith($this->server, Permission::BanMembers);

        $this->actingAs($other)->post(route('members.kick', [$this->server, $this->member]))->assertForbidden();
        $this->actingAs($this->member)->post(route('members.kick', [$this->server, $other]))->assertForbidden();

        $this->assertTrue($this->server->hasMember($this->member));
        $this->assertSame([], $this->disconnected);
    }

    public function test_nobody_can_kick_the_owner_or_themselves(): void
    {
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->owner]))->assertForbidden();
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->moderator]))->assertForbidden();
        $this->actingAs($this->owner)->post(route('members.kick', [$this->server, $this->owner]))->assertForbidden();

        $this->assertTrue($this->server->hasMember($this->moderator));
    }

    public function test_moderators_can_only_act_on_members_below_their_highest_role(): void
    {
        // memberWith() puts each new role on top, so this one is above the moderator's.
        $senior = $this->memberWith($this->server, Permission::ManageMessages);
        $peer = $this->joinServer($this->server);
        $this->giveRole($peer, $this->moderator->roles()->firstOrFail());

        foreach (['members.kick', 'members.ban'] as $route) {
            $this->actingAs($this->moderator)->post(route($route, [$this->server, $senior]))->assertForbidden();
            $this->actingAs($this->moderator)->post(route($route, [$this->server, $peer]))->assertForbidden();
        }
        $this->actingAs($this->moderator)
            ->put(route('members.timeout', [$this->server, $peer]), ['duration' => 60])
            ->assertForbidden();

        $this->assertTrue($this->server->hasMember($senior));
        $this->assertTrue($this->server->hasMember($peer));
    }

    public function test_a_moderator_with_only_everyone_cannot_kick_another_everyone_member(): void
    {
        $everyone = $this->server->defaultRole();
        $everyone->update(['permissions' => (int) $everyone->permissions | Permission::KickMembers->value]);

        $this->actingAs($this->member)
            ->post(route('members.kick', [$this->server, $this->joinServer($this->server)]))
            ->assertForbidden();
    }

    public function test_administrator_does_not_lift_the_hierarchy(): void
    {
        $admin = $this->memberWith($this->server, Permission::Administrator);
        $above = $this->memberWith($this->server, Permission::SendMessages);

        $this->actingAs($admin)->post(route('members.kick', [$this->server, $above]))->assertForbidden();
        $this->actingAs($admin)->post(route('members.kick', [$this->server, $this->member]))->assertRedirect();
    }

    public function test_the_owner_can_kick_anyone_but_themselves(): void
    {
        $this->actingAs($this->owner)
            ->post(route('members.kick', [$this->server, $this->moderator]))
            ->assertRedirect(route('members.index', $this->server));

        $this->assertFalse($this->server->hasMember($this->moderator));
    }

    public function test_acting_on_someone_who_is_not_a_member_is_a_404(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $stranger]))->assertNotFound();
        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $stranger]))->assertNotFound();
        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $stranger]), ['duration' => 60])->assertNotFound();
        $this->actingAs($this->moderator)->post('/servers/'.$this->server->id.'/members/999999/kick')->assertNotFound();

        $this->assertSame(0, Ban::query()->count());
    }

    public function test_people_outside_the_server_cannot_moderate_it(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('members.index', $this->server))->assertForbidden();
        $this->actingAs($outsider)->post(route('members.kick', [$this->server, $this->member]))->assertForbidden();
        $this->actingAs($outsider)->get(route('bans.index', $this->server))->assertForbidden();
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('members.index', $this->server))->assertRedirect('/login');
        $this->post(route('members.kick', [$this->server, $this->member]))->assertRedirect('/login');
    }

    // --- Ban -------------------------------------------------------------

    public function test_a_moderator_bans_a_member_with_a_reason(): void
    {
        Event::fake([MemberModerated::class]);

        $this->actingAs($this->moderator)
            ->post(route('members.ban', [$this->server, $this->member]), ['reason' => '  Spamming invites  '])
            ->assertRedirect(route('members.index', $this->server))
            ->assertSessionHas('status', 'Member has been banned from the server.');

        $ban = Ban::query()->where('server_id', $this->server->id)->where('user_id', $this->member->id)->firstOrFail();
        $this->assertSame('Spamming invites', $ban->reason);
        $this->assertSame($this->moderator->id, $ban->banned_by_id);
        $this->assertFalse($this->server->hasMember($this->member));

        Event::assertDispatched(MemberModerated::class, fn (MemberModerated $e): bool => $e->type === MemberModerated::BANNED
            && $e->recipient->is($this->member));
        $this->assertSame([$this->member->id], $this->disconnected);
    }

    public function test_an_empty_reason_is_stored_as_none(): void
    {
        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $this->member]), ['reason' => '   ']);

        $this->assertNull(Ban::query()->firstOrFail()->reason);
    }

    public function test_a_banned_user_cannot_rejoin_with_any_invite(): void
    {
        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $this->member]));
        $invite = Invite::factory()->create(['server_id' => $this->server->id, 'inviter_id' => $this->owner->id]);

        $this->actingAs($this->member)->get("/invite/{$invite->code}")->assertForbidden()->assertSee('You are banned from this server.');
        $this->actingAs($this->member)->post("/invite/{$invite->code}")->assertForbidden();

        $this->assertFalse($this->server->hasMember($this->member));
        $this->assertSame(0, $invite->fresh()?->uses);
    }

    public function test_banning_can_delete_recent_messages_in_the_servers_channels_only(): void
    {
        $recent = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->member->id, 'created_at' => now()->subMinutes(30)]);
        $old = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->member->id, 'created_at' => now()->subHours(2)]);
        $someoneElse = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->moderator->id]);

        $otherServer = app(CreateServer::class)->create($this->member, 'Elsewhere');
        /** @var Channel $otherChannel */
        $otherChannel = $otherServer->channels()->firstOrFail();
        $elsewhere = Message::factory()->inChannel($otherChannel)->create(['user_id' => $this->member->id]);

        $dm = Message::factory()->inConversation(Conversation::factory()->create())->create(['user_id' => $this->member->id]);

        Event::fake([MessageDeleted::class, MemberModerated::class]);

        $this->actingAs($this->moderator)
            ->post(route('members.ban', [$this->server, $this->member]), ['delete_messages' => 3600])
            ->assertSessionHas('status', 'Member has been banned from the server. 1 message was deleted.');

        $this->assertSoftDeleted($recent);
        $this->assertNotSoftDeleted($old);
        $this->assertNotSoftDeleted($someoneElse);
        $this->assertNotSoftDeleted($elsewhere);
        $this->assertNotSoftDeleted($dm);

        Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $e): bool => $e->messageId === $recent->id
            && $e->channelId === $this->channel->id);
        Event::assertDispatchedTimes(MessageDeleted::class, 1);
    }

    public function test_banning_keeps_messages_by_default(): void
    {
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->member->id]);

        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $this->member]));

        $this->assertNotSoftDeleted($message);
    }

    public function test_ban_input_is_validated(): void
    {
        $this->actingAs($this->moderator)
            ->post(route('members.ban', [$this->server, $this->member]), ['delete_messages' => 42])
            ->assertSessionHasErrors('delete_messages');

        $this->actingAs($this->moderator)
            ->post(route('members.ban', [$this->server, $this->member]), ['reason' => str_repeat('a', 513)])
            ->assertSessionHasErrors('reason');

        $this->assertTrue($this->server->hasMember($this->member));
        $this->assertSame(0, Ban::query()->count());
    }

    public function test_banning_needs_ban_members(): void
    {
        $kicker = $this->memberWith($this->server, Permission::KickMembers);

        $this->actingAs($kicker)->post(route('members.ban', [$this->server, $this->member]))->assertForbidden();

        $this->assertSame(0, Ban::query()->count());
    }

    // --- Bans list and unban ----------------------------------------------

    public function test_the_ban_list_shows_who_banned_whom_and_why(): void
    {
        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $this->member]), ['reason' => '<b>rude</b>']);

        $this->actingAs($this->moderator)
            ->get(route('bans.index', $this->server))
            ->assertOk()
            ->assertSee('Member')
            ->assertSee('&lt;b&gt;rude&lt;/b&gt;', false)
            ->assertSee('Banned by '.$this->moderator->name);
    }

    public function test_unbanning_lets_the_user_join_again(): void
    {
        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $this->member]));

        $this->actingAs($this->moderator)
            ->delete(route('bans.destroy', [$this->server, $this->member]))
            ->assertRedirect(route('bans.index', $this->server))
            ->assertSessionHas('status', 'Member has been unbanned.');

        $this->assertFalse($this->server->hasBanned($this->member));
        $this->assertFalse($this->server->hasMember($this->member), 'Unbanning does not add them back.');

        $invite = Invite::factory()->create(['server_id' => $this->server->id, 'inviter_id' => $this->owner->id]);
        $this->actingAs($this->member)->post("/invite/{$invite->code}")->assertRedirect(route('servers.show', $this->server));
        $this->assertTrue($this->server->hasMember($this->member));
    }

    public function test_unbanning_someone_who_is_not_banned_is_a_404(): void
    {
        $this->actingAs($this->moderator)->delete(route('bans.destroy', [$this->server, $this->member]))->assertNotFound();
    }

    public function test_the_ban_list_and_unbanning_need_ban_members(): void
    {
        $banned = User::factory()->create();
        Ban::query()->create(['server_id' => $this->server->id, 'user_id' => $banned->id]);
        $kicker = $this->memberWith($this->server, Permission::KickMembers);

        $this->actingAs($kicker)->get(route('bans.index', $this->server))->assertForbidden();
        $this->actingAs($this->member)->get(route('bans.index', $this->server))->assertForbidden();
        $this->actingAs($kicker)->delete(route('bans.destroy', [$this->server, $banned]))->assertForbidden();

        $this->assertTrue($this->server->hasBanned($banned));
    }

    // --- Timeout ---------------------------------------------------------

    public function test_a_moderator_times_a_member_out(): void
    {
        Event::fake([MemberModerated::class]);
        $this->freezeSecond();

        $this->actingAs($this->moderator)
            ->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 600])
            ->assertRedirect(route('members.index', $this->server))
            ->assertSessionHas('status', 'Member has been timed out for 10 minutes.');

        $until = $this->server->membershipOf($this->member)?->timed_out_until;
        $this->assertNotNull($until);
        $this->assertTrue($until->equalTo(now()->addMinutes(10)));
        $this->assertTrue($this->server->hasMember($this->member));

        Event::assertDispatched(MemberModerated::class, fn (MemberModerated $e): bool => $e->type === MemberModerated::TIMED_OUT
            && $e->until !== null && $e->until->equalTo(now()->addMinutes(10)));
        $this->assertSame([], $this->disconnected, 'A timeout does not disconnect.');
    }

    public function test_a_timed_out_member_can_read_but_not_post_react_or_invite(): void
    {
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id]);
        $message->reactions()->create(['user_id' => $this->owner->id, 'emoji' => '👍']);
        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 600]);

        $this->actingAs($this->member)
            ->get(route('channels.show', [$this->server, $this->channel]))
            ->assertOk()
            ->assertSee('You are timed out until')
            ->assertDontSee('data-message-form', false);

        $this->actingAs($this->member)
            ->postJson(route('messages.store', [$this->server, $this->channel]), ['content' => 'hi'])
            ->assertForbidden();
        // Joining an existing reaction is refused too.
        $this->actingAs($this->member)
            ->postJson(route('reactions.store', [$this->server, $this->channel, $message]), ['emoji' => '👍'])
            ->assertForbidden();
        $this->actingAs($this->member)
            ->post(route('servers.invites.store', $this->server))
            ->assertForbidden();

        $this->assertSame(1, Message::query()->count());
        $this->assertSame(1, $message->reactions()->count());
    }

    public function test_a_timeout_ends_by_itself(): void
    {
        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 60]);

        $this->travel(61)->seconds();

        $this->actingAs($this->member)
            ->postJson(route('messages.store', [$this->server, $this->channel]), ['content' => 'back'])
            ->assertCreated();
    }

    public function test_a_timeout_can_be_removed_early(): void
    {
        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 3600]);
        Event::fake([MemberModerated::class]);

        $this->actingAs($this->moderator)
            ->delete(route('members.timeout.destroy', [$this->server, $this->member]))
            ->assertSessionHas('status', "Member's timeout has been removed.");

        $this->assertNull($this->server->membershipOf($this->member)?->timed_out_until);
        Event::assertDispatched(MemberModerated::class, fn (MemberModerated $e): bool => $e->type === MemberModerated::TIMEOUT_REMOVED);

        $this->actingAs($this->moderator)
            ->delete(route('members.timeout.destroy', [$this->server, $this->member]))
            ->assertSessionHas('status', 'Member is not timed out.');
        Event::assertDispatchedTimes(MemberModerated::class, 1);
    }

    public function test_administrators_cannot_be_timed_out(): void
    {
        $admin = $this->joinServer($this->server);
        $this->giveRole($admin, $this->makeRole($this->server, 'Admin', Permission::Administrator->value, 1));
        $this->moderator->roles()->firstOrFail()->update(['position' => 10]);

        $this->actingAs($this->moderator)
            ->put(route('members.timeout', [$this->server, $admin]), ['duration' => 60])
            ->assertForbidden();

        // They can still be kicked by someone above them.
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $admin]))->assertRedirect();
    }

    public function test_timeout_duration_is_validated_and_needs_timeout_members(): void
    {
        $this->actingAs($this->moderator)
            ->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 123])
            ->assertSessionHasErrors('duration');
        $this->actingAs($this->moderator)
            ->put(route('members.timeout', [$this->server, $this->member]), [])
            ->assertSessionHasErrors('duration');

        $kicker = $this->memberWith($this->server, Permission::KickMembers);
        $this->actingAs($kicker)
            ->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 60])
            ->assertForbidden();

        $this->assertNull($this->server->membershipOf($this->member)?->timed_out_until);
    }

    // --- Members page ----------------------------------------------------

    public function test_the_members_page_shows_only_allowed_actions(): void
    {
        $kicker = $this->memberWith($this->server, Permission::KickMembers);

        $response = $this->actingAs($kicker)->get(route('members.index', $this->server))->assertOk();

        $response->assertSee(route('members.kick', [$this->server, $this->member]), false);
        $response->assertSee(route('members.kick', [$this->server, $this->moderator]), false);
        $response->assertDontSee(route('members.ban', [$this->server, $this->member]), false);
        $response->assertDontSee(route('members.timeout', [$this->server, $this->member]), false);
        // Never the owner or themselves; no ban list without Ban Members.
        $response->assertDontSee(route('members.kick', [$this->server, $this->owner]), false);
        $response->assertDontSee(route('members.kick', [$this->server, $kicker]), false);
        $response->assertDontSee(route('bans.index', $this->server), false);
    }

    public function test_the_members_page_needs_a_moderation_permission(): void
    {
        $this->actingAs($this->member)->get(route('members.index', $this->server))->assertForbidden();

        $this->actingAs($this->owner)
            ->get(route('servers.show', $this->server))
            ->assertSee(route('members.index', $this->server), false)
            ->assertSee(route('bans.index', $this->server), false);
        $this->actingAs($this->member)
            ->get(route('servers.show', $this->server))
            ->assertDontSee(route('members.index', $this->server), false);
    }

    public function test_the_members_page_shows_timeouts(): void
    {
        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 60]);

        $this->actingAs($this->moderator)
            ->get(route('members.index', $this->server))
            ->assertSee('Timed out until')
            ->assertSee(route('members.timeout.destroy', [$this->server, $this->member]), false);
    }

    // --- MemberModerated -------------------------------------------------

    public function test_member_moderated_goes_to_the_users_own_channel_without_the_reason(): void
    {
        $event = new MemberModerated(MemberModerated::BANNED, $this->server, $this->member);

        $this->assertSame('private-App.Models.User.'.$this->member->id, $event->broadcastOn()->name);
        $this->assertSame('member.moderated', $event->broadcastAs());
        $this->assertSame([
            'type' => 'banned',
            'server' => ['id' => $this->server->id, 'name' => 'Mod Server'],
            'until' => null,
        ], $event->broadcastWith());
    }
}
