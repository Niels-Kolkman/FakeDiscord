<?php

namespace Tests\Feature\Permissions;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Policies\ChannelPolicy;
use App\Policies\RolePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * The allowed/denied matrix of ServerPolicy, ChannelPolicy,
 * MessagePolicy and RolePolicy for the owner, an administrator, a member
 * whose role has the flag, a plain member (only @everyone) and a stranger.
 */
class PolicyMatrixTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->server = app(CreateServer::class)->create($this->owner, 'Matrix');
        /** @var Channel $channel */
        $channel = $this->server->channels()->firstOrFail();
        $this->channel = $channel;
    }

    public function test_the_new_policies_are_registered(): void
    {
        $this->assertInstanceOf(ChannelPolicy::class, Gate::getPolicyFor(Channel::class));
        $this->assertInstanceOf(RolePolicy::class, Gate::getPolicyFor(Role::class));
    }

    /**
     * @return array<string, array{string, string, Permission, bool}>
     */
    public static function abilities(): array
    {
        return [
            // ability, on, flag, does a plain member have it by default?
            'server update' => ['update', 'server', Permission::ManageServer, false],
            'server manageInvites' => ['manageInvites', 'server', Permission::ManageServer, false],
            'server createInvite' => ['createInvite', 'server', Permission::CreateInvite, true],
            'server manageChannels' => ['manageChannels', 'server', Permission::ManageChannels, false],
            'server manageRoles' => ['manageRoles', 'server', Permission::ManageRoles, false],
            'server manageMessages' => ['manageMessages', 'server', Permission::ManageMessages, false],
            'server sendMessages' => ['sendMessages', 'server', Permission::SendMessages, true],
            'channel view' => ['view', 'channel', Permission::ViewChannels, true],
            'channel sendMessages' => ['sendMessages', 'channel', Permission::SendMessages, true],
            'channel attachFiles' => ['attachFiles', 'channel', Permission::AttachFiles, true],
            'channel addReactions' => ['addReactions', 'channel', Permission::AddReactions, true],
            'channel manageMessages' => ['manageMessages', 'channel', Permission::ManageMessages, false],
        ];
    }

    #[DataProvider('abilities')]
    public function test_ability_matrix(string $ability, string $on, Permission $flag, bool $plainByDefault): void
    {
        $target = $on === 'server' ? $this->server : $this->channel;

        $admin = $this->memberWith($this->server, Permission::Administrator);
        $granted = $this->memberWith($this->server, $flag);
        $plain = $this->joinServer($this->server);
        $stranger = User::factory()->create();

        $this->assertMatrix($ability, $target, [
            'owner' => [$this->owner, true],
            'administrator' => [$admin, true],
            'member with the flag' => [$granted, true],
            'plain member' => [$plain, $plainByDefault],
            'stranger' => [$stranger, false],
        ]);

        // Without the flag on @everyone only the role gives it.
        $this->revokeFromEveryone($this->server, $flag);

        $this->assertMatrix($ability, $target, [
            'owner' => [$this->owner, true],
            'administrator' => [$admin, true],
            'member with the flag' => [$granted, true],
            'plain member' => [$plain, false],
            'stranger' => [$stranger, false],
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function channelAbilitiesNeedingView(): array
    {
        return [
            'sendMessages' => ['sendMessages'],
            'attachFiles' => ['attachFiles'],
            'addReactions' => ['addReactions'],
            'manageMessages' => ['manageMessages'],
        ];
    }

    #[DataProvider('channelAbilitiesNeedingView')]
    public function test_every_channel_ability_needs_view_channels(string $ability): void
    {
        $this->revokeFromEveryone($this->server, Permission::ViewChannels);
        $member = $this->memberWith($this->server, Permission::SendMessages, Permission::AttachFiles, Permission::AddReactions, Permission::ManageMessages);

        $this->assertFalse($member->can($ability, $this->channel));
        $this->assertTrue($this->owner->can($ability, $this->channel));
    }

    public function test_attaching_files_also_needs_send_messages(): void
    {
        $this->revokeFromEveryone($this->server, Permission::SendMessages);
        $member = $this->memberWith($this->server, Permission::AttachFiles);

        $this->assertFalse($member->can('attachFiles', $this->channel));
    }

    public function test_deleting_and_transferring_the_server_stay_owner_only(): void
    {
        $admin = $this->memberWith($this->server, Permission::Administrator);
        $plain = $this->joinServer($this->server);

        foreach (['delete', 'transferOwnership'] as $ability) {
            $this->assertTrue($this->owner->can($ability, $this->server), "owner: {$ability}");
            $this->assertFalse($admin->can($ability, $this->server), "administrator: {$ability}");
            $this->assertFalse($plain->can($ability, $this->server), "plain member: {$ability}");
        }
    }

    public function test_viewing_the_server_and_leaving_only_need_membership(): void
    {
        $this->revokeFromEveryone($this->server, ...Permission::cases());
        $member = $this->joinServer($this->server);

        $this->assertTrue($member->can('view', $this->server));
        $this->assertTrue($member->can('leave', $this->server));
        $this->assertFalse(User::factory()->create()->can('view', $this->server));
    }

    public function test_message_matrix(): void
    {
        $author = $this->joinServer($this->server);
        $other = $this->joinServer($this->server);
        $moderator = $this->memberWith($this->server, Permission::ManageMessages);
        $stranger = User::factory()->create();
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $author->id]);

        $expected = [
            // user => [update, delete, react, pin]
            'author' => [$author, true, true, true, false],
            'other member' => [$other, false, false, true, false],
            'moderator' => [$moderator, false, true, true, true],
            'owner' => [$this->owner, false, true, true, true],
            'stranger' => [$stranger, false, false, false, false],
        ];

        foreach ($expected as $who => [$user, $update, $delete, $react, $pin]) {
            $this->assertSame($update, $user->can('update', $message), "update for {$who}");
            $this->assertSame($delete, $user->can('delete', $message), "delete for {$who}");
            $this->assertSame($react, $user->can('react', $message), "react for {$who}");
            $this->assertSame($pin, $user->can('pin', $message), "pin for {$who}");
        }
    }

    public function test_without_view_channels_nobody_but_the_owner_touches_messages(): void
    {
        $author = $this->joinServer($this->server);
        $moderator = $this->memberWith($this->server, Permission::ManageMessages);
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $author->id]);

        $this->revokeFromEveryone($this->server, Permission::ViewChannels);

        foreach (['update', 'delete', 'react', 'pin'] as $ability) {
            $this->assertFalse($author->can($ability, $message), "author: {$ability}");
            $this->assertFalse($moderator->can($ability, $message), "moderator: {$ability}");
        }
        $this->assertTrue($this->owner->can('delete', $message));
    }

    public function test_new_reactions_need_add_reactions_but_existing_ones_can_be_joined(): void
    {
        $member = $this->joinServer($this->server);
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->owner->id]);
        Reaction::factory()->create(['message_id' => $message->id, 'user_id' => $this->owner->id, 'emoji' => '👍']);

        $this->assertTrue($member->can('addReaction', [$message, '🎉']));

        $this->revokeFromEveryone($this->server, Permission::AddReactions);

        $this->assertFalse($member->can('addReaction', [$message, '🎉']));
        $this->assertTrue($member->can('addReaction', [$message, '👍']));
        $this->assertTrue($member->can('react', $message), 'Taking a reaction back needs no flag.');
        $this->assertTrue($this->owner->can('addReaction', [$message, '🎉']));
    }

    public function test_role_matrix(): void
    {
        $below = $this->makeRole($this->server, 'Below', 0, 1);
        $managers = $this->makeRole($this->server, 'Managers', Permission::mask(Permission::ManageRoles), 2);
        $above = $this->makeRole($this->server, 'Above', 0, 3);
        $everyone = $this->server->defaultRole();

        $manager = $this->joinServer($this->server);
        $this->giveRole($manager, $managers);
        $plain = $this->joinServer($this->server);

        $expected = [
            // user => [@everyone, below, own role, above]
            'owner' => [$this->owner, true, true, true, true],
            'manager' => [$manager, true, true, false, false],
            'plain member' => [$plain, false, false, false, false],
            'stranger' => [User::factory()->create(), false, false, false, false],
        ];

        foreach ($expected as $who => [$user, $canEveryone, $canBelow, $canOwn, $canAbove]) {
            $this->assertSame($canEveryone, $user->can('manage', $everyone), "{$who}: @everyone");
            $this->assertSame($canBelow, $user->can('manage', $below), "{$who}: below");
            $this->assertSame($canOwn, $user->can('manage', $managers), "{$who}: own role");
            $this->assertSame($canAbove, $user->can('manage', $above), "{$who}: above");
        }
    }

    public function test_invites_can_be_revoked_by_their_inviter_and_by_manage_server(): void
    {
        $inviter = $this->joinServer($this->server);
        $manager = $this->memberWith($this->server, Permission::ManageServer);
        $other = $this->joinServer($this->server);
        $invite = $this->server->invites()->create([
            'inviter_id' => $inviter->id,
            'channel_id' => $this->channel->id,
            'code' => 'matrix01',
        ]);

        $this->assertTrue($inviter->can('delete', $invite));
        $this->assertTrue($manager->can('delete', $invite));
        $this->assertTrue($this->owner->can('delete', $invite));
        $this->assertFalse($other->can('delete', $invite));
    }

    /**
     * @param  array<string, array{User, bool}>  $expected
     */
    private function assertMatrix(string $ability, Server|Channel $target, array $expected): void
    {
        foreach ($expected as $who => [$user, $allowed]) {
            $this->assertSame($allowed, $user->can($ability, $target), "{$ability} for {$who}");
        }
    }
}
