<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ISSUE-9: changing the email address from account settings.
 */
class UpdateEmailTest extends TestCase
{
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
}
