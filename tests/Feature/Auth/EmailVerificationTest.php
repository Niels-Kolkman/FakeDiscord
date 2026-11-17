<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * ISSUE-7: users must verify their email address before joining servers.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function verificationUrl(User $user, ?string $hash = null, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
            'id' => $user->getKey(),
            'hash' => $hash ?? sha1($user->getEmailForVerification()),
        ]);
    }

    /**
     * Stand-in for the server-join routes (ISSUE-11), which will use the same
     * `auth` + `verified` middleware as the group in routes/web.php.
     */
    private function registerVerifiedOnlyRoute(): void
    {
        Route::middleware(['web', 'auth', 'verified'])
            ->post('/_test/servers/join', fn () => response('joined'));
    }

    public function test_the_user_model_must_verify_email(): void
    {
        $this->assertInstanceOf(MustVerifyEmail::class, new User);
    }

    public function test_registering_sends_a_verification_email(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Niels',
            'username' => 'niels',
            'email' => 'niels@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect('/dashboard');

        $user = User::where('username', 'niels')->sole();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_the_verification_email_links_to_a_working_signed_url(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;
            $this->actingAs($user)->get($url)->assertRedirect('/dashboard?verified=1');

            return true;
        });
        $this->assertTrue($user->fresh()?->hasVerifiedEmail());
    }

    public function test_the_verification_notice_renders_for_unverified_users(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'pending@example.test']);

        $this->actingAs($user)
            ->get('/email/verify')
            ->assertOk()
            ->assertViewIs('auth.verify-email')
            ->assertSee('pending@example.test')
            ->assertSee('action="'.route('verification.send').'"', false);
    }

    public function test_verified_users_are_sent_on_from_the_notice(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/email/verify')
            ->assertRedirect('/dashboard');
    }

    public function test_guests_cannot_see_the_notice(): void
    {
        $this->get('/email/verify')->assertRedirect('/login');
    }

    public function test_a_valid_link_verifies_the_email(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get($this->verificationUrl($user))
            ->assertRedirect('/dashboard?verified=1');

        $this->assertTrue($user->fresh()?->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_the_dashboard_confirms_verification(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard?verified=1')
            ->assertOk()
            ->assertSee('Your email address is verified.');
    }

    public function test_a_link_with_the_wrong_hash_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get($this->verificationUrl($user, sha1('someone-else@example.test')))
            ->assertForbidden();

        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    public function test_an_unsigned_link_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))
            ->assertForbidden();

        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    public function test_an_expired_link_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->verificationUrl($user, minutes: 60);

        $this->travel(61)->minutes();

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    public function test_a_user_cannot_verify_with_another_users_link(): void
    {
        $victim = User::factory()->unverified()->create();
        $attacker = User::factory()->unverified()->create();

        $this->actingAs($attacker)
            ->get($this->verificationUrl($victim))
            ->assertForbidden();

        $this->assertFalse($victim->fresh()?->hasVerifiedEmail());
        $this->assertFalse($attacker->fresh()?->hasVerifiedEmail());
    }

    public function test_the_verification_link_requires_login(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->verificationUrl($user))->assertRedirect('/login');
        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    public function test_unverified_users_can_resend_the_email(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->from('/email/verify')
            ->post('/email/verification-notification')
            ->assertRedirect('/email/verify');

        Notification::assertSentTo($user, VerifyEmail::class);

        $this->get('/email/verify')
            ->assertSee('A new verification link has been sent to your email address.')
            ->assertDontSee('verification-link-sent');
    }

    public function test_verified_users_do_not_get_another_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/email/verification-notification')
            ->assertRedirect('/dashboard');

        Notification::assertNothingSent();
    }

    public function test_resending_is_rate_limited(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post('/email/verification-notification')->assertRedirect();
        }

        $this->actingAs($user)->post('/email/verification-notification')->assertTooManyRequests();
        Notification::assertSentToTimes($user, VerifyEmail::class, 6);
    }

    public function test_the_dashboard_warns_unverified_users(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Verify your email address before you join servers.')
            ->assertSee(route('verification.notice'));
    }

    public function test_the_dashboard_does_not_warn_verified_users(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Verify your email address before you join servers.');
    }

    public function test_unverified_users_cannot_use_verified_only_routes(): void
    {
        $this->registerVerifiedOnlyRoute();

        $this->actingAs(User::factory()->unverified()->create())
            ->post('/_test/servers/join')
            ->assertRedirect('/email/verify');
    }

    public function test_unverified_users_get_403_on_json_requests_to_verified_only_routes(): void
    {
        $this->registerVerifiedOnlyRoute();

        $this->actingAs(User::factory()->unverified()->create())
            ->postJson('/_test/servers/join')
            ->assertForbidden();
    }

    public function test_verified_users_can_use_verified_only_routes(): void
    {
        $this->registerVerifiedOnlyRoute();

        $this->actingAs(User::factory()->create())
            ->post('/_test/servers/join')
            ->assertOk()
            ->assertSee('joined');
    }
}
