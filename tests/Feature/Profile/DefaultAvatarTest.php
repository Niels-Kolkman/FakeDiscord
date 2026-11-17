<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Support\DefaultAvatar;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ISSUE-8: users without an uploaded avatar get a generated initials avatar.
 */
class DefaultAvatarTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function initialsProvider(): array
    {
        return [
            'two words' => ['Niels Kolkman', 'NK'],
            'one word' => ['niels', 'N'],
            'three words use the first two' => ['Ada King Lovelace', 'AK'],
            'extra whitespace' => ["  jane \t doe ", 'JD'],
            'multibyte' => ['élodie ünal', 'ÉÜ'],
            'empty' => ['', '?'],
        ];
    }

    #[DataProvider('initialsProvider')]
    public function test_initials(string $name, string $expected): void
    {
        $this->assertSame($expected, DefaultAvatar::initials($name));
    }

    public function test_the_colour_is_stable_and_from_the_palette(): void
    {
        $this->assertSame(DefaultAvatar::color('niels'), DefaultAvatar::color('niels'));
        $this->assertContains(DefaultAvatar::color('niels'), DefaultAvatar::COLORS);

        $colours = array_map(fn (int $i) => DefaultAvatar::color("user{$i}"), range(1, 50));
        $this->assertGreaterThan(1, count(array_unique($colours)), 'Different users should not all get the same colour.');
    }

    public function test_users_without_an_upload_get_a_generated_svg_data_uri(): void
    {
        $user = User::factory()->make(['name' => 'Niels Kolkman', 'username' => 'niels', 'avatar_path' => null]);

        $url = $user->avatarUrl();

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
        $svg = (string) base64_decode(substr($url, strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('width="256" height="256"', $svg);
        $this->assertStringContainsString('>NK</text>', $svg);
        $this->assertStringContainsString('fill="'.DefaultAvatar::color('niels').'"', $svg);
    }

    public function test_names_are_escaped_inside_the_svg(): void
    {
        $user = User::factory()->make(['name' => '<script>alert(1)</script>', 'username' => 'x']);

        $svg = DefaultAvatar::svg($user);

        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringContainsString('&lt;</text>', $svg);
    }
}
