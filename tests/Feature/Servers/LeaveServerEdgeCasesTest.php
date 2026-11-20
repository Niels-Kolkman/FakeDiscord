<?php

namespace Tests\Feature\Servers;

use App\Actions\Servers\CreateServer;
use App\Actions\Servers\LeaveServer;
use App\Actions\Servers\TransferServerOwnership;
use App\Http\Requests\TransferServerOwnershipRequest;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Edge cases for leaving a server and transferring ownership:
 * repeated requests, missing servers, roles, the sole-owner case, input
 * shapes and the transfer rate limit.
 */
class LeaveServerEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->member = User::factory()->create(['name' => 'Member']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Test Server');
        $this->addMember($this->member);
    }

    private function addMember(User $user): void
    {
        // No nickname: the factory gives one to 1 in 5 members, which would make
        // tests that read display names fail at random.
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $user->id, 'nickname' => null]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function transfer(User $as, array $data): TestResponse
    {
        return $this->actingAs($as)
            ->from("/servers/{$this->server->id}/settings")
            ->patch("/servers/{$this->server->id}/owner", $data);
    }

    // --- Leaving -------------------------------------------------------------

    public function test_leaving_twice_is_forbidden_the_second_time(): void
    {
        $this->actingAs($this->member)->delete("/servers/{$this->server->id}/leave")->assertRedirect('/dashboard');
        $this->actingAs($this->member)->delete("/servers/{$this->server->id}/leave")->assertForbidden();
    }

    public function test_routes_of_a_missing_server_are_not_found(): void
    {
        $this->actingAs($this->member)->get('/servers/999999/leave')->assertNotFound();
        $this->actingAs($this->member)->delete('/servers/999999/leave')->assertNotFound();
        $this->actingAs($this->owner)->patch('/servers/999999/owner', ['new_owner_id' => $this->member->id])->assertNotFound();
    }

    public function test_leaving_needs_the_delete_method(): void
    {
        $this->actingAs($this->member)->post("/servers/{$this->server->id}/leave")->assertMethodNotAllowed();

        $this->assertTrue($this->server->hasMember($this->member));
    }

    public function test_leaving_also_removes_an_everyone_role_row(): void
    {
        $everyone = $this->server->roles()->where('is_default', true)->firstOrFail();
        $everyone->users()->syncWithoutDetaching([$this->member->id]);

        $this->actingAs($this->member)->delete("/servers/{$this->server->id}/leave");

        $this->assertDatabaseMissing('role_user', ['role_id' => $everyone->id, 'user_id' => $this->member->id]);
        $this->assertDatabaseHas('roles', ['id' => $everyone->id]);
    }

    public function test_leaving_removes_every_role_on_the_server(): void
    {
        $roles = Role::factory()->count(3)->create(['server_id' => $this->server->id]);
        foreach ($roles as $role) {
            $role->users()->attach($this->member);
        }

        app(LeaveServer::class)->leave($this->server, $this->member);

        $this->assertDatabaseMissing('role_user', ['user_id' => $this->member->id]);
    }

    public function test_leaving_does_not_touch_other_members(): void
    {
        $other = User::factory()->create();
        $this->addMember($other);
        $role = Role::factory()->create(['server_id' => $this->server->id]);
        $role->users()->attach([$this->member->id, $other->id]);

        app(LeaveServer::class)->leave($this->server, $this->member);

        $this->assertTrue($this->server->hasMember($other));
        $this->assertDatabaseHas('role_user', ['role_id' => $role->id, 'user_id' => $other->id]);
        $this->assertSame(2, $this->server->memberships()->count());
    }

    public function test_a_member_who_left_cannot_open_the_leave_page(): void
    {
        app(LeaveServer::class)->leave($this->server, $this->member);

        $this->actingAs($this->member)->get("/servers/{$this->server->id}/leave")->assertForbidden();
    }

    public function test_the_leave_page_escapes_the_server_name(): void
    {
        $this->server->update(['name' => '<b>Evil</b>']);

        $this->actingAs($this->member)
            ->get("/servers/{$this->server->id}/leave")
            ->assertOk()
            ->assertDontSee('<b>Evil</b>', false)
            ->assertSee('&lt;b&gt;Evil&lt;/b&gt;', false);
    }

    // --- The owner -----------------------------------------------------------

    public function test_a_sole_owner_cannot_leave_but_can_still_delete_the_server(): void
    {
        ServerMember::query()->where('user_id', $this->member->id)->delete();

        $this->actingAs($this->owner)->delete("/servers/{$this->server->id}/leave")->assertForbidden();
        $this->assertTrue($this->server->hasMember($this->owner));

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}/settings")
            ->assertSee('There are no other members to transfer this server to.')
            ->assertSee('action="'.route('servers.destroy', $this->server).'"', false);
    }

    public function test_after_a_transfer_the_new_owner_is_blocked_and_the_old_owner_gets_the_form(): void
    {
        app(TransferServerOwnership::class)->transfer($this->server, $this->member);

        $this->actingAs($this->member)
            ->get("/servers/{$this->server->id}/leave")
            ->assertSee('Transfer ownership to another member before leaving this server.')
            ->assertDontSee('action="'.route('servers.leave', $this->server).'"', false);

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}/leave")
            ->assertSee('Are you sure you want to leave')
            ->assertSee('action="'.route('servers.leave', $this->server).'"', false)
            ->assertDontSee('#transfer-ownership', false);
    }

    // --- Transferring ownership ----------------------------------------------

    public function test_the_previous_owner_cannot_transfer_again(): void
    {
        $third = User::factory()->create();
        $this->addMember($third);

        $this->transfer($this->owner, ['new_owner_id' => $this->member->id, 'confirm_name' => 'Test Server'])->assertRedirect();
        $this->transfer($this->owner, ['new_owner_id' => $third->id, 'confirm_name' => 'Test Server'])->assertForbidden();

        $this->assertTrue($this->server->fresh()->isOwnedBy($this->member));
    }

    public function test_ownership_can_be_passed_back(): void
    {
        $this->transfer($this->owner, ['new_owner_id' => $this->member->id, 'confirm_name' => 'Test Server'])->assertRedirect();
        $this->transfer($this->member, ['new_owner_id' => $this->owner->id, 'confirm_name' => 'Test Server'])->assertRedirect();

        $this->assertTrue($this->server->fresh()->isOwnedBy($this->owner));
    }

    public function test_transferring_keeps_everyones_roles_and_memberships(): void
    {
        $role = Role::factory()->create(['server_id' => $this->server->id]);
        $role->users()->attach([$this->owner->id, $this->member->id]);

        app(TransferServerOwnership::class)->transfer($this->server, $this->member);

        $this->assertDatabaseHas('role_user', ['role_id' => $role->id, 'user_id' => $this->owner->id]);
        $this->assertDatabaseHas('role_user', ['role_id' => $role->id, 'user_id' => $this->member->id]);
        $this->assertSame(2, $this->server->memberships()->count());
    }

    public function test_only_the_owner_changes_on_the_server(): void
    {
        $before = $this->server->fresh()->only(['name', 'icon_path']);

        app(TransferServerOwnership::class)->transfer($this->server, $this->member);

        $this->assertSame($before, $this->server->fresh()->only(['name', 'icon_path']));
    }

    /**
     * "MEMBER" is replaced by the member's id in the test.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedInput(): array
    {
        return [
            'array new owner' => [['new_owner_id' => [1], 'confirm_name' => 'Test Server'], 'new_owner_id'],
            'decimal new owner' => [['new_owner_id' => '1.5', 'confirm_name' => 'Test Server'], 'new_owner_id'],
            'negative new owner' => [['new_owner_id' => -1, 'confirm_name' => 'Test Server'], 'new_owner_id'],
            'missing confirm' => [['new_owner_id' => 'MEMBER'], 'confirm_name'],
            'array confirm' => [['new_owner_id' => 'MEMBER', 'confirm_name' => ['Test Server']], 'confirm_name'],
            'confirm in another case' => [['new_owner_id' => 'MEMBER', 'confirm_name' => 'TEST SERVER'], 'confirm_name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('malformedInput')]
    public function test_malformed_input_is_rejected(array $data, string $field): void
    {
        if (($data['new_owner_id'] ?? null) === 'MEMBER') {
            $data['new_owner_id'] = $this->member->id;
        }

        $this->transfer($this->owner, $data)
            ->assertRedirect("/servers/{$this->server->id}/settings")
            ->assertSessionHasErrorsIn(TransferServerOwnershipRequest::ERROR_BAG, [$field]);

        $this->assertTrue($this->server->fresh()->isOwnedBy($this->owner));
    }

    public function test_surrounding_spaces_in_the_confirmation_are_trimmed(): void
    {
        $this->transfer($this->owner, ['new_owner_id' => $this->member->id, 'confirm_name' => '  Test Server  '])
            ->assertRedirect("/servers/{$this->server->id}")
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->server->fresh()->isOwnedBy($this->member));
    }

    public function test_the_member_picked_is_kept_after_a_validation_error(): void
    {
        $this->transfer($this->owner, ['new_owner_id' => $this->member->id, 'confirm_name' => 'wrong']);

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}/settings")
            ->assertSee('<option value="'.$this->member->id.'" selected', false);
    }

    public function test_candidates_are_sorted_by_display_name_and_show_usernames(): void
    {
        ServerMember::query()->where('user_id', $this->member->id)->update(['nickname' => 'zz Last']);
        $first = User::factory()->create(['name' => 'Aaron']);
        $this->addMember($first);

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}/settings")
            ->assertSeeInOrder([
                'Aaron (@'.$first->username.')',
                'zz Last (@'.$this->member->username.')',
            ]);
    }

    public function test_transferring_is_rate_limited(): void
    {
        foreach (range(1, 6) as $_) {
            $this->transfer($this->owner, ['new_owner_id' => $this->member->id, 'confirm_name' => 'wrong'])
                ->assertRedirect();
        }

        $this->transfer($this->owner, ['new_owner_id' => $this->member->id, 'confirm_name' => 'Test Server'])
            ->assertTooManyRequests();

        $this->assertTrue($this->server->fresh()->isOwnedBy($this->owner));
    }
}
