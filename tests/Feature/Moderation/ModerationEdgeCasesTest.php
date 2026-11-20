<?php

namespace Tests\Feature\Moderation;

use App\Actions\Moderation\BanMember;
use App\Actions\Moderation\KickMember;
use App\Actions\Moderation\TimeoutMember;
use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Events\MemberModerated;
use App\Models\Ban;
use App\Models\Channel;
use App\Models\Invite;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Support\RealtimeSessions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * Edge cases on top of ModerationTest: timed-out moderators,
 * timeouts that are replaced, expired or outlive a membership, larger
 * message purges, the actions called directly (reason clean-up, re-bans,
 * the owner), and the order of the members and ban lists.
 */
class ModerationEdgeCasesTest extends TestCase
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

    // --- Timeouts --------------------------------------------------------

    public function test_a_timed_out_moderator_cannot_moderate(): void
    {
        $this->actingAs($this->owner)
            ->put(route('members.timeout', [$this->server, $this->moderator]), ['duration' => 600])
            ->assertRedirect();

        $this->actingAs($this->moderator)->get(route('members.index', $this->server))->assertForbidden();
        $this->actingAs($this->moderator)->get(route('bans.index', $this->server))->assertForbidden();
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]))->assertForbidden();
        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $this->member]))->assertForbidden();
        $this->actingAs($this->moderator)
            ->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 60])
            ->assertForbidden();

        $this->assertTrue($this->server->hasMember($this->member));
        $this->assertSame(0, Ban::query()->count());
    }

    public function test_timing_out_again_replaces_the_earlier_timeout(): void
    {
        $this->freezeSecond();

        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 604800]);
        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 60]);

        $until = $this->server->membershipOf($this->member)?->timed_out_until;
        $this->assertNotNull($until);
        $this->assertTrue($until->equalTo(now()->addSeconds(60)), 'A shorter timeout overrides a longer one.');

        $this->travel(61)->seconds();
        $this->actingAs($this->member)
            ->postJson(route('messages.store', [$this->server, $this->channel]), ['content' => 'free'])
            ->assertCreated();
    }

    public function test_removing_an_expired_timeout_says_they_are_not_timed_out(): void
    {
        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 60]);
        $this->travel(2)->minutes();
        Event::fake([MemberModerated::class]);

        $this->actingAs($this->moderator)
            ->delete(route('members.timeout.destroy', [$this->server, $this->member]))
            ->assertSessionHas('status', 'Member is not timed out.');

        Event::assertNotDispatched(MemberModerated::class);
    }

    public function test_the_members_page_does_not_show_an_expired_timeout(): void
    {
        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 60]);
        $this->travel(2)->minutes();

        $this->actingAs($this->moderator)
            ->get(route('members.index', $this->server))
            ->assertOk()
            ->assertDontSee('Timed out until');
    }

    public function test_a_timeout_does_not_survive_a_kick_and_rejoin(): void
    {
        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 604800]);
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));

        $invite = Invite::factory()->create(['server_id' => $this->server->id, 'inviter_id' => $this->owner->id]);
        $this->actingAs($this->member)->post("/invite/{$invite->code}")->assertRedirect(route('servers.show', $this->server));

        $this->assertNull($this->server->membershipOf($this->member)?->timed_out_until);
        $this->actingAs($this->member)
            ->postJson(route('messages.store', [$this->server, $this->channel]), ['content' => 'fresh start'])
            ->assertCreated();
    }

    public function test_the_owner_can_time_out_a_moderator(): void
    {
        $this->actingAs($this->owner)
            ->put(route('members.timeout', [$this->server, $this->moderator]), ['duration' => 300])
            ->assertSessionHas('status', $this->moderator->name.' has been timed out for 5 minutes.');

        $this->assertTrue($this->server->membershipOf($this->moderator)?->isTimedOut() ?? false);
    }

    public function test_the_timeout_action_only_touches_that_servers_membership(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Other Server');
        $this->joinServer($other, $this->member);

        app(TimeoutMember::class)->timeout($this->server, $this->member, 600);

        $this->assertTrue($this->server->membershipOf($this->member)?->isTimedOut() ?? false);
        $this->assertNull($other->membershipOf($this->member)?->timed_out_until);
    }

    // --- Kick ------------------------------------------------------------

    public function test_a_kick_keeps_the_members_messages(): void
    {
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->member->id]);

        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));

        $this->assertNotSoftDeleted($message);
    }

    public function test_a_kicked_member_can_no_longer_open_the_server(): void
    {
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));

        $this->actingAs($this->member)->get(route('channels.show', [$this->server, $this->channel]))->assertForbidden();
        $this->actingAs($this->member)
            ->postJson(route('messages.store', [$this->server, $this->channel]), ['content' => 'still here?'])
            ->assertForbidden();
    }

    public function test_kicking_the_owner_directly_fails_without_telling_or_disconnecting_anyone(): void
    {
        Event::fake([MemberModerated::class]);

        try {
            app(KickMember::class)->kick($this->server, $this->owner);
            $this->fail('Kicking the owner should throw.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertTrue($this->server->hasMember($this->owner));
        Event::assertNotDispatched(MemberModerated::class);
        $this->assertSame([], $this->disconnected);
    }

    // --- Ban -------------------------------------------------------------

    public function test_banning_the_owner_directly_leaves_no_ban_behind(): void
    {
        Event::fake([MemberModerated::class]);

        try {
            app(BanMember::class)->ban($this->server, $this->owner, $this->moderator, 'coup');
            $this->fail('Banning the owner should throw.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(0, Ban::query()->count(), 'The ban is rolled back with the failed removal.');
        $this->assertTrue($this->server->hasMember($this->owner));
        Event::assertNotDispatched(MemberModerated::class);
        $this->assertSame([], $this->disconnected);
    }

    public function test_the_ban_action_trims_and_shortens_the_reason(): void
    {
        app(BanMember::class)->ban($this->server, $this->member, $this->moderator, '  '.str_repeat('é', 600).'  ');

        $reason = Ban::query()->firstOrFail()->reason;
        $this->assertNotNull($reason);
        $this->assertSame(BanMember::MAX_REASON_LENGTH, mb_strlen($reason));
        $this->assertSame(str_repeat('é', BanMember::MAX_REASON_LENGTH), $reason);
    }

    public function test_banning_again_updates_the_existing_ban(): void
    {
        $action = app(BanMember::class);
        $action->ban($this->server, $this->member, $this->moderator, 'first');
        $action->ban($this->server, $this->member, $this->owner, 'second');

        $ban = Ban::query()->sole();
        $this->assertSame('second', $ban->reason);
        $this->assertSame($this->owner->id, $ban->banned_by_id);
    }

    public function test_a_reason_of_exactly_the_maximum_length_is_accepted(): void
    {
        $reason = str_repeat('a', BanMember::MAX_REASON_LENGTH);

        $this->actingAs($this->moderator)
            ->post(route('members.ban', [$this->server, $this->member]), ['reason' => $reason])
            ->assertSessionHasNoErrors();

        $this->assertSame($reason, Ban::query()->firstOrFail()->reason);
    }

    public function test_purging_a_week_of_messages_reports_the_count_and_spans_several_chunks(): void
    {
        Message::factory()->count(105)->inChannel($this->channel)->create([
            'user_id' => $this->member->id,
            'created_at' => now()->subDays(6),
        ]);
        $tooOld = Message::factory()->inChannel($this->channel)->create([
            'user_id' => $this->member->id,
            'created_at' => now()->subDays(8),
        ]);

        // A second channel of the same server is purged too.
        $second = $this->server->channels()->create(['name' => 'second', 'position' => 1]);
        $inSecond = Message::factory()->inChannel($second)->create(['user_id' => $this->member->id]);

        $this->actingAs($this->moderator)
            ->post(route('members.ban', [$this->server, $this->member]), ['delete_messages' => 604800])
            ->assertSessionHas('status', 'Member has been banned from the server. 106 messages were deleted.');

        $this->assertSame(0, Message::query()->where('user_id', $this->member->id)->where('id', '!=', $tooOld->id)->count());
        $this->assertSoftDeleted($inSecond);
        $this->assertNotSoftDeleted($tooOld);
    }

    public function test_a_purge_with_nothing_to_delete_does_not_mention_messages(): void
    {
        $this->actingAs($this->moderator)
            ->post(route('members.ban', [$this->server, $this->member]), ['delete_messages' => 86400])
            ->assertSessionHas('status', 'Member has been banned from the server.');
    }

    public function test_a_banned_user_can_no_longer_open_the_server(): void
    {
        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $this->member]));

        $this->actingAs($this->member)->get(route('channels.show', [$this->server, $this->channel]))->assertForbidden();
    }

    public function test_a_ban_outlives_the_moderator_who_made_it(): void
    {
        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $this->member]), ['reason' => 'spam']);

        $this->moderator->delete();

        $this->assertTrue($this->server->hasBanned($this->member));
        $this->actingAs($this->owner)
            ->get(route('bans.index', $this->server))
            ->assertOk()
            ->assertSee('Banned by Deleted User');
    }

    // --- Lists -----------------------------------------------------------

    public function test_the_ban_list_is_newest_first_and_says_when_there_is_no_reason(): void
    {
        $first = $this->joinServer($this->server, User::factory()->create(['name' => 'First Banned']));
        $second = $this->joinServer($this->server, User::factory()->create(['name' => 'Second Banned']));
        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $first]));
        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $second]), ['reason' => 'Raid']);

        $this->actingAs($this->moderator)
            ->get(route('bans.index', $this->server))
            ->assertSeeInOrder(['Second Banned', 'Raid', 'First Banned', 'No reason given.']);
    }

    public function test_an_empty_ban_list_says_so(): void
    {
        $this->actingAs($this->moderator)
            ->get(route('bans.index', $this->server))
            ->assertOk()
            ->assertSee('Nobody is banned from this server.');
    }

    public function test_the_members_page_is_sorted_by_display_name_ignoring_case(): void
    {
        $zed = $this->joinServer($this->server, User::factory()->create(['name' => 'zed']));
        $alpha = $this->joinServer($this->server, User::factory()->create(['name' => 'Zulu']));
        $this->server->membershipOf($alpha)?->update(['nickname' => 'alpha']);

        $this->actingAs($this->owner)
            ->get(route('members.index', $this->server))
            ->assertOk()
            ->assertSeeInOrder([
                'data-moderation-member="'.$alpha->id.'"',
                'data-moderation-member="'.$this->member->id.'"',
                'data-moderation-member="'.$zed->id.'"',
            ], false);
    }
}
