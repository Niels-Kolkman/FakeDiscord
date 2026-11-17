<?php

namespace Tests\Feature\Servers;

use App\Actions\Servers\CreateServer;
use App\Models\Ban;
use App\Models\Channel;
use App\Models\Invite;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ISSUE-10: edge cases for creating, renaming and deleting servers that
 * ServerManagementTest does not cover (mass assignment, authorisation order,
 * icon precedence, cascade scope, configured icon disk).
 */
class ServerManagementEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    /** A PNG header that passes the image rule but that GD cannot decode. */
    private const BROKEN_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJ';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function serverOwnedBy(User $owner, string $name = 'Test Server', ?UploadedFile $icon = null): Server
    {
        return app(CreateServer::class)->create($owner, $name, $icon);
    }

    private function addMember(Server $server, User $user): void
    {
        ServerMember::factory()->create(['server_id' => $server->id, 'user_id' => $user->id]);
    }

    // --- Create ----------------------------------------------------------

    public function test_create_ignores_mass_assigned_owner_and_icon_path(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post('/servers', [
            'name' => 'Mine',
            'owner_id' => $other->id,
            'icon_path' => 'server-icons/evil.png',
        ])->assertSessionHasNoErrors();

        $server = Server::sole();
        $this->assertSame($user->id, (int) $server->owner_id);
        $this->assertNull($server->icon_path);
        $this->assertFalse($server->hasMember($other));
    }

    public function test_each_new_server_gets_its_own_owner_membership_role_and_general_channel(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Duplicate names are allowed, also for the same owner.
        $this->actingAs($alice)->post('/servers', ['name' => 'Same'])->assertSessionHasNoErrors();
        $this->actingAs($alice)->post('/servers', ['name' => 'Same'])->assertSessionHasNoErrors();
        $this->actingAs($bob)->post('/servers', ['name' => 'Same'])->assertSessionHasNoErrors();

        $servers = Server::orderBy('id')->get();
        $this->assertCount(3, $servers);
        $this->assertSame([$alice->id, $alice->id, $bob->id], $servers->pluck('owner_id')->map(fn ($id) => (int) $id)->all());

        foreach ($servers as $server) {
            $this->assertSame([(int) $server->owner_id], $server->members()->pluck('users.id')->all());
            $this->assertSame(['general'], $server->channels()->pluck('name')->all());
            $this->assertSame(1, $server->roles()->where('is_default', true)->count());
        }
    }

    public function test_tabs_and_newlines_in_names_are_collapsed(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/servers', ['name' => "\tTabbed\n\nName  "])
            ->assertSessionHasNoErrors();

        $this->assertSame('Tabbed Name', Server::sole()->name);
    }

    public function test_icons_are_stored_on_the_configured_disk(): void
    {
        Storage::fake('icons');
        config(['filesystems.server_icon_disk' => 'icons']);

        $this->actingAs(User::factory()->create())
            ->post('/servers', ['name' => 'Elsewhere', 'icon' => UploadedFile::fake()->image('i.png', 50, 50)])
            ->assertSessionHasNoErrors();

        $path = (string) Server::sole()->icon_path;
        Storage::disk('icons')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    // --- Authorisation order --------------------------------------------

    public function test_authorisation_is_checked_before_validation(): void
    {
        $server = $this->serverOwnedBy(User::factory()->create(), 'Guarded');
        $member = User::factory()->create();
        $this->addMember($server, $member);

        foreach ([$member, User::factory()->create()] as $user) {
            // Invalid payloads must still get 403, so outsiders learn nothing from validation errors.
            $this->actingAs($user)->patch("/servers/{$server->id}", ['name' => ''])->assertForbidden();
            $this->actingAs($user)->delete("/servers/{$server->id}", ['confirm_name' => 'wrong'])->assertForbidden();
        }

        $this->assertModelExists($server);
        $this->assertSame('Guarded', $server->fresh()?->name);
    }

    public function test_missing_servers_return_404_for_every_route(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/servers/999/settings')->assertNotFound();
        $this->actingAs($user)->patch('/servers/999', ['name' => 'Valid'])->assertNotFound();
        $this->actingAs($user)->delete('/servers/999', ['confirm_name' => 'x'])->assertNotFound();
    }

    public function test_an_unverified_owner_cannot_use_their_server_routes(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Locked');
        $owner->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($owner)->get("/servers/{$server->id}")->assertRedirect('/email/verify');
        $this->actingAs($owner)->patch("/servers/{$server->id}", ['name' => 'Changed'])->assertRedirect('/email/verify');
        $this->actingAs($owner)->delete("/servers/{$server->id}", ['confirm_name' => 'Locked'])->assertRedirect('/email/verify');

        $this->assertSame('Locked', $server->fresh()?->name);
    }

    // --- Rename / icon ---------------------------------------------------

    public function test_rename_cannot_transfer_ownership_or_set_icon_path(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Original');

        $this->actingAs($owner)->patch("/servers/{$server->id}", [
            'name' => 'Renamed',
            'owner_id' => $other->id,
            'icon_path' => 'server-icons/evil.png',
        ])->assertSessionHasNoErrors();

        $server->refresh();
        $this->assertSame('Renamed', $server->name);
        $this->assertSame($owner->id, (int) $server->owner_id);
        $this->assertNull($server->icon_path);
    }

    public function test_renaming_without_icon_fields_keeps_the_icon(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Has Icon', UploadedFile::fake()->image('i.png', 64, 64));
        $path = (string) $server->icon_path;

        $this->actingAs($owner)
            ->patch("/servers/{$server->id}", ['name' => 'Still Has Icon'])
            ->assertSessionHasNoErrors();

        $this->assertSame($path, $server->fresh()?->icon_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_new_upload_wins_over_remove_icon(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Both', UploadedFile::fake()->image('old.png', 64, 64));
        $old = (string) $server->icon_path;

        $this->actingAs($owner)->patch("/servers/{$server->id}", [
            'name' => 'Both',
            'remove_icon' => '1',
            'icon' => UploadedFile::fake()->image('new.png', 64, 64),
        ])->assertSessionHasNoErrors();

        $new = $server->fresh()?->icon_path;
        $this->assertNotNull($new);
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertExists($new);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_remove_icon_without_an_icon_is_a_no_op(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Plain');

        $this->actingAs($owner)
            ->patch("/servers/{$server->id}", ['name' => 'Plain', 'remove_icon' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertNull($server->fresh()?->icon_path);
    }

    public function test_remove_icon_must_be_boolean(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Strict', UploadedFile::fake()->image('i.png', 64, 64));
        $path = (string) $server->icon_path;

        $this->actingAs($owner)
            ->patch("/servers/{$server->id}", ['name' => 'Strict', 'remove_icon' => 'maybe'])
            ->assertSessionHasErrors('remove_icon');

        $this->assertSame($path, $server->fresh()?->icon_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_an_undecodable_replacement_keeps_the_existing_icon(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Keep', UploadedFile::fake()->image('i.png', 64, 64));
        $path = (string) $server->icon_path;

        $this->actingAs($owner)->patch("/servers/{$server->id}", [
            'name' => 'Keep',
            'icon' => UploadedFile::fake()->createWithContent('b.png', (string) base64_decode(self::BROKEN_PNG)),
        ])->assertSessionHasErrors('icon');

        $this->assertSame($path, $server->fresh()?->icon_path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame([$path], Storage::disk('public')->allFiles());
    }

    public function test_rename_rejects_too_long_and_non_string_names(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Valid');

        foreach ([str_repeat('a', 101), ['x'], null] as $name) {
            $this->actingAs($owner)
                ->patch("/servers/{$server->id}", ['name' => $name])
                ->assertSessionHasErrors('name');
        }

        $this->assertSame('Valid', $server->fresh()?->name);
    }

    // --- Delete ----------------------------------------------------------

    public function test_deleting_a_server_only_removes_that_servers_data(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $doomed = $this->serverOwnedBy($owner, 'Doomed');
        $kept = $this->serverOwnedBy($owner, 'Kept', UploadedFile::fake()->image('k.png', 64, 64));

        foreach ([$doomed, $kept] as $server) {
            $this->addMember($server, $member);
            Invite::factory()->create(['server_id' => $server->id, 'inviter_id' => $owner->id]);
            Ban::factory()->create(['server_id' => $server->id, 'banned_by_id' => $owner->id]);
            Channel::factory()->voice()->create(['server_id' => $server->id, 'position' => 1]);
            DB::table('role_user')->insert([
                'role_id' => $server->roles()->value('id'),
                'user_id' => $member->id,
            ]);
        }

        $this->actingAs($owner)
            ->delete("/servers/{$doomed->id}", ['confirm_name' => 'Doomed'])
            ->assertRedirect('/dashboard');

        $this->assertModelMissing($doomed);
        foreach (['channels', 'roles', 'server_members', 'invites', 'bans'] as $table) {
            $this->assertSame(0, DB::table($table)->where('server_id', $doomed->id)->count(), "{$table} for deleted server");
            $this->assertGreaterThan(0, DB::table($table)->where('server_id', $kept->id)->count(), "{$table} for kept server");
        }
        $this->assertSame(1, DB::table('role_user')->count());

        $this->assertModelExists($kept);
        Storage::disk('public')->assertExists((string) $kept->icon_path);
    }

    public function test_a_server_without_an_icon_can_be_deleted(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'No Icon');

        $this->actingAs($owner)
            ->delete("/servers/{$server->id}", ['confirm_name' => 'No Icon'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/dashboard');

        $this->assertModelMissing($server);
    }

    public function test_confirm_name_must_match_case_and_inner_spacing(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Exact Name');

        foreach (['Exact  Name', 'EXACT NAME', 'exact name', 'ExactName'] as $typed) {
            $this->actingAs($owner)
                ->delete("/servers/{$server->id}", ['confirm_name' => $typed])
                ->assertSessionHasErrors(['confirm_name' => 'Type the server name exactly to confirm.']);
        }

        $this->assertModelExists($server);
    }

    public function test_surrounding_whitespace_in_confirm_name_is_trimmed_by_the_framework(): void
    {
        // Laravel's global TrimStrings middleware trims input before the
        // controller compares it, so a stray trailing space still confirms.
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Exact Name');

        $this->actingAs($owner)
            ->delete("/servers/{$server->id}", ['confirm_name' => '  Exact Name '])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/dashboard');

        $this->assertModelMissing($server);
    }

    public function test_a_deleted_server_disappears_from_the_dashboard_and_returns_404(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Gone Soon');

        $this->actingAs($owner)->delete("/servers/{$server->id}", ['confirm_name' => 'Gone Soon']);

        $this->actingAs($owner)->get('/dashboard')
            ->assertOk()
            ->assertSee('&quot;Gone Soon&quot; has been deleted.', false)
            ->assertDontSee(route('servers.show', $server), false);
        $this->actingAs($owner)->get("/servers/{$server->id}")->assertNotFound();
    }

    // --- Show ------------------------------------------------------------

    public function test_the_server_page_lists_channels_in_position_order(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Ordered');
        Channel::factory()->create(['server_id' => $server->id, 'name' => 'zeta-last', 'position' => 5]);
        Channel::factory()->create(['server_id' => $server->id, 'name' => 'alpha-middle', 'position' => 2]);

        $this->actingAs($owner)->get("/servers/{$server->id}")
            ->assertOk()
            ->assertSeeInOrder(['general', 'alpha-middle', 'zeta-last']);
    }
}
