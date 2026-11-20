<?php

namespace Tests\Feature\Profile;

use App\Actions\Profile\UpdateUserAvatar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Users edit their display name, bio and avatar.
 */
class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Niels', 'bio' => 'Hello there'], $overrides);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function storedImageInfo(User $user): array
    {
        $this->assertNotNull($user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);

        $info = getimagesizefromstring((string) Storage::disk('public')->get($user->avatar_path));
        $this->assertIsArray($info);

        return $info;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings/profile')->assertRedirect('/login');
        $this->patch('/settings/profile', $this->payload())->assertRedirect('/login');
    }

    public function test_the_profile_page_shows_the_current_values(): void
    {
        $user = User::factory()->create(['name' => 'Niels K', 'bio' => 'Building FakeDiscord']);

        $this->actingAs($user)
            ->get('/settings/profile')
            ->assertOk()
            ->assertViewIs('profile.edit')
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="avatar"', false)
            ->assertSee('value="Niels K"', false)
            ->assertSee('Building FakeDiscord')
            // No uploaded avatar yet, so there is nothing to remove.
            ->assertDontSee('name="remove_avatar"', false);
    }

    public function test_display_name_and_bio_can_be_updated(): void
    {
        $user = User::factory()->create(['name' => 'Old']);

        $this->actingAs($user)
            ->patch('/settings/profile', $this->payload(['name' => '  New Name  ', 'bio' => "Line one\r\nLine two"]))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile')
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame("Line one\nLine two", $user->bio);
        $this->assertNull($user->avatar_path);
    }

    public function test_an_empty_bio_is_stored_as_null(): void
    {
        $user = User::factory()->create(['bio' => 'Something']);

        $this->actingAs($user)->patch('/settings/profile', $this->payload(['bio' => '   ']));

        $this->assertNull($user->refresh()->bio);
    }

    public function test_updating_the_profile_does_not_touch_username_or_email(): void
    {
        $user = User::factory()->create(['username' => 'niels', 'email' => 'niels@example.test']);

        $this->actingAs($user)->patch('/settings/profile', $this->payload([
            'username' => 'hacker',
            'email' => 'hacker@example.test',
            'avatar_path' => 'avatars/other/file.png',
        ]));

        $user->refresh();
        $this->assertSame('niels', $user->username);
        $this->assertSame('niels@example.test', $user->email);
        $this->assertNull($user->avatar_path);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidTextProvider(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'whitespace name' => [['name' => '   '], 'name'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'bio too long' => [['bio' => str_repeat('a', 191)], 'bio'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidTextProvider')]
    public function test_invalid_text_fields_are_rejected(array $overrides, string $field): void
    {
        $user = User::factory()->create(['name' => 'Original']);

        $this->actingAs($user)
            ->from('/settings/profile')
            ->patch('/settings/profile', $this->payload($overrides))
            ->assertRedirect('/settings/profile')
            ->assertSessionHasErrors($field);

        $this->assertSame('Original', $user->refresh()->name);
    }

    public function test_a_bio_of_exactly_190_characters_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', $this->payload(['bio' => str_repeat('é', 190)]))
            ->assertSessionHasNoErrors();

        $this->assertSame(190, mb_strlen((string) $user->refresh()->bio));
    }

    public function test_an_avatar_is_stored_on_the_public_disk_as_a_256px_png(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', $this->payload(['avatar' => UploadedFile::fake()->image('me.jpg', 800, 800)]))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertStringStartsWith("avatars/{$user->id}/", (string) $user->avatar_path);
        $this->assertStringEndsWith('.png', (string) $user->avatar_path);

        $info = $this->storedImageInfo($user);
        $this->assertSame([256, 256, 'image/png'], [$info[0], $info[1], $info['mime']]);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function imageShapeProvider(): array
    {
        return [
            'landscape png' => ['wide.png', 1200, 400],
            'portrait gif' => ['tall.gif', 300, 900],
            'tiny jpeg is upscaled' => ['tiny.jpg', 16, 16],
            'webp' => ['photo.webp', 512, 512],
        ];
    }

    #[DataProvider('imageShapeProvider')]
    public function test_any_accepted_image_is_cropped_and_resized_to_256_square(string $name, int $width, int $height): void
    {
        if (str_ends_with($name, '.webp') && ! function_exists('imagewebp')) {
            $this->markTestSkipped('GD was built without WebP support.');
        }

        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', $this->payload(['avatar' => UploadedFile::fake()->image($name, $width, $height)]))
            ->assertSessionHasNoErrors();

        $info = $this->storedImageInfo($user->refresh());
        $this->assertSame([256, 256], [$info[0], $info[1]]);
    }

    public function test_replacing_an_avatar_deletes_the_previous_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/profile', $this->payload(['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]));
        $first = (string) $user->refresh()->avatar_path;

        $this->actingAs($user)->patch('/settings/profile', $this->payload(['avatar' => UploadedFile::fake()->image('b.png', 300, 300)]));
        $second = (string) $user->refresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_the_avatar_can_be_removed_to_fall_back_to_the_generated_one(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch('/settings/profile', $this->payload(['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]));
        $path = (string) $user->refresh()->avatar_path;

        $this->actingAs($user)->get('/settings/profile')->assertSee('name="remove_avatar"', false);

        $this->actingAs($user)
            ->patch('/settings/profile', $this->payload(['remove_avatar' => '1']))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertNull($user->avatar_path);
        Storage::disk('public')->assertMissing($path);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $user->avatarUrl());
    }

    public function test_a_new_upload_wins_over_the_remove_checkbox(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/profile', $this->payload([
            'remove_avatar' => '1',
            'avatar' => UploadedFile::fake()->image('a.png', 300, 300),
        ]));

        $this->assertNotNull($user->refresh()->avatar_path);
    }

    public function test_saving_without_a_file_keeps_the_existing_avatar(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch('/settings/profile', $this->payload(['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]));
        $path = $user->refresh()->avatar_path;

        $this->actingAs($user)->patch('/settings/profile', $this->payload(['name' => 'Renamed']));

        $this->assertSame($path, $user->refresh()->avatar_path);
        Storage::disk('public')->assertExists((string) $path);
    }

    /**
     * @return array<string, array{0: callable(): (UploadedFile|string)}>
     */
    public static function invalidAvatarProvider(): array
    {
        return [
            'pdf' => [fn () => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')],
            'svg' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            'text renamed to png' => [fn () => UploadedFile::fake()->createWithContent('fake.png', 'definitely not an image')],
            'over 4 MB' => [fn () => UploadedFile::fake()->image('huge.jpg', 100, 100)->size(4097)],
            'wider than 4096px' => [fn () => UploadedFile::fake()->image('panorama.png', 4097, 10)],
            'not a file' => [fn () => 'just-a-string'],
        ];
    }

    /**
     * @param  callable(): (UploadedFile|string)  $makeFile
     */
    #[DataProvider('invalidAvatarProvider')]
    public function test_invalid_avatars_are_rejected(callable $makeFile): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/profile')
            ->patch('/settings/profile', $this->payload(['avatar' => $makeFile()]))
            ->assertRedirect('/settings/profile')
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_action_rejects_files_gd_cannot_decode(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('broken.png', "\x89PNG\r\n\x1a\n garbage");

        try {
            (new UpdateUserAvatar)->update($user, $file);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('avatar', $e->errors());
        }

        $this->assertNull($user->refresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_avatar_disk_is_configurable(): void
    {
        Storage::fake('avatars');
        config()->set('filesystems.avatar_disk', 'avatars');
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/profile', $this->payload(['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]));

        Storage::disk('avatars')->assertExists((string) $user->refresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_success_message_is_shown_after_saving(): void
    {
        $this->actingAs(User::factory()->create())
            ->followingRedirects()
            ->patch('/settings/profile', $this->payload())
            ->assertOk()
            ->assertSee('role="status"', false)
            ->assertSee('Your profile has been updated.');
    }

    public function test_validation_errors_are_shown_and_input_is_kept(): void
    {
        $user = User::factory()->create(['bio' => 'Old bio']);

        $this->actingAs($user)
            ->from('/settings/profile')
            ->followingRedirects()
            ->patch('/settings/profile', $this->payload(['name' => 'Kept Name', 'bio' => str_repeat('b', 191)]))
            ->assertOk()
            ->assertSee('id="bio-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('value="Kept Name"', false)
            ->assertSee(str_repeat('b', 191));

        $this->assertSame('Old bio', $user->refresh()->bio);
    }

    public function test_the_profile_page_escapes_user_supplied_text(): void
    {
        $user = User::factory()->create([
            'name' => '"><script>alert(1)</script>',
            'bio' => '</textarea><script>alert(2)</script>',
        ]);

        $this->actingAs($user)
            ->get('/settings/profile')
            ->assertOk()
            ->assertDontSee('<script>alert', false)
            ->assertSee('&lt;script&gt;alert(1)', false)
            ->assertSee('&lt;/textarea&gt;', false);
    }

    public function test_the_profile_page_shows_the_uploaded_avatar(): void
    {
        $user = User::factory()->create(['avatar_path' => 'avatars/1/abc.png']);

        $this->actingAs($user)
            ->get('/settings/profile')
            ->assertSee('src="'.Storage::disk('public')->url('avatars/1/abc.png').'"', false)
            ->assertSee('name="remove_avatar"', false);
    }

    public function test_an_invalid_text_field_prevents_the_avatar_from_being_stored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', $this->payload(['name' => '', 'avatar' => UploadedFile::fake()->image('a.png', 300, 300)]))
            ->assertSessionHasErrors('name');

        $this->assertNull($user->refresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_removing_when_there_is_no_avatar_is_harmless(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', $this->payload(['remove_avatar' => '1']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_an_unchecked_remove_box_keeps_the_avatar(): void
    {
        $user = User::factory()->create(['avatar_path' => 'avatars/1/abc.png']);
        Storage::disk('public')->put('avatars/1/abc.png', 'x');

        $this->actingAs($user)
            ->patch('/settings/profile', $this->payload(['remove_avatar' => '0']))
            ->assertSessionHasNoErrors();

        $this->assertSame('avatars/1/abc.png', $user->refresh()->avatar_path);
        Storage::disk('public')->assertExists('avatars/1/abc.png');
    }

    public function test_a_non_boolean_remove_flag_is_rejected(): void
    {
        $user = User::factory()->create(['avatar_path' => 'avatars/1/abc.png']);

        $this->actingAs($user)
            ->patch('/settings/profile', $this->payload(['remove_avatar' => 'please']))
            ->assertSessionHasErrors('remove_avatar');

        $this->assertSame('avatars/1/abc.png', $user->refresh()->avatar_path);
    }

    public function test_an_avatar_of_exactly_4_mb_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', $this->payload(['avatar' => UploadedFile::fake()->image('big.jpg', 100, 100)->size(4096)]))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($user->refresh()->avatar_path);
    }

    public function test_an_image_taller_than_4096px_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', $this->payload(['avatar' => UploadedFile::fake()->image('tall.png', 10, 4097)]))
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_a_name_of_exactly_255_characters_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', $this->payload(['name' => str_repeat('n', 255)]))
            ->assertSessionHasNoErrors();

        $this->assertSame(255, strlen($user->refresh()->name));
    }

    public function test_a_missing_bio_field_clears_the_bio(): void
    {
        $user = User::factory()->create(['bio' => 'Something']);

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => 'Niels'])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->refresh()->bio);
    }

    public function test_avatar_url_points_at_the_disk_for_uploaded_avatars(): void
    {
        $user = User::factory()->create(['avatar_path' => 'avatars/1/abc.png']);

        $this->assertSame(Storage::disk('public')->url('avatars/1/abc.png'), $user->avatarUrl());
    }
}
