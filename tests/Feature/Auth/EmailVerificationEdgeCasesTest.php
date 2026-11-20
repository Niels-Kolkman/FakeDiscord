<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Edge cases around email verification that the main
 * EmailVerificationTest does not cover (replayed/tampered links, changed
 * addresses, throttling, real mail rendering and local mail setup).
 */
class EmailVerificationEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function verificationUrl(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }

    private function emailedLink(User $user): string
    {
        Notification::fake();
        $user->sendEmailVerificationNotification();

        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $n) use ($user, &$url) {
            $url = $n->toMail($user)->actionUrl;

            return true;
        });
        $this->assertIsString($url);

        return $url;
    }

    public function test_reopening_a_link_when_already_verified_does_not_reverify(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()->subDay()]);
        $verifiedAt = $user->fresh()?->getRawOriginal('email_verified_at');
        $this->assertNotNull($verifiedAt);
        Event::fake([Verified::class]);

        $this->actingAs($user)
            ->get($this->verificationUrl($user))
            ->assertRedirect('/dashboard?verified=1');

        Event::assertNotDispatched(Verified::class);
        $this->assertSame($verifiedAt, $user->fresh()?->getRawOriginal('email_verified_at'));
    }

    public function test_a_tampered_signature_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get($this->verificationUrl($user).'0')->assertForbidden();
        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    public function test_a_signed_link_for_a_missing_user_id_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->getKey() + 999,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    public function test_changing_the_email_invalidates_an_old_link(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'old@example.test']);
        $oldLink = $this->verificationUrl($user);

        $user->forceFill(['email' => 'new@example.test'])->save();

        $this->actingAs($user)->get($oldLink)->assertForbidden();
        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    public function test_guests_cannot_request_a_new_verification_email(): void
    {
        Notification::fake();

        $this->post('/email/verification-notification')->assertRedirect('/login');

        Notification::assertNothingSent();
    }

    public function test_opening_verification_links_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $url = $this->verificationUrl($user);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->get($url)->assertRedirect();
        }

        $this->actingAs($user)->get($url)->assertTooManyRequests();
    }

    public function test_unverified_users_can_still_log_in(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'secret-password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
    }

    public function test_the_notice_offers_a_logout_button(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/email/verify')
            ->assertOk()
            ->assertSee('action="'.route('logout').'"', false);
    }

    public function test_the_verification_email_is_rendered_and_sent_through_the_mailer(): void
    {
        config(['mail.default' => 'array']);
        $user = User::factory()->unverified()->create(['email' => 'mailbox@example.test']);

        $user->sendEmailVerificationNotification();

        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $messages = $transport->messages();
        $this->assertCount(1, $messages);

        $email = $messages->first()?->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('mailbox@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('Verify Email Address', $email->getSubject());
        $this->assertStringContainsString(
            '/email/verify/'.$user->getKey().'/'.sha1('mailbox@example.test'),
            (string) $email->getHtmlBody(),
        );
    }

    public function test_the_emailed_link_expires_after_60_minutes(): void
    {
        $this->assertSame(60, config('auth.verification.expire', 60));
        $user = User::factory()->unverified()->create();
        $url = $this->emailedLink($user);

        $this->travel(61)->minutes();

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    public function test_the_emailed_link_still_works_just_before_expiry(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->emailedLink($user);

        $this->travel(59)->minutes();

        $this->actingAs($user)->get($url)->assertRedirect('/dashboard?verified=1');
        $this->assertTrue($user->fresh()?->hasVerifiedEmail());
    }

    public function test_seeded_demo_users_are_verified_and_get_no_emails(): void
    {
        Notification::fake();

        $this->seed(DemoSeeder::class);

        $this->assertTrue(User::query()->exists());
        $this->assertFalse(User::query()->whereNull('email_verified_at')->exists());
        Notification::assertNothingSent();
    }

    public function test_env_example_uses_the_log_mailer_and_documents_mailpit(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^MAIL_MAILER=log\r?$/m', $env);
        $this->assertStringContainsString('Mailpit', $env);
        $this->assertStringContainsString('1025', $env);
    }
}
