<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ISSUE-8: the <x-user-avatar> component that renders avatars and opens the profile popover.
 */
class UserAvatarComponentTest extends TestCase
{
    use RefreshDatabase;

    private function render(User $user, string $attributes = ''): string
    {
        return Blade::render('<x-user-avatar :user="$user" '.$attributes.' />', ['user' => $user]);
    }

    public function test_by_default_it_is_a_button_that_opens_the_popover(): void
    {
        $user = User::factory()->create(['name' => 'Niels', 'username' => 'niels']);

        $html = $this->render($user);

        $this->assertStringContainsString('<button', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('data-profile-url="'.route('users.profile', $user).'"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('aria-label="View profile of Niels"', $html);
        $this->assertStringContainsString('width="40" height="40"', $html);
    }

    public function test_without_popover_it_renders_a_plain_image(): void
    {
        $user = User::factory()->create(['name' => 'Niels']);

        $html = $this->render($user, ':popover="false" :size="80"');

        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('data-profile-url', $html);
        $this->assertStringContainsString('alt="Niels"', $html);
        $this->assertStringContainsString('width="80" height="80"', $html);
        $this->assertStringContainsString('src="'.$user->avatarUrl().'"', $html);
    }

    public function test_extra_classes_are_merged(): void
    {
        $user = User::factory()->create();

        $this->assertStringContainsString('my-extra', $this->render($user, 'class="my-extra"'));
        $this->assertStringContainsString('my-extra', $this->render($user, ':popover="false" class="my-extra"'));
    }

    public function test_the_size_is_forced_to_an_integer(): void
    {
        $user = User::factory()->create();

        $html = $this->render($user, ':size="\'32px\'"');

        $this->assertStringContainsString('width="32" height="32"', $html);
        $this->assertStringContainsString('width: 32px; height: 32px', $html);
    }

    public function test_the_users_name_is_escaped(): void
    {
        $user = User::factory()->create(['name' => '"><img src=x onerror=alert(1)>']);

        $html = $this->render($user).$this->render($user, ':popover="false"');

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;img', $html);
    }

    public function test_it_uses_the_uploaded_avatar_when_there_is_one(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['avatar_path' => 'avatars/3/me.png']);

        $this->assertStringContainsString('src="'.Storage::disk('public')->url('avatars/3/me.png').'"', $this->render($user));
    }
}
