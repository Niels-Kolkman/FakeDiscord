<?php

namespace Tests\Feature\Profile;

use App\Actions\Profile\UpdateUserAvatar;
use App\Models\User;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ISSUE-8: pixel-level checks of the avatar crop/resize and the file
 * lifecycle in UpdateUserAvatar, beyond "the output is 256x256".
 */
class AvatarProcessingTest extends TestCase
{
    use RefreshDatabase;

    private const RED = [255, 0, 0];

    private const GREEN = [0, 255, 0];

    private const BLUE = [0, 0, 255];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * Builds an image of the given size from solid rectangles.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4: array{0: int, 1: int, 2: int}}>  $rects  x1, y1, x2, y2, rgb
     */
    private function canvas(int $width, int $height, array $rects): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        foreach ($rects as [$x1, $y1, $x2, $y2, [$r, $g, $b]]) {
            imagefilledrectangle($image, $x1, $y1, $x2, $y2, (int) imagecolorallocate($image, $r, $g, $b));
        }

        return $image;
    }

    private function encode(GdImage $image, string $format): string
    {
        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }

    private function storeFor(User $user, string $name, string $bytes): GdImage
    {
        (new UpdateUserAvatar)->update($user, UploadedFile::fake()->createWithContent($name, $bytes));

        $path = (string) $user->refresh()->avatar_path;
        $stored = imagecreatefromstring((string) Storage::disk('public')->get($path));
        $this->assertInstanceOf(GdImage::class, $stored);
        $this->assertSame([256, 256], [imagesx($stored), imagesy($stored)]);

        return $stored;
    }

    /**
     * @return array{red: int, green: int, blue: int, alpha: int}
     */
    private function pixel(GdImage $image, int $x, int $y): array
    {
        /** @var array{red: int, green: int, blue: int, alpha: int} */
        return imagecolorsforindex($image, imagecolorat($image, $x, $y));
    }

    /**
     * Asserts the pixel is roughly the given colour, tolerating JPEG noise.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private function assertColourAt(GdImage $image, int $x, int $y, array $rgb): void
    {
        $p = $this->pixel($image, $x, $y);
        foreach (['red', 'green', 'blue'] as $i => $channel) {
            $this->assertEqualsWithDelta($rgb[$i], $p[$channel], 40, "Channel {$channel} at ({$x},{$y}) was ".json_encode($p));
        }
    }

    public function test_landscape_images_are_cropped_to_the_horizontal_centre(): void
    {
        // Three vertical stripes: only the middle one may survive the crop.
        $image = $this->canvas(300, 100, [
            [0, 0, 99, 99, self::RED],
            [100, 0, 199, 99, self::GREEN],
            [200, 0, 299, 99, self::BLUE],
        ]);

        $stored = $this->storeFor(User::factory()->create(), 'wide.png', $this->encode($image, 'png'));

        foreach ([[2, 128], [128, 128], [253, 128], [128, 2], [128, 253]] as [$x, $y]) {
            $this->assertColourAt($stored, $x, $y, self::GREEN);
        }
    }

    public function test_portrait_images_are_cropped_to_the_vertical_centre(): void
    {
        $image = $this->canvas(100, 300, [
            [0, 0, 99, 99, self::RED],
            [0, 100, 99, 199, self::GREEN],
            [0, 200, 99, 299, self::BLUE],
        ]);

        $stored = $this->storeFor(User::factory()->create(), 'tall.png', $this->encode($image, 'png'));

        foreach ([[128, 2], [128, 128], [128, 253], [2, 128], [253, 128]] as [$x, $y]) {
            $this->assertColourAt($stored, $x, $y, self::GREEN);
        }
    }

    public function test_square_images_are_scaled_without_cropping(): void
    {
        // Quadrants must stay in their corners.
        $image = $this->canvas(64, 64, [
            [0, 0, 31, 31, self::RED],
            [32, 0, 63, 31, self::GREEN],
            [0, 32, 31, 63, self::BLUE],
        ]);

        $stored = $this->storeFor(User::factory()->create(), 'square.png', $this->encode($image, 'png'));

        $this->assertColourAt($stored, 10, 10, self::RED);
        $this->assertColourAt($stored, 245, 10, self::GREEN);
        $this->assertColourAt($stored, 10, 245, self::BLUE);
        $this->assertColourAt($stored, 245, 245, [0, 0, 0]);
    }

    public function test_png_transparency_is_preserved(): void
    {
        $image = imagecreatetruecolor(100, 100);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 40, 40, 59, 59, (int) imagecolorallocatealpha($image, 255, 0, 0, 0));

        $stored = $this->storeFor(User::factory()->create(), 'logo.png', $this->encode($image, 'png'));

        $this->assertSame(127, $this->pixel($stored, 5, 5)['alpha'], 'Transparent corners must stay transparent.');
        $this->assertSame(0, $this->pixel($stored, 128, 128)['alpha']);
        $this->assertColourAt($stored, 128, 128, self::RED);
    }

    /**
     * Inserts an EXIF APP1 segment carrying only the Orientation tag right after the JPEG SOI marker.
     */
    private function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08"    // big-endian TIFF header, IFD0 at offset 8
            ."\x00\x01"                          // one entry
            ."\x01\x12\x00\x03\x00\x00\x00\x01" // tag 0x0112 Orientation, SHORT, count 1
            .pack('n', $orientation)."\x00\x00"
            ."\x00\x00\x00\x00";                 // no next IFD
        $payload = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    /**
     * Source is 200x100 with a red left half and blue right half.
     *
     * @return array<string, array{0: int, 1: array<string, array{0: int, 1: int, 2: int}>}>
     */
    public static function orientationProvider(): array
    {
        return [
            '1 (upright)' => [1, ['left' => self::RED, 'right' => self::BLUE]],
            '3 (rotate 180)' => [3, ['left' => self::BLUE, 'right' => self::RED]],
            '6 (rotate 90 CW)' => [6, ['top' => self::RED, 'bottom' => self::BLUE]],
            '8 (rotate 90 CCW)' => [8, ['top' => self::BLUE, 'bottom' => self::RED]],
        ];
    }

    /**
     * @param  array<string, array{0: int, 1: int, 2: int}>  $expected
     */
    #[DataProvider('orientationProvider')]
    public function test_jpeg_exif_orientation_is_applied_before_cropping(int $orientation, array $expected): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('The exif extension is not installed.');
        }

        $jpeg = $this->encode($this->canvas(200, 100, [
            [0, 0, 99, 99, self::RED],
            [100, 0, 199, 99, self::BLUE],
        ]), 'jpeg');
        $bytes = $this->withExifOrientation($jpeg, $orientation);
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $this->assertSame($orientation, is_array($exif) ? ($exif['Orientation'] ?? null) : null, 'Fixture EXIF is unreadable.');

        $stored = $this->storeFor(User::factory()->create(), 'phone.jpg', $bytes);

        $points = ['left' => [30, 128], 'right' => [226, 128], 'top' => [128, 30], 'bottom' => [128, 226]];
        foreach ($expected as $where => $rgb) {
            $this->assertColourAt($stored, $points[$where][0], $points[$where][1], $rgb);
        }
    }

    public function test_a_failed_upload_keeps_the_previous_avatar(): void
    {
        $user = User::factory()->create();
        $avatars = new UpdateUserAvatar;
        $avatars->update($user, UploadedFile::fake()->image('ok.png', 50, 50));
        $previous = (string) $user->refresh()->avatar_path;

        try {
            $avatars->update($user, UploadedFile::fake()->createWithContent('broken.jpg', "\xFF\xD8\xFF garbage"));
            $this->fail('Expected a validation exception.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($previous, $user->refresh()->avatar_path);
        Storage::disk('public')->assertExists($previous);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_an_empty_file_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        (new UpdateUserAvatar)->update($user, UploadedFile::fake()->createWithContent('empty.png', ''));
    }

    public function test_each_upload_gets_a_new_unguessable_path_in_the_users_folder(): void
    {
        $user = User::factory()->create();
        $avatars = new UpdateUserAvatar;

        $paths = [];
        foreach (range(1, 3) as $i) {
            $avatars->update($user, UploadedFile::fake()->image("me{$i}.png", 20, 20));
            $paths[] = (string) $user->refresh()->avatar_path;
        }

        $this->assertCount(3, array_unique($paths));
        foreach ($paths as $path) {
            $this->assertMatchesRegularExpression('#^avatars/'.$user->id.'/[A-Za-z0-9]{40}\.png$#', $path);
        }
        // Only the latest file is kept.
        $this->assertSame([end($paths)], Storage::disk('public')->allFiles());
    }

    public function test_uploads_and_removals_do_not_touch_other_users_avatars(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $avatars = new UpdateUserAvatar;

        $avatars->update($alice, UploadedFile::fake()->image('a.png', 20, 20));
        $avatars->update($bob, UploadedFile::fake()->image('b.png', 20, 20));
        $avatars->update($bob, UploadedFile::fake()->image('b2.png', 20, 20));
        $avatars->remove($bob);

        Storage::disk('public')->assertExists((string) $alice->refresh()->avatar_path);
        $this->assertSame([(string) $alice->avatar_path], Storage::disk('public')->allFiles());
    }

    public function test_removing_without_an_avatar_is_a_no_op(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);
        $updatedAt = $user->updated_at;
        $this->travel(5)->minutes();

        (new UpdateUserAvatar)->remove($user);

        $this->assertNull($user->refresh()->avatar_path);
        $this->assertEquals($updatedAt, $user->updated_at);
    }

    public function test_removing_clears_the_path_even_if_the_file_is_already_gone(): void
    {
        $user = User::factory()->create(['avatar_path' => 'avatars/1/missing.png']);

        (new UpdateUserAvatar)->remove($user);

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_the_disk_comes_from_config(): void
    {
        $this->assertSame('public', UpdateUserAvatar::disk());

        config()->set('filesystems.avatar_disk', 's3');
        $this->assertSame('s3', UpdateUserAvatar::disk());
    }
}
