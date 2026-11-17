<?php

namespace Tests\Feature\Servers;

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ISSUE-10: create, rename and delete servers.
 */
class ServerManagementTest extends TestCase
{
    use RefreshDatabase;

    /** A PNG header that passes the image rule but that GD cannot decode. */
    private const BROKEN_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJ';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function serverOwnedBy(User $owner, string $name = 'Test Server'): Server
    {
        return app(CreateServer::class)->create($owner, $name);
    }

    private function addMember(Server $server, User $user): void
    {
        ServerMember::factory()->create(['server_id' => $server->id, 'user_id' => $user->id]);
    }

    private function brokenPng(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('broken.png', (string) base64_decode(self::BROKEN_PNG));
    }

    // --- Access ----------------------------------------------------------

    public function test_guests_are_redirected_to_login(): void
    {
        $server = $this->serverOwnedBy(User::factory()->create());

        $this->get('/servers/create')->assertRedirect('/login');
        $this->post('/servers', ['name' => 'Nope'])->assertRedirect('/login');
        $this->get("/servers/{$server->id}")->assertRedirect('/login');
        $this->get("/servers/{$server->id}/settings")->assertRedirect('/login');
        $this->patch("/servers/{$server->id}", ['name' => 'Nope'])->assertRedirect('/login');
        $this->delete("/servers/{$server->id}", ['confirm_name' => 'Test Server'])->assertRedirect('/login');

        $this->assertSame(1, Server::count());
    }

    public function test_unverified_users_cannot_create_servers(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/servers/create')->assertRedirect('/email/verify');
        $this->actingAs($user)->post('/servers', ['name' => 'Mine'])->assertRedirect('/email/verify');

        $this->assertSame(0, Server::count());
    }

    // --- Create ----------------------------------------------------------

    public function test_the_create_form_renders(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Niels']))
            ->get('/servers/create')
            ->assertOk()
            ->assertViewIs('servers.create')
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="icon"', false)
            ->assertSee('value="Niels&#039;s server"', false);
    }

    public function test_creating_a_server_makes_the_creator_owner_and_member_with_a_general_channel(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/servers', ['name' => '  My   Cool  Server ']);

        $server = Server::sole();
        $response->assertSessionHasNoErrors()->assertRedirect("/servers/{$server->id}");

        $this->assertSame('My Cool Server', $server->name);
        $this->assertTrue($server->owner->is($user));
        $this->assertNull($server->icon_path);

        $this->assertSame([$user->id], $server->members()->pluck('users.id')->all());
        $this->assertNotNull($server->memberships()->sole()->joined_at);

        $channel = $server->channels()->sole();
        $this->assertSame('general', $channel->name);
        $this->assertSame(Channel::TYPE_TEXT, $channel->type);
        $this->assertSame(0, $channel->position);

        $everyone = $server->roles()->sole();
        $this->assertSame('@everyone', $everyone->name);
        $this->assertTrue($everyone->is_default);
    }

    public function test_the_new_server_page_lists_general(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->followingRedirects()
            ->post('/servers', ['name' => 'Fresh'])
            ->assertOk()
            ->assertViewIs('servers.show')
            ->assertSee('Your server has been created.')
            ->assertSee('general')
            ->assertSee(route('servers.edit', Server::sole()), false);
    }

    public function test_a_server_can_be_created_with_an_icon(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/servers', ['name' => 'Iconic', 'icon' => UploadedFile::fake()->image('icon.jpg', 600, 400)])
            ->assertSessionHasNoErrors();

        $server = Server::sole();
        $this->assertNotNull($server->icon_path);
        $this->assertStringStartsWith("server-icons/{$server->id}/", $server->icon_path);
        Storage::disk('public')->assertExists($server->icon_path);

        $info = getimagesizefromstring((string) Storage::disk('public')->get($server->icon_path));
        $this->assertIsArray($info);
        $this->assertSame([256, 256, 'image/png'], [$info[0], $info[1], $info['mime']]);
        $this->assertSame(Storage::disk('public')->url($server->icon_path), $server->iconUrl());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidNames(): array
    {
        return [
            'missing' => [null],
            'blank' => ['   '],
            'too short' => ['a'],
            'too long' => [str_repeat('a', 101)],
            'not a string' => [['x']],
        ];
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_are_rejected(mixed $name): void
    {
        $this->actingAs(User::factory()->create())
            ->from('/servers/create')
            ->post('/servers', ['name' => $name])
            ->assertRedirect('/servers/create')
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Server::count());
    }

    public function test_name_length_limits_are_inclusive(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/servers', ['name' => 'ab'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/servers', ['name' => str_repeat('é', 100)])->assertSessionHasNoErrors();

        $this->assertSame(2, Server::count());
    }

