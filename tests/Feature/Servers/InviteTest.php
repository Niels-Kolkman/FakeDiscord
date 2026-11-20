<?php

namespace Tests\Feature\Servers;

use App\Actions\Servers\AcceptInvite;
use App\Actions\Servers\CreateInvite;
use App\Actions\Servers\CreateServer;
use App\Exceptions\InviteUnavailableException;
use App\Models\Ban;
use App\Models\Invite;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Creating, revoking and accepting invites.
 */
class InviteTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->server = app(CreateServer::class)->create($this->owner, 'Invite Test');
    }

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();

        parent::tearDown();
    }

    private function member(): User
    {
        $user = User::factory()->create();
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $user->id]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function invite(array $attributes = []): Invite
    {
        return Invite::factory()->create($attributes + ['server_id' => $this->server->id, 'inviter_id' => $this->owner->id]);
    }

    // --- Creating invites ------------------------------------------------

    public function test_a_member_can_create_an_invite_with_expiry_and_max_uses(): void
    {
        $this->freezeSecond();
        $member = $this->member();

        $response = $this->actingAs($member)
            ->post("/servers/{$this->server->id}/invites", ['expires_in' => 60, 'max_uses' => 5]);

        $invite = Invite::sole();
        $response->assertRedirect("/servers/{$this->server->id}/invites")
            ->assertSessionHas('invite_url', url("/invite/{$invite->code}"));

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', $invite->code);
        $this->assertSame($this->server->id, (int) $invite->server_id);
        $this->assertSame($member->id, (int) $invite->inviter_id);
        $this->assertSame(5, $invite->max_uses);
        $this->assertSame(0, $invite->uses);
        $this->assertTrue(now()->addHour()->equalTo($invite->expires_at));
    }

    public function test_never_and_no_limit_store_null(): void
    {
        $this->actingAs($this->owner)
            ->post("/servers/{$this->server->id}/invites", ['expires_in' => 0, 'max_uses' => 0])
            ->assertSessionHasNoErrors();

        $invite = Invite::sole();
        $this->assertNull($invite->expires_at);
        $this->assertNull($invite->max_uses);
    }

    public function test_only_listed_options_are_accepted(): void
    {
        foreach ([
            ['expires_in' => 999, 'max_uses' => 0],
            ['expires_in' => 60, 'max_uses' => 3],
            ['expires_in' => -30, 'max_uses' => 0],
            ['expires_in' => 'abc', 'max_uses' => 0],
            ['max_uses' => 0],
            ['expires_in' => 60],
        ] as $input) {
            $this->actingAs($this->owner)
                ->post("/servers/{$this->server->id}/invites", $input)
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, Invite::count());
    }

    public function test_non_members_cannot_create_or_list_invites(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get("/servers/{$this->server->id}/invites")->assertForbidden();
        $this->actingAs($stranger)
            ->post("/servers/{$this->server->id}/invites", ['expires_in' => 60, 'max_uses' => 0])
            ->assertForbidden();

        $this->assertSame(0, Invite::count());
    }

    public function test_the_invite_page_renders_and_lists_usable_invites(): void
    {
        $member = $this->member();
        $this->invite(['code' => 'mine1234', 'inviter_id' => $member->id]);
        $this->invite(['code' => 'owner123']);
        $this->invite(['code' => 'expired1', 'inviter_id' => $member->id, 'expires_at' => now()->subMinute()]);
        $this->invite(['code' => 'usedup12', 'inviter_id' => $member->id, 'max_uses' => 1, 'uses' => 1]);

        // Members only see their own invites.
        $this->actingAs($member)->get("/servers/{$this->server->id}/invites")
            ->assertOk()
            ->assertSee('Generate a new link')
            ->assertSee('mine1234')
            ->assertDontSee('owner123')
            ->assertDontSee('expired1')
            ->assertDontSee('usedup12');

        // The owner sees every usable invite.
        $this->actingAs($this->owner)->get("/servers/{$this->server->id}/invites")
            ->assertOk()
            ->assertSee('mine1234')
            ->assertSee('owner123')
            ->assertDontSee('expired1')
            ->assertDontSee('usedup12');
    }

    public function test_the_server_page_links_to_invites_for_members(): void
    {
        $this->actingAs($this->member())->get("/servers/{$this->server->id}")
            ->assertOk()
            ->assertSee('Invite people');
    }

    public function test_code_collisions_are_retried(): void
    {
        $this->invite(['code' => 'taken123']);
        Str::createRandomStringsUsingSequence(['taken123', 'fresh456']);

        $invite = app(CreateInvite::class)->create($this->server, $this->owner, null, null);

        $this->assertSame('fresh456', $invite->code);
        $this->assertSame(2, Invite::count());
    }

    // --- Revoking --------------------------------------------------------

    public function test_the_owner_and_the_inviter_can_revoke_but_other_members_cannot(): void
    {
        $inviter = $this->member();
        $other = $this->member();
        $first = $this->invite(['inviter_id' => $inviter->id]);
        $second = $this->invite(['inviter_id' => $inviter->id]);

        $this->actingAs($other)
            ->delete("/servers/{$this->server->id}/invites/{$first->id}")
            ->assertForbidden();

        $this->actingAs($inviter)
            ->delete("/servers/{$this->server->id}/invites/{$first->id}")
            ->assertRedirect("/servers/{$this->server->id}/invites");

        $this->actingAs($this->owner)
            ->delete("/servers/{$this->server->id}/invites/{$second->id}")
            ->assertRedirect("/servers/{$this->server->id}/invites");

        $this->assertSame(0, Invite::count());
    }

    public function test_an_inviter_who_left_cannot_revoke(): void
    {
        $inviter = $this->member();
        $invite = $this->invite(['inviter_id' => $inviter->id]);
        ServerMember::where('user_id', $inviter->id)->delete();

        $this->assertFalse($inviter->can('delete', $invite));
    }

    public function test_revoking_needs_the_invite_to_belong_to_the_server_in_the_url(): void
    {
        $otherServer = app(CreateServer::class)->create($this->owner, 'Other');
        $invite = Invite::factory()->create(['server_id' => $otherServer->id, 'inviter_id' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->delete("/servers/{$this->server->id}/invites/{$invite->id}")
            ->assertNotFound();

        $this->assertModelExists($invite);
    }

    // --- Opening and accepting -------------------------------------------

    public function test_guests_are_sent_to_log_in_and_come_back_to_the_invite(): void
    {
        $invite = $this->invite();
        $user = User::factory()->create(['password' => 'password']);

        $this->get("/invite/{$invite->code}")->assertRedirect('/login');
        $this->post("/invite/{$invite->code}")->assertRedirect('/login');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect("/invite/{$invite->code}");
    }

    public function test_unverified_users_must_verify_first(): void
    {
        $invite = $this->invite();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get("/invite/{$invite->code}")->assertRedirect('/email/verify');
        $this->actingAs($user)->post("/invite/{$invite->code}")->assertRedirect('/email/verify');

        $this->assertFalse($this->server->hasMember($user));
    }

    public function test_unknown_codes_are_not_found_and_codes_are_case_sensitive(): void
    {
        $this->invite(['code' => 'AbCdEfGh']);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/invite/nope1234')->assertNotFound();
        $this->actingAs($user)->get('/invite/abcdefgh')->assertNotFound();
        $this->actingAs($user)->post('/invite/abcdefgh')->assertNotFound();
        $this->actingAs($user)->get('/invite/AbCdEfGh')->assertOk();
    }

    public function test_the_preview_shows_the_server(): void
    {
        $invite = $this->invite();

        $this->actingAs(User::factory()->create())->get("/invite/{$invite->code}")
            ->assertOk()
            ->assertSee('Invite Test')
            ->assertSee('@'.$this->owner->username)
            ->assertSee('1 member')
            ->assertSee('Accept invite');

        $this->assertSame(0, $invite->fresh()?->uses, 'Opening the preview does not use the invite.');
    }

    public function test_accepting_joins_the_server_and_counts_a_use(): void
    {
        $invite = $this->invite(['max_uses' => 2]);
        $user = User::factory()->create();

        $this->actingAs($user)->post("/invite/{$invite->code}")
            ->assertRedirect("/servers/{$this->server->id}")
            ->assertSessionHas('status', 'Welcome to Invite Test!');

        $this->assertTrue($this->server->hasMember($user));
        $this->assertSame(1, $invite->fresh()?->uses);
        $this->actingAs($user)->get("/servers/{$this->server->id}")->assertOk();
    }

    public function test_members_are_not_charged_a_use(): void
    {
        $invite = $this->invite(['max_uses' => 1]);
        $member = $this->member();

        $this->actingAs($member)->get("/invite/{$invite->code}")
            ->assertOk()
            ->assertSee('already a member');

        $this->actingAs($member)->post("/invite/{$invite->code}")
            ->assertRedirect("/servers/{$this->server->id}")
            ->assertSessionHas('status', 'You are already a member of Invite Test.');

        $this->assertSame(0, $invite->fresh()?->uses);
        $this->assertSame(1, ServerMember::where('user_id', $member->id)->count());
    }

    public function test_members_can_still_open_an_expired_invite_to_their_server(): void
    {
        $invite = $this->invite(['expires_at' => now()->subDay()]);

        $this->actingAs($this->owner)->post("/invite/{$invite->code}")
            ->assertRedirect("/servers/{$this->server->id}");
    }

    public function test_expired_invites_are_refused(): void
    {
        $invite = $this->invite(['expires_at' => now()->subSecond()]);
        $user = User::factory()->create();

        $this->actingAs($user)->get("/invite/{$invite->code}")
            ->assertStatus(410)
            ->assertSee('This invite has expired.')
            ->assertSee('Ask someone in');
        $this->actingAs($user)->post("/invite/{$invite->code}")->assertStatus(410);

        $this->assertFalse($this->server->hasMember($user));
        $this->assertSame(0, $invite->fresh()?->uses);
    }

    public function test_an_invite_stops_working_at_its_expiry_time(): void
    {
        $this->freezeSecond();
        $invite = $this->invite(['expires_at' => now()->addMinute()]);
        $user = User::factory()->create();

        $this->travel(59)->seconds();
        $this->actingAs($user)->get("/invite/{$invite->code}")->assertOk();

        $this->travel(2)->seconds();
        $this->actingAs($user)->post("/invite/{$invite->code}")->assertStatus(410);
    }

    public function test_full_invites_are_refused(): void
    {
        $invite = $this->invite(['max_uses' => 1]);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)->post("/invite/{$invite->code}")->assertRedirect("/servers/{$this->server->id}");

        $this->actingAs($second)->get("/invite/{$invite->code}")
            ->assertStatus(410)
            ->assertSee('This invite has reached its maximum number of uses.');
        $this->actingAs($second)->post("/invite/{$invite->code}")->assertStatus(410);

        $this->assertFalse($this->server->hasMember($second));
        $this->assertSame(1, $invite->fresh()?->uses);
    }

    public function test_the_last_use_cannot_be_taken_twice(): void
    {
        $invite = $this->invite(['max_uses' => 1]);
        $first = User::factory()->create();
        $second = User::factory()->create();

        // Both requests loaded the invite while one use was still left.
        $staleCopy = Invite::findOrFail($invite->id);
        $this->assertTrue(app(AcceptInvite::class)->accept($invite, $first));

        try {
            app(AcceptInvite::class)->accept($staleCopy, $second);
            $this->fail('The second join should be refused.');
        } catch (InviteUnavailableException $e) {
            $this->assertSame(InviteUnavailableException::FULL, $e->reason);
        }

        $this->assertFalse($this->server->hasMember($second));
        $this->assertSame(1, $invite->fresh()?->uses);
    }

    public function test_banned_users_cannot_join(): void
    {
        $invite = $this->invite();
        $user = User::factory()->create();
        Ban::factory()->create(['server_id' => $this->server->id, 'user_id' => $user->id, 'banned_by_id' => $this->owner->id]);

        $this->actingAs($user)->get("/invite/{$invite->code}")
            ->assertForbidden()
            ->assertSee('You are banned from this server.')
            ->assertDontSee('Ask someone in');
        $this->actingAs($user)->post("/invite/{$invite->code}")->assertForbidden();

        $this->assertFalse($this->server->hasMember($user));
        $this->assertSame(0, $invite->fresh()?->uses);
    }

    public function test_a_ban_takes_precedence_over_an_expired_invite(): void
    {
        $invite = $this->invite(['expires_at' => now()->subDay()]);
        $user = User::factory()->create();
        Ban::factory()->create(['server_id' => $this->server->id, 'user_id' => $user->id]);

        $this->actingAs($user)->post("/invite/{$invite->code}")
            ->assertForbidden()
            ->assertSee('You are banned from this server.');
    }

    public function test_a_ban_on_another_server_does_not_matter(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Other');
        $invite = $this->invite();
        $user = User::factory()->create();
        Ban::factory()->create(['server_id' => $other->id, 'user_id' => $user->id]);

        $this->actingAs($user)->post("/invite/{$invite->code}")->assertRedirect("/servers/{$this->server->id}");

        $this->assertTrue($this->server->hasMember($user));
    }

    public function test_an_invite_whose_inviter_was_deleted_still_works(): void
    {
        $inviter = $this->member();
        $invite = $this->invite(['inviter_id' => $inviter->id]);
        $inviter->delete();
        $user = User::factory()->create();

        $this->actingAs($user)->get("/invite/{$invite->code}")
            ->assertOk()
            ->assertSee('You have been invited to join');
        $this->actingAs($user)->post("/invite/{$invite->code}")->assertRedirect("/servers/{$this->server->id}");
    }

    // --- Model helpers and policy ----------------------------------------

    public function test_model_helpers(): void
    {
        $this->assertTrue((new Invite)->isUsable());
        $this->assertTrue((new Invite(['expires_at' => now()->addMinute()]))->isUsable());
        $this->assertTrue((new Invite(['expires_at' => now()->subMinute()]))->isExpired());
        $this->assertTrue((new Invite(['max_uses' => 3, 'uses' => 3]))->isFull());
        $this->assertFalse((new Invite(['max_uses' => 3, 'uses' => 2]))->isFull());
        $this->assertFalse((new Invite(['max_uses' => null, 'uses' => 1000]))->isFull());
    }

    public function test_invite_abilities(): void
    {
        $member = $this->member();
        $stranger = User::factory()->create();

        $this->assertTrue($this->owner->can('createInvite', $this->server));
        $this->assertTrue($member->can('createInvite', $this->server));
        $this->assertFalse($stranger->can('createInvite', $this->server));

        $this->assertTrue($this->owner->can('manageInvites', $this->server));
        $this->assertFalse($member->can('manageInvites', $this->server));
    }
}
