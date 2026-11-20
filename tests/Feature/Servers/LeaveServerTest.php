<?php

namespace Tests\Feature\Servers;

use App\Actions\Servers\CreateServer;
use App\Actions\Servers\LeaveServer;
use App\Actions\Servers\TransferServerOwnership;
use App\Http\Requests\TransferServerOwnershipRequest;
use App\Models\Invite;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Leaving a server, and transferring ownership so the owner can leave.
 */
class LeaveServerTest extends TestCase
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
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $user->id]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function transfer(User $as, mixed $newOwnerId, string $confirm = 'Test Server'): TestResponse
    {
        return $this->actingAs($as)
            ->from("/servers/{$this->server->id}/settings")
            ->patch("/servers/{$this->server->id}/owner", [
                'new_owner_id' => $newOwnerId,
                'confirm_name' => $confirm,
            ]);
    }

    // --- Access ----------------------------------------------------------

    public function test_guests_and_unverified_users_are_redirected(): void
    {
        $id = $this->server->id;

        $this->get("/servers/{$id}/leave")->assertRedirect('/login');
        $this->delete("/servers/{$id}/leave")->assertRedirect('/login');
        $this->patch("/servers/{$id}/owner", ['new_owner_id' => $this->member->id])->assertRedirect('/login');

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->delete("/servers/{$id}/leave")->assertRedirect('/email/verify');

        $this->assertTrue($this->server->hasMember($this->member));
    }

    public function test_non_members_cannot_open_or_use_the_leave_page(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get("/servers/{$this->server->id}/leave")->assertForbidden();
        $this->actingAs($stranger)->delete("/servers/{$this->server->id}/leave")->assertForbidden();
    }

    // --- Leaving ---------------------------------------------------------

    public function test_the_sidebar_links_to_the_leave_page(): void
    {
        $this->actingAs($this->member)
            ->get("/servers/{$this->server->id}")
            ->assertSee(route('servers.leave.confirm', $this->server), false);
    }

    public function test_a_member_sees_a_confirmation_page(): void
    {
        $this->actingAs($this->member)
            ->get("/servers/{$this->server->id}/leave")
            ->assertOk()
            ->assertSee('Are you sure you want to leave')
            ->assertSee('action="'.route('servers.leave', $this->server).'"', false);
    }

    public function test_a_member_can_leave(): void
    {
        $this->actingAs($this->member)
            ->delete("/servers/{$this->server->id}/leave")
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'You have left "Test Server".');

        $this->assertFalse($this->server->hasMember($this->member));
        $this->assertTrue($this->server->hasMember($this->owner));
        $this->actingAs($this->member)->get("/servers/{$this->server->id}")->assertForbidden();
    }

    public function test_leaving_removes_the_members_roles_on_that_server_only(): void
    {
        $here = Role::factory()->create(['server_id' => $this->server->id]);
        $elsewhere = Role::factory()->create();
        $here->users()->attach($this->member);
        $elsewhere->users()->attach($this->member);
        // Someone else's role on this server stays.
        $here->users()->attach($this->owner);

        $this->actingAs($this->member)->delete("/servers/{$this->server->id}/leave");

        $this->assertDatabaseMissing('role_user', ['role_id' => $here->id, 'user_id' => $this->member->id]);
        $this->assertDatabaseHas('role_user', ['role_id' => $elsewhere->id, 'user_id' => $this->member->id]);
        $this->assertDatabaseHas('role_user', ['role_id' => $here->id, 'user_id' => $this->owner->id]);
        $this->assertDatabaseHas('roles', ['id' => $here->id]);
    }

    public function test_leaving_keeps_other_memberships_and_invites(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Other');
        ServerMember::factory()->create(['server_id' => $other->id, 'user_id' => $this->member->id]);
        $invite = Invite::factory()->create(['server_id' => $this->server->id, 'inviter_id' => $this->member->id]);

        $this->actingAs($this->member)->delete("/servers/{$this->server->id}/leave");

        $this->assertTrue($other->hasMember($this->member));
        $this->assertModelExists($invite);
    }

    public function test_a_member_who_left_can_rejoin_with_an_invite(): void
    {
        $invite = Invite::factory()->create([
            'server_id' => $this->server->id,
            'inviter_id' => $this->owner->id,
            'expires_at' => null,
            'max_uses' => null,
        ]);

        $this->actingAs($this->member)->delete("/servers/{$this->server->id}/leave");
        $this->actingAs($this->member)->post("/invite/{$invite->code}")->assertRedirect("/servers/{$this->server->id}");

        $this->assertTrue($this->server->hasMember($this->member));
    }

    // --- The owner cannot leave -----------------------------------------

    public function test_the_owner_is_told_to_transfer_ownership_first(): void
    {
        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}/leave")
            ->assertOk()
            ->assertSee('Transfer ownership to another member before leaving this server.')
            ->assertSee(route('servers.edit', $this->server).'#transfer-ownership', false)
            ->assertDontSee('action="'.route('servers.leave', $this->server).'"', false);
    }

    public function test_the_owner_cannot_leave(): void
    {
        $this->actingAs($this->owner)
            ->delete("/servers/{$this->server->id}/leave")
            ->assertForbidden()
            ->assertSee('Transfer ownership to another member before leaving this server.');

        $this->assertTrue($this->server->hasMember($this->owner));
    }

    public function test_the_action_refuses_the_owner_even_after_a_stale_policy_check(): void
    {
        // The server model was loaded before ownership moved to the member.
        $stale = Server::findOrFail($this->server->id);
        $this->server->update(['owner_id' => $this->member->id]);

        try {
            app(LeaveServer::class)->leave($stale, $this->member);
            $this->fail('Expected an authorization error.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertTrue($this->server->hasMember($this->member));
    }

    // --- Transferring ownership -----------------------------------------

    public function test_the_settings_page_offers_other_members_as_new_owner(): void
    {
        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}/settings")
            ->assertOk()
            ->assertSee('id="transfer-ownership"', false)
            ->assertSee('<option value="'.$this->member->id.'"', false)
            ->assertDontSee('<option value="'.$this->owner->id.'"', false);
    }

    public function test_the_settings_page_says_when_there_is_nobody_to_transfer_to(): void
    {
        ServerMember::query()->where('user_id', $this->member->id)->delete();

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}/settings")
            ->assertSee('There are no other members to transfer this server to.');
    }

    public function test_the_owner_can_transfer_ownership_and_then_leave(): void
    {
        $this->transfer($this->owner, $this->member->id)
            ->assertRedirect("/servers/{$this->server->id}")
            ->assertSessionHas('status', 'Member now owns "Test Server".');

        $this->server->refresh();
        $this->assertTrue($this->server->isOwnedBy($this->member));
        // The previous owner stays a member, without owner rights.
        $this->assertTrue($this->server->hasMember($this->owner));
        $this->actingAs($this->owner)->get("/servers/{$this->server->id}/settings")->assertForbidden();
        $this->actingAs($this->member)->get("/servers/{$this->server->id}/settings")->assertOk();

        $this->actingAs($this->owner)->delete("/servers/{$this->server->id}/leave")->assertRedirect('/dashboard');
        $this->assertFalse($this->server->hasMember($this->owner));
    }

    public function test_only_the_owner_can_transfer_ownership(): void
    {
        $third = User::factory()->create();
        $this->addMember($third);

        $this->transfer($this->member, $third->id)->assertForbidden();
        $this->transfer(User::factory()->create(), $third->id)->assertForbidden();

        $this->assertTrue($this->server->fresh()->isOwnedBy($this->owner));
    }

    public function test_authorisation_is_checked_before_validation(): void
    {
        $this->transfer($this->member, 'nonsense', 'wrong')->assertForbidden();
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidNewOwners(): array
    {
        return [
            'missing' => [null, 'Choose the member who will become the owner.'],
            'not a number' => ['abc', 'Choose the member who will become the owner.'],
            'unknown user' => [999999, 'The new owner must be a member of this server.'],
        ];
    }

    #[DataProvider('invalidNewOwners')]
    public function test_the_new_owner_must_be_valid(mixed $newOwnerId, string $message): void
    {
        $this->transfer($this->owner, $newOwnerId)
            ->assertRedirect("/servers/{$this->server->id}/settings")
            ->assertSessionHasErrorsIn(TransferServerOwnershipRequest::ERROR_BAG, ['new_owner_id' => $message]);

        $this->assertTrue($this->server->fresh()->isOwnedBy($this->owner));
    }

    public function test_the_new_owner_must_be_a_member_of_this_server(): void
    {
        $outsider = User::factory()->create();
        $other = app(CreateServer::class)->create($this->owner, 'Other');
        ServerMember::factory()->create(['server_id' => $other->id, 'user_id' => $outsider->id]);

        $this->transfer($this->owner, $outsider->id)
            ->assertSessionHasErrorsIn(TransferServerOwnershipRequest::ERROR_BAG, ['new_owner_id' => 'The new owner must be a member of this server.']);

        $this->assertTrue($this->server->fresh()->isOwnedBy($this->owner));
    }

    public function test_the_owner_cannot_transfer_to_themselves(): void
    {
        $this->transfer($this->owner, $this->owner->id)
            ->assertSessionHasErrorsIn(TransferServerOwnershipRequest::ERROR_BAG, ['new_owner_id' => 'You already own this server. Choose another member.']);
    }

    public function test_the_server_name_must_be_typed_to_confirm(): void
    {
        $this->transfer($this->owner, $this->member->id, 'test server')
            ->assertSessionHasErrorsIn(TransferServerOwnershipRequest::ERROR_BAG, ['confirm_name' => 'Type the server name exactly to confirm.']);

        $this->assertTrue($this->server->fresh()->isOwnedBy($this->owner));
    }

    public function test_transfer_errors_do_not_show_on_the_delete_form(): void
    {
        $this->transfer($this->owner, $this->member->id, 'wrong');

        $this->actingAs($this->owner)
            ->get("/servers/{$this->server->id}/settings")
            ->assertSee('aria-describedby="transfer_confirm_name-error"', false)
            ->assertDontSee('aria-describedby="confirm_name-error"', false);
    }

    public function test_the_action_refuses_a_new_owner_who_left_in_the_meantime(): void
    {
        ServerMember::query()->where('user_id', $this->member->id)->delete();

        try {
            app(TransferServerOwnership::class)->transfer($this->server, $this->member);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertSame(TransferServerOwnershipRequest::ERROR_BAG, $e->errorBag);
        }

        $this->assertTrue($this->server->fresh()->isOwnedBy($this->owner));
    }

    public function test_owner_id_cannot_be_changed_through_the_rename_form(): void
    {
        $this->actingAs($this->owner)->patch("/servers/{$this->server->id}", [
            'name' => 'Renamed',
            'owner_id' => $this->member->id,
        ]);

        $this->assertTrue($this->server->fresh()->isOwnedBy($this->owner));
    }
}
