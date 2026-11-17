<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\DocumentsKnownBugs;
use Tests\TestCase;

/**
 * ISSUE-9: changing the email address from account settings.
 */
class UpdateEmailTest extends TestCase
{
    use DocumentsKnownBugs;
    use RefreshDatabase;

    public function test_email_can_be_changed_and_must_be_verified_again(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/account/email', [
                'email' => 'New.Address@Example.test ',
                'current_password' => 'password',
            ])
            ->assertRedirect('/settings/account')
            ->assertSessionHas('status', 'email-updated')
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('new.address@example.test', $user->email);
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_wrong_password_is_rejected(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $original = $user->email;

        $this->actingAs($user)
            ->from('/settings/account')
            ->put('/settings/account/email', [
                'email' => 'new@example.test',
                'current_password' => 'wrong',
            ])
            ->assertRedirect('/settings/account')
            ->assertSessionHasErrorsIn('updateEmail', 'current_password');

        $this->assertSame($original, $user->fresh()->email);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Notification::assertNothingSent();
    }

    public function test_email_taken_by_another_user_is_rejected_case_insensitively(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/account/email', [
                'email' => 'TAKEN@example.test',
                'current_password' => 'password',
            ])
            ->assertSessionHasErrorsIn('updateEmail', 'email');
    }

    public function test_invalid_email_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/account/email', [
                'email' => 'not-an-email',
                'current_password' => 'password',
            ])
            ->assertSessionHasErrorsIn('updateEmail', 'email');
    }

    public function test_submitting_the_current_email_changes_nothing(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/account/email', [
                'email' => strtoupper($user->email),
                'current_password' => 'password',
            ])
            ->assertSessionHas('status', 'email-unchanged');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Notification::assertNothingSent();
    }

    public function test_other_fields_cannot_be_changed_through_this_form(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $originalName = $user->name;
        $originalUsername = $user->username;

        $this->actingAs($user)->put('/settings/account/email', [
            'email' => 'new@example.test',
            'current_password' => 'password',
            'name' => 'Hacked',
            'username' => 'hacked',
            'email_verified_at' => now()->toDateTimeString(),
        ]);

        $user->refresh();
        $this->assertSame('new@example.test', $user->email);
        $this->assertSame($originalName, $user->name);
        $this->assertSame($originalUsername, $user->username);
        $this->assertFalse($user->hasVerifiedEmail());
    }

    public function test_missing_current_password_is_rejected(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $original = $user->email;

        $this->actingAs($user)
            ->put('/settings/account/email', ['email' => 'new@example.test'])
            ->assertSessionHasErrorsIn('updateEmail', 'current_password');

        $this->assertSame($original, $user->fresh()->email);
        Notification::assertNothingSent();
    }

    public function test_missing_email_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/account/email', ['current_password' => 'password'])
            ->assertSessionHasErrorsIn('updateEmail', 'email');
    }

    public function test_email_longer_than_255_characters_is_rejected(): void
    {
        $user = User::factory()->create();
        $original = $user->email;

        $this->actingAs($user)
            ->put('/settings/account/email', [
                'email' => str_repeat('a', 244).'@example.test', // 257 chars
                'current_password' => 'password',
            ])
            ->assertSessionHasErrorsIn('updateEmail', 'email');

        $this->assertSame($original, $user->fresh()->email);
    }

    public function test_unverified_user_can_fix_a_mistyped_address(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'typo@exmaple.test']);

        $this->actingAs($user)
            ->put('/settings/account/email', [
                'email' => 'fixed@example.test',
                'current_password' => 'password',
            ])
            ->assertSessionHas('status', 'email-updated');

        $this->assertSame('fixed@example.test', $user->fresh()->email);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_user_logs_in_with_the_new_address_only(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.test']);

        $this->actingAs($user)->put('/settings/account/email', [
            'email' => 'new@example.test',
            'current_password' => 'password',
        ]);
        $this->post('/logout');

        $this->post('/login', ['email' => 'old@example.test', 'password' => 'password']);
        $this->assertGuest();

        $this->post('/login', ['email' => 'new@example.test', 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_error_is_shown_under_the_email_form_only(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/account')
            ->followingRedirects()
            ->put('/settings/account/email', [
                'email' => 'new@example.test',
                'current_password' => 'wrong',
            ])
            ->assertSee('id="email_current_password-error"', false)
            ->assertSee('The provided password does not match your current password.')
            ->assertDontSee('id="password_current_password-error"', false)
            ->assertDontSee('id="delete_password-error"', false)
            // The typed address is kept so the user does not have to retype it.
            ->assertSee('value="new@example.test"', false);
    }

    public function test_success_message_is_shown(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->followingRedirects()
            ->put('/settings/account/email', [
                'email' => 'new@example.test',
                'current_password' => 'password',
            ])
            ->assertOk()
            ->assertSee('new@example.test')
            ->assertSee('not verified');
    }

    public function test_email_changes_are_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->put('/settings/account/email', [
                'email' => 'new@example.test',
                'current_password' => 'wrong',
            ])->assertRedirect();
        }

        $this->actingAs($user)->put('/settings/account/email', [
            'email' => 'new@example.test',
            'current_password' => 'password',
        ])->assertTooManyRequests();
    }

    public function test_non_string_current_password_is_a_validation_error_not_a_crash(): void
    {
        $user = User::factory()->create();
        $original = $user->email;

        $this->knownBug('array current_password reaches current_password rule and password_verify() throws a TypeError (500); the rule list needs `bail`.', function () use ($user) {
            $this->actingAs($user)
                ->from('/settings/account')
                ->put('/settings/account/email', [
                    'email' => 'new@example.test',
                    'current_password' => ['password'],
                ])
                ->assertRedirect('/settings/account')
                ->assertSessionHasErrorsIn('updateEmail', 'current_password');
        });

        $this->assertSame($original, $user->fresh()->email);
    }
}
