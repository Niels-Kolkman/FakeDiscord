<?php

namespace Tests\Feature\Servers;

use App\Actions\Servers\AcceptInvite;
use App\Actions\Servers\CreateInvite;
use App\Actions\Servers\CreateServer;
use App\Exceptions\InviteUnavailableException;
use App\Http\Requests\StoreInviteRequest;
use App\Models\Ban;
use App\Models\Invite;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Edge cases for invites that InviteTest does not cover (every
 * option value, form defaults, list rendering, the code retry limit, revoke
 * edge cases, the exact expiry second, join races and rate limits).
 */
class InviteEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->server = app(CreateServer::class)->create($this->owner, 'Edge Server');
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

    // --- Options ---------------------------------------------------------

    /**
     * @return iterable<string, array{int}>
     */
    public static function expiryOptions(): iterable
    {
        foreach (array_keys(StoreInviteRequest::EXPIRY_OPTIONS) as $minutes) {
            yield "{$minutes} minutes" => [$minutes];
        }
    }

    #[DataProvider('expiryOptions')]
    public function test_every_expiry_option_is_stored_correctly(int $minutes): void
    {
        $this->freezeSecond();

        $this->actingAs($this->owner)
            ->post("/servers/{$this->server->id}/invites", ['expires_in' => $minutes, 'max_uses' => 0])
            ->assertSessionHasNoErrors();

        $invite = Invite::sole();
        if ($minutes === 0) {
            $this->assertNull($invite->expires_at);
        } else {
            $this->assertTrue(now()->addMinutes($minutes)->equalTo($invite->expires_at));
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function maxUsesOptions(): iterable
    {
        foreach (array_keys(StoreInviteRequest::MAX_USES_OPTIONS) as $uses) {
            yield "{$uses} uses" => [$uses];
        }
    }

    #[DataProvider('maxUsesOptions')]
    public function test_every_max_uses_option_is_stored_correctly(int $uses): void
    {
        $this->actingAs($this->owner)
            ->post("/servers/{$this->server->id}/invites", ['expires_in' => 0, 'max_uses' => $uses])
            ->assertSessionHasNoErrors();

        $this->assertSame($uses === 0 ? null : $uses, Invite::sole()->max_uses);
    }

    public function test_options_given_as_strings_are_accepted(): void
    {
        // Form posts send strings; "60" must match the integer key 60.
        $this->actingAs($this->owner)
            ->post("/servers/{$this->server->id}/invites", ['expires_in' => '60', 'max_uses' => '10'])
            ->assertSessionHasNoErrors();

        $this->assertSame(10, Invite::sole()->max_uses);
    }

    public function test_mass_assigned_fields_are_ignored_when_creating(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Other');

        $this->actingAs($this->owner)->post("/servers/{$this->server->id}/invites", [
            'expires_in' => 0,
            'max_uses' => 0,
            'code' => 'chosen12',
            'uses' => 99,
            'server_id' => $other->id,
            'inviter_id' => User::factory()->create()->id,
        ])->assertSessionHasNoErrors();

        $invite = Invite::sole();
        $this->assertNotSame('chosen12', $invite->code);
        $this->assertSame(0, $invite->uses);
        $this->assertSame($this->server->id, (int) $invite->server_id);
        $this->assertSame($this->owner->id, (int) $invite->inviter_id);
    }

    public function test_authorisation_is_checked_before_validation(): void
    {
        $this->actingAs(User::factory()->create())
            ->post("/servers/{$this->server->id}/invites", ['expires_in' => 'bad'])
            ->assertForbidden();
    }

    public function test_guests_cannot_reach_the_invite_management_routes(): void
    {
        $invite = $this->invite();

        $this->get("/servers/{$this->server->id}/invites")->assertRedirect('/login');
        $this->post("/servers/{$this->server->id}/invites", ['expires_in' => 0, 'max_uses' => 0])->assertRedirect('/login');
        $this->delete("/servers/{$this->server->id}/invites/{$invite->id}")->assertRedirect('/login');

        $this->assertSame(1, Invite::count());
    }

    // --- The invites page ------------------------------------------------

    public function test_the_form_preselects_seven_days_and_no_limit(): void
    {
        $this->actingAs($this->owner)->get("/servers/{$this->server->id}/invites")
            ->assertOk()
            ->assertSee('<option value="10080" selected>7 days</option>', false)
            ->assertSee('<option value="0" selected>No limit</option>', false)
            ->assertSee('No active invites.');
    }

    public function test_a_new_link_is_shown_after_creating_it(): void
    {
        $this->actingAs($this->owner)
            ->followingRedirects()
            ->post("/servers/{$this->server->id}/invites", ['expires_in' => 0, 'max_uses' => 0])
            ->assertOk()
            ->assertSee('Your invite link is ready.')
            ->assertSee(url('/invite/'.Invite::sole()->code));
    }

    public function test_the_list_shows_uses_expiry_and_inviter(): void
    {
        $this->freezeSecond();
        $gone = $this->member();
        $this->invite(['code' => 'limited1', 'max_uses' => 5, 'uses' => 2, 'expires_at' => now()->addDay()]);
        $this->invite(['code' => 'forever1', 'inviter_id' => $gone->id]);
        $gone->delete();

        $this->actingAs($this->owner)->get("/servers/{$this->server->id}/invites")
            ->assertOk()
            ->assertSee('Active invites')
            ->assertSee('2 / 5 uses')
            ->assertSee('expires 1 day from now')
            ->assertSee('never expires')
            ->assertSee('Unknown')
            ->assertSee('@'.$this->owner->username);
    }

    public function test_members_only_get_a_revoke_button_for_their_own_invites(): void
    {
        $member = $this->member();
        $this->invite(['inviter_id' => $member->id]);

        $this->actingAs($member)->get("/servers/{$this->server->id}/invites")
            ->assertOk()
            ->assertSee('Your active invites')
            ->assertSee('Revoke');

        $other = $this->member();
        $this->actingAs($other)->get("/servers/{$this->server->id}/invites")
            ->assertOk()
            ->assertDontSee('Revoke');
    }

    // --- Code generation -------------------------------------------------

    public function test_code_generation_gives_up_after_five_collisions(): void
    {
        $this->invite(['code' => 'taken123']);
        Str::createRandomStringsUsing(fn () => 'taken123');

        try {
            app(CreateInvite::class)->create($this->server, $this->owner, null, null);
            $this->fail('Expected the code generator to give up.');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not generate a unique invite code.', $e->getMessage());
        }

        $this->assertSame(1, Invite::count());
    }

    public function test_four_collisions_still_succeed(): void
    {
        $this->invite(['code' => 'taken123']);
        Str::createRandomStringsUsingSequence(['taken123', 'taken123', 'taken123', 'taken123', 'fifth567']);

        $this->assertSame('fifth567', app(CreateInvite::class)->create($this->server, $this->owner, null, null)->code);
    }

    // --- Revoking --------------------------------------------------------

    public function test_a_revoked_invite_no_longer_works(): void
    {
        $invite = $this->invite();

        $this->actingAs($this->owner)
            ->delete("/servers/{$this->server->id}/invites/{$invite->id}")
            ->assertSessionHas('status', 'The invite has been revoked.');

        $this->actingAs(User::factory()->create())->get("/invite/{$invite->code}")->assertNotFound();
    }

    public function test_the_owner_can_revoke_an_invite_from_a_member_who_left_or_was_deleted(): void
    {
        $left = $this->member();
        $deleted = $this->member();
        $first = $this->invite(['inviter_id' => $left->id]);
        $second = $this->invite(['inviter_id' => $deleted->id]);
        ServerMember::where('user_id', $left->id)->delete();
        $deleted->delete();

        $this->actingAs($this->owner)->delete("/servers/{$this->server->id}/invites/{$first->id}")->assertRedirect();
        $this->actingAs($this->owner)->delete("/servers/{$this->server->id}/invites/{$second->id}")->assertRedirect();

        $this->assertSame(0, Invite::count());
    }

    public function test_revoking_an_unknown_invite_is_not_found(): void
    {
        $this->actingAs($this->owner)->delete("/servers/{$this->server->id}/invites/999999")->assertNotFound();
    }

    public function test_strangers_cannot_revoke_even_with_the_right_server(): void
    {
        $invite = $this->invite();

        $this->actingAs(User::factory()->create())
            ->delete("/servers/{$this->server->id}/invites/{$invite->id}")
            ->assertForbidden();

        $this->assertModelExists($invite);
    }

    // --- Accepting -------------------------------------------------------

    public function test_accepting_is_refused_at_the_exact_expiry_second(): void
    {
        $this->freezeSecond();
        $invite = $this->invite(['expires_at' => now()]);
        $user = User::factory()->create();

        $this->actingAs($user)->post("/invite/{$invite->code}")
            ->assertStatus(410)
            ->assertSee('This invite has expired.');

        $this->assertFalse($this->server->hasMember($user));
        $this->assertSame(0, $invite->fresh()?->uses);
    }

    public function test_the_last_use_can_be_taken_and_then_the_invite_is_full(): void
    {
        $invite = $this->invite(['max_uses' => 5, 'uses' => 4]);

        $this->actingAs(User::factory()->create())->post("/invite/{$invite->code}")->assertRedirect("/servers/{$this->server->id}");
        $this->actingAs(User::factory()->create())->post("/invite/{$invite->code}")->assertStatus(410);

        $this->assertSame(5, $invite->fresh()?->uses);
    }

    public function test_unlimited_invites_keep_working(): void
    {
        $invite = $this->invite(['uses' => 1000]);

        $this->actingAs(User::factory()->create())->post("/invite/{$invite->code}")->assertRedirect("/servers/{$this->server->id}");

        $this->assertSame(1001, $invite->fresh()?->uses);
    }

    public function test_a_stale_copy_of_an_invite_that_has_since_expired_is_refused_as_expired(): void
    {
        $this->freezeSecond();
        $invite = $this->invite(['expires_at' => now()->addMinute()]);
        $stale = Invite::findOrFail($invite->id);
        Invite::whereKey($invite->id)->update(['expires_at' => now()->subMinute()]);

        try {
            app(AcceptInvite::class)->accept($stale, User::factory()->create());
            $this->fail('Expected the join to be refused.');
        } catch (InviteUnavailableException $e) {
            $this->assertSame(InviteUnavailableException::EXPIRED, $e->reason);
        }
    }

    public function test_a_parallel_join_gives_the_use_back(): void
    {
        $invite = $this->invite(['max_uses' => 1]);
        $user = User::factory()->create();

        // Another request adds the membership after our checks but before our insert.
        ServerMember::creating(function (ServerMember $membership) {
            DB::table('server_members')->insert([
                'server_id' => $membership->server_id,
                'user_id' => $membership->user_id,
                'joined_at' => now(),
            ]);
        });

        $this->assertFalse(app(AcceptInvite::class)->accept($invite, $user));
        $this->assertSame(0, $invite->fresh()?->uses, 'The rolled back join must not use up the invite.');
    }

    public function test_a_banned_member_is_refused_before_the_member_shortcut(): void
    {
        $member = $this->member();
        $invite = $this->invite();
        Ban::factory()->create(['server_id' => $this->server->id, 'user_id' => $member->id]);

        $this->actingAs($member)->get("/invite/{$invite->code}")
            ->assertForbidden()
            ->assertSee('You are banned from this server.');
    }

    public function test_the_full_page_suggests_asking_for_a_new_invite(): void
    {
        $invite = $this->invite(['max_uses' => 1, 'uses' => 1]);

        $this->actingAs(User::factory()->create())->get("/invite/{$invite->code}")
            ->assertStatus(410)
            ->assertSee('Ask someone in')
            ->assertSee('Edge Server');
    }

    public function test_the_preview_pluralises_the_member_count(): void
    {
        $this->member();
        $invite = $this->invite();

        $this->actingAs(User::factory()->create())->get("/invite/{$invite->code}")
            ->assertOk()
            ->assertSee('2 members');
    }

    public function test_codes_longer_than_the_column_are_not_found(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/invite/'.str_repeat('a', 200))
            ->assertNotFound();
    }

    // --- Rate limits -----------------------------------------------------

    public function test_creating_invites_is_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($this->owner)
                ->post("/servers/{$this->server->id}/invites", ['expires_in' => 0, 'max_uses' => 0])
                ->assertRedirect();
        }

        $this->actingAs($this->owner)
            ->post("/servers/{$this->server->id}/invites", ['expires_in' => 0, 'max_uses' => 0])
            ->assertTooManyRequests();

        $this->assertSame(20, Invite::count());
    }

    public function test_opening_invites_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user)->get('/invite/guess'.$i)->assertNotFound();
        }

        $this->actingAs($user)->get('/invite/guess30')->assertTooManyRequests();
    }

    // --- Model and exception ---------------------------------------------

    public function test_the_usable_scope_matches_is_usable(): void
    {
        $this->freezeSecond();
        $rows = [
            'open' => [],
            'future' => ['expires_at' => now()->addSecond()],
            'past' => ['expires_at' => now()->subSecond()],
            'oneleft' => ['max_uses' => 2, 'uses' => 1],
            'full' => ['max_uses' => 2, 'uses' => 2],
            'over' => ['max_uses' => 2, 'uses' => 3],
        ];
        foreach ($rows as $code => $attributes) {
            $this->invite(['code' => $code] + $attributes);
        }

        $usable = Invite::query()->usable()->pluck('code')->sort()->values()->all();
        $expected = Invite::all()->filter->isUsable()->pluck('code')->sort()->values()->all();

        $this->assertSame(['future', 'oneleft', 'open'], $usable);
        $this->assertSame($expected, $usable);
    }

    public function test_invite_urls_point_at_the_public_route(): void
    {
        $this->assertSame(url('/invite/AbC12345'), (new Invite(['code' => 'AbC12345']))->url());
    }

    public function test_exception_statuses_and_unknown_reasons(): void
    {
        $this->assertSame(403, (new InviteUnavailableException(InviteUnavailableException::BANNED))->status());
        $this->assertSame(410, (new InviteUnavailableException(InviteUnavailableException::EXPIRED))->status());
        $this->assertSame(410, (new InviteUnavailableException(InviteUnavailableException::FULL))->status());

        $unknown = new InviteUnavailableException('something-else');
        $this->assertSame('This invite is not valid.', $unknown->getMessage());
        $this->assertSame(410, $unknown->status());
    }
}