    public function test_invalid_icons_are_rejected_and_nothing_is_created(): void
    {
        $user = User::factory()->create();

        $cases = [
            UploadedFile::fake()->create('icon.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            UploadedFile::fake()->image('huge.png', 100, 100)->size(5000),
            UploadedFile::fake()->image('wide.png', 4097, 10),
        ];

        foreach ($cases as $file) {
            $this->actingAs($user)
                ->post('/servers', ['name' => 'Bad icon', 'icon' => $file])
                ->assertSessionHasErrors('icon');
        }

        $this->assertSame(0, Server::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_undecodable_icon_rolls_back_the_whole_server(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/servers', ['name' => 'Broken', 'icon' => $this->brokenPng()])
            ->assertSessionHasErrors(['icon' => 'The icon could not be read as an image.']);

        $this->assertSame(0, Server::count());
        $this->assertSame(0, Channel::count());
        $this->assertSame(0, ServerMember::count());
    }

    // --- View ------------------------------------------------------------

    public function test_only_members_can_open_a_server(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Members Only');
        $this->addMember($server, $member);

        $this->actingAs($owner)->get("/servers/{$server->id}")->assertOk()->assertSee('Server settings');
        $this->actingAs($member)->get("/servers/{$server->id}")->assertOk()->assertSee('Members Only')->assertDontSee('Server settings');
        $this->actingAs($stranger)->get("/servers/{$server->id}")->assertForbidden();
    }

    public function test_unknown_servers_return_404(): void
    {
        $this->actingAs(User::factory()->create())->get('/servers/999')->assertNotFound();
    }

    public function test_the_dashboard_lists_the_users_servers(): void
    {
        $user = User::factory()->create();
        $this->serverOwnedBy($user, 'Zeta Club');
        $joined = $this->serverOwnedBy(User::factory()->create(), 'Alpha Guild');
        $this->addMember($joined, $user);
        $this->serverOwnedBy(User::factory()->create(), 'Not Mine');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Alpha Guild', 'Zeta Club'])
            ->assertDontSee('Not Mine')
            ->assertSee(route('servers.create'), false);
    }

    public function test_the_dashboard_hides_create_for_unverified_users(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('You are not in any servers yet.')
            ->assertDontSee(route('servers.create'), false);
    }

    // --- Rename / icon ---------------------------------------------------

    public function test_the_owner_can_rename_the_server(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Old Name');

        $this->actingAs($owner)->get("/servers/{$server->id}/settings")
            ->assertOk()
            ->assertViewIs('servers.edit')
            ->assertSee('value="Old Name"', false)
            ->assertSee('name="confirm_name"', false)
            ->assertDontSee('name="remove_icon"', false);

        $this->actingAs($owner)
            ->patch("/servers/{$server->id}", ['name' => ' New Name '])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/servers/{$server->id}/settings");

        $this->assertSame('New Name', $server->fresh()?->name);
    }

    public function test_members_and_strangers_cannot_rename_or_open_settings(): void
    {
        $server = $this->serverOwnedBy(User::factory()->create(), 'Keep Me');
        $member = User::factory()->create();
        $this->addMember($server, $member);

        foreach ([$member, User::factory()->create()] as $user) {
            $this->actingAs($user)->get("/servers/{$server->id}/settings")->assertForbidden();
            $this->actingAs($user)->patch("/servers/{$server->id}", ['name' => 'Hijacked'])->assertForbidden();
        }

        $this->assertSame('Keep Me', $server->fresh()?->name);
    }

    public function test_rename_validates_the_name(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Valid');

        $this->actingAs($owner)
            ->patch("/servers/{$server->id}", ['name' => 'x'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Valid', $server->fresh()?->name);
    }

    public function test_the_owner_can_replace_and_remove_the_icon(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $this->actingAs($owner)->patch("/servers/{$server->id}", [
            'name' => $server->name,
            'icon' => UploadedFile::fake()->image('a.png', 300, 300),
        ])->assertSessionHasNoErrors();
        $first = (string) $server->fresh()?->icon_path;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($owner)->patch("/servers/{$server->id}", [
            'name' => $server->name,
            'icon' => UploadedFile::fake()->image('b.png', 300, 300),
        ])->assertSessionHasNoErrors();
        $second = (string) $server->fresh()?->icon_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->actingAs($owner)->get("/servers/{$server->id}/settings")->assertSee('name="remove_icon"', false);

        $this->actingAs($owner)->patch("/servers/{$server->id}", [
            'name' => $server->name,
            'remove_icon' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertNull($server->fresh()?->icon_path);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_an_undecodable_icon_does_not_apply_the_rename(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Before');

        $this->actingAs($owner)->patch("/servers/{$server->id}", [
            'name' => 'After',
            'icon' => $this->brokenPng(),
        ])->assertSessionHasErrors(['icon' => 'The icon could not be read as an image.']);

        $this->assertSame('Before', $server->fresh()?->name);
    }

    // --- Delete ----------------------------------------------------------

    public function test_the_owner_can_delete_the_server_and_everything_in_it(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $this->actingAs($owner)->post('/servers', [
            'name' => 'Doomed',
            'icon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ]);
        $server = Server::sole();
        $iconPath = (string) $server->icon_path;
        $this->addMember($server, $member);
        Message::factory()->create([
            'channel_id' => $server->channels()->value('id'),
            'conversation_id' => null,
            'user_id' => $member->id,
        ]);

        $this->actingAs($owner)
            ->delete("/servers/{$server->id}", ['confirm_name' => 'Doomed'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', '"Doomed" has been deleted.');

        $this->assertModelMissing($server);
        $this->assertSame(0, Channel::count());
        $this->assertSame(0, ServerMember::count());
        $this->assertSame(0, Message::count());
        $this->assertDatabaseCount('roles', 0);
        Storage::disk('public')->assertMissing($iconPath);

        // The users themselves are untouched.
        $this->assertModelExists($owner);
        $this->assertModelExists($member);
    }

    public function test_deleting_requires_typing_the_exact_name(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Precious');

        foreach ([null, '', 'precious', 'Other'] as $typed) {
            $this->actingAs($owner)
                ->delete("/servers/{$server->id}", ['confirm_name' => $typed])
                ->assertSessionHasErrors('confirm_name');
        }

        $this->assertModelExists($server);
    }

    public function test_members_and_strangers_cannot_delete_the_server(): void
    {
        $server = $this->serverOwnedBy(User::factory()->create(), 'Safe');
        $member = User::factory()->create();
        $this->addMember($server, $member);

        foreach ([$member, User::factory()->create()] as $user) {
            $this->actingAs($user)
                ->delete("/servers/{$server->id}", ['confirm_name' => 'Safe'])
                ->assertForbidden();
        }

        $this->assertModelExists($server);
    }
}
