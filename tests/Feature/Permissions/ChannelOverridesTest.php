<?php

namespace Tests\Feature\Permissions;

use App\Actions\Channels\SetChannelOverride;
use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Channel;
use App\Models\ChannelPermissionOverride;
use App\Models\Server;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * Private channels and per-channel role overrides, as resolved by
 * PermissionService and written by SetChannelOverride.
 */
class ChannelOverridesTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private PermissionService $permissions;

    private User $owner;

    private Server $server;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permissions = app(PermissionService::class);
        $this->owner = User::factory()->create();
        $this->server = app(CreateServer::class)->create($this->owner, 'Overrides');
        /** @var Channel $channel */
        $channel = $this->server->channels()->firstOrFail();
        $this->channel = $channel;
    }

    private function viewBit(): int
    {
        return Permission::ViewChannels->value;
    }

    private function sendBit(): int
    {
        return Permission::SendMessages->value;
    }

    public function test_without_overrides_channel_permissions_are_the_server_permissions(): void
    {
        $member = $this->joinServer($this->server);

        $this->assertSame(
            $this->permissions->permissions($member, $this->server),
            $this->permissions->channelPermissions($member, $this->channel),
        );
    }

    public function test_a_private_channel_is_hidden_from_plain_members_but_not_the_owner_or_administrators(): void
    {
        $member = $this->joinServer($this->server);
        $admin = $this->memberWith($this->server, Permission::Administrator);
        $this->channel->update(['is_private' => true]);

        $this->assertFalse($member->can('view', $this->channel));
        $this->assertFalse($member->can('sendMessages', $this->channel));
        $this->assertTrue($this->owner->can('view', $this->channel));
        $this->assertTrue($admin->can('view', $this->channel));
        $this->assertSame(Permission::all(), $this->permissions->channelPermissions($admin, $this->channel));
    }

    public function test_a_role_allowed_view_channels_opens_a_private_channel_for_its_holders_only(): void
    {
        $this->channel->update(['is_private' => true]);
        $staff = $this->makeRole($this->server, 'Staff', 0);
        $this->overrideChannel($this->channel, $staff, allow: $this->viewBit());
        $holder = $this->joinServer($this->server);
        $this->giveRole($holder, $staff);
        $other = $this->joinServer($this->server);

        $this->assertTrue($holder->can('view', $this->channel));
        $this->assertTrue($holder->can('sendMessages', $this->channel), 'Send Messages comes from @everyone');
        $this->assertFalse($other->can('view', $this->channel));
    }

    public function test_an_everyone_allow_does_not_open_a_private_channel(): void
    {
        $member = $this->joinServer($this->server);
        $this->channel->update(['is_private' => true]);
        $this->overrideChannel($this->channel, $this->server->defaultRole(), allow: $this->viewBit());

        $this->assertFalse($member->can('view', $this->channel));
    }

    public function test_everyone_overrides_apply_to_every_member(): void
    {
        $member = $this->joinServer($this->server);
        $this->overrideChannel($this->channel, $this->server->defaultRole(), deny: $this->sendBit());

        $this->assertTrue($member->can('view', $this->channel));
        $this->assertFalse($member->can('sendMessages', $this->channel));
        $this->assertFalse($member->can('attachFiles', $this->channel), 'attaching needs Send Messages');
        // Only this channel.
        $this->assertTrue($this->permissions->has($member, $this->server, Permission::SendMessages));
    }

    public function test_an_everyone_allow_grants_a_flag_the_server_withholds(): void
    {
        $member = $this->joinServer($this->server);
        $this->revokeFromEveryone($this->server, Permission::SendMessages);
        $this->overrideChannel($this->channel, $this->server->defaultRole(), allow: $this->sendBit());

        $this->assertTrue($member->can('sendMessages', $this->channel));
    }

    public function test_a_role_allow_beats_an_everyone_deny(): void
    {
        $this->overrideChannel($this->channel, $this->server->defaultRole(), deny: $this->sendBit());
        $speakers = $this->makeRole($this->server, 'Speakers', 0);
        $this->overrideChannel($this->channel, $speakers, allow: $this->sendBit());
        $speaker = $this->joinServer($this->server);
        $this->giveRole($speaker, $speakers);

        $this->assertTrue($speaker->can('sendMessages', $this->channel));
        $this->assertFalse($this->joinServer($this->server)->can('sendMessages', $this->channel));
    }

    public function test_a_role_deny_takes_away_what_the_server_gives(): void
    {
        $muted = $this->makeRole($this->server, 'Muted', 0);
        $this->overrideChannel($this->channel, $muted, deny: $this->sendBit() | $this->viewBit());
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $muted);

        $this->assertFalse($member->can('view', $this->channel));
        $this->assertFalse($member->can('sendMessages', $this->channel));
    }

    public function test_between_roles_an_allow_beats_a_deny(): void
    {
        $muted = $this->makeRole($this->server, 'Muted', 0);
        $helpers = $this->makeRole($this->server, 'Helpers', 0);
        $this->overrideChannel($this->channel, $muted, deny: $this->sendBit());
        $this->overrideChannel($this->channel, $helpers, allow: $this->sendBit());
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $muted);
        $this->giveRole($member, $helpers);

        $this->assertTrue($member->can('sendMessages', $this->channel));
    }

    public function test_overrides_of_roles_the_member_does_not_hold_do_not_count(): void
    {
        $this->channel->update(['is_private' => true]);
        $staff = $this->makeRole($this->server, 'Staff', 0);
        $this->overrideChannel($this->channel, $staff, allow: $this->viewBit());
        $member = $this->joinServer($this->server);

        $this->assertFalse($member->can('view', $this->channel));
    }

    public function test_overrides_only_change_their_own_channel(): void
    {
        $other = $this->server->channels()->create(['name' => 'other', 'type' => Channel::TYPE_TEXT, 'position' => 1]);
        $muted = $this->makeRole($this->server, 'Muted', 0);
        $this->overrideChannel($this->channel, $muted, deny: $this->viewBit());
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $muted);

        $this->assertFalse($member->can('view', $this->channel));
        $this->assertTrue($member->can('view', $other));
    }

    public function test_a_server_wide_administrator_ignores_a_deny_on_their_role(): void
    {
        $admins = $this->makeRole($this->server, 'Admins', Permission::mask(Permission::Administrator));
        $this->overrideChannel($this->channel, $admins, deny: $this->viewBit() | $this->sendBit());
        $admin = $this->joinServer($this->server);
        $this->giveRole($admin, $admins);

        $this->assertTrue($admin->can('view', $this->channel));
        $this->assertTrue($admin->can('sendMessages', $this->channel));
    }

    public function test_non_members_get_nothing_whatever_the_overrides(): void
    {
        $this->overrideChannel($this->channel, $this->server->defaultRole(), allow: $this->viewBit() | $this->sendBit());

        $this->assertSame(0, $this->permissions->channelPermissions(User::factory()->create(), $this->channel));
    }

    public function test_visible_channels_agrees_with_the_policy_and_keeps_the_order(): void
    {
        $staff = $this->makeRole($this->server, 'Staff', 0);
        $muted = $this->makeRole($this->server, 'Muted', 0);
        $channels = collect([$this->channel]);
        foreach (range(1, 5) as $i) {
            $channels->push($this->server->channels()->create(['name' => "c{$i}", 'type' => Channel::TYPE_TEXT, 'position' => $i]));
        }
        $channels[1]->update(['is_private' => true]);
        $channels[2]->update(['is_private' => true]);
        $this->overrideChannel($channels[2], $staff, allow: $this->viewBit());
        $this->overrideChannel($channels[3], $muted, deny: $this->viewBit());
        $this->overrideChannel($channels[4], $this->server->defaultRole(), deny: $this->viewBit());
        $this->overrideChannel($channels[4], $staff, allow: $this->viewBit());
        $member = $this->joinServer($this->server);
        $this->giveRole($member, $staff);
        $this->giveRole($member, $muted);

        $all = $this->server->channels()->get();
        $visible = $this->permissions->visibleChannels($member, $this->server, $all);

        $expected = $all->filter(fn (Channel $channel): bool => $member->can('view', $channel))->pluck('name')->values()->all();
        $this->assertSame(['general', 'c2', 'c4', 'c5'], $expected);
        $this->assertSame($expected, $visible->pluck('name')->all());
    }

    public function test_visible_channels_uses_the_same_number_of_queries_however_many_channels(): void
    {
        $member = $this->joinServer($this->server);
        $staff = $this->makeRole($this->server, 'Staff', 0);
        $this->giveRole($member, $staff);

        $count = function () use ($member): int {
            $channels = $this->server->channels()->get();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->permissions->visibleChannels($member, $this->server, $channels);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $few = $count();
        foreach (range(1, 10) as $i) {
            $channel = $this->server->channels()->create(['name' => "c{$i}", 'type' => Channel::TYPE_TEXT, 'position' => $i, 'is_private' => true]);
            $this->overrideChannel($channel, $staff, allow: $this->viewBit());
        }

        $this->assertSame($few, $count());
    }

    public function test_visible_channels_drops_channels_of_other_servers(): void
    {
        $other = app(CreateServer::class)->create(User::factory()->create(), 'Elsewhere');
        $mixed = $this->server->channels()->get()->concat($other->channels()->get());

        $visible = $this->permissions->visibleChannels($this->owner, $this->server, $mixed);

        $this->assertSame([$this->channel->id], $visible->modelKeys());
    }

    public function test_set_channel_override_creates_updates_and_deletes_the_row(): void
    {
        $role = $this->makeRole($this->server, 'Staff', 0);
        $set = app(SetChannelOverride::class);

        $override = $set->set($this->channel, $role, ['ViewChannels' => 'allow', 'SendMessages' => 'deny']);
        $this->assertNotNull($override);
        $this->assertSame([$this->viewBit(), $this->sendBit()], [$override->allow, $override->deny]);

        // A flag left out keeps its state.
        $override = $set->set($this->channel, $role, ['ViewChannels' => 'inherit']);
        $this->assertNotNull($override);
        $this->assertSame([0, $this->sendBit()], [$override->allow, $override->deny]);
        $this->assertSame(ChannelPermissionOverride::INHERIT, $override->stateOf(Permission::ViewChannels));
        $this->assertSame(ChannelPermissionOverride::DENY, $override->stateOf(Permission::SendMessages));

        $this->assertNull($set->set($this->channel, $role, ['ViewChannels' => 'inherit', 'SendMessages' => 'inherit']));
        $this->assertSame(0, ChannelPermissionOverride::query()->count());
    }

    public function test_set_channel_override_ignores_flags_that_cannot_be_overridden(): void
    {
        $role = $this->makeRole($this->server, 'Staff', 0);

        $override = app(SetChannelOverride::class)->set($this->channel, $role, ['ManageMessages' => 'allow', 'ViewChannels' => 'allow']);

        $this->assertNotNull($override);
        $this->assertSame($this->viewBit(), $override->allow);
    }

    public function test_set_channel_override_refuses_a_role_of_another_server(): void
    {
        $other = app(CreateServer::class)->create(User::factory()->create(), 'Elsewhere');

        $this->expectException(InvalidArgumentException::class);

        app(SetChannelOverride::class)->set($this->channel, $other->defaultRole(), ['ViewChannels' => 'allow']);
    }

    public function test_set_channel_override_refuses_unknown_states(): void
    {
        $role = $this->makeRole($this->server, 'Staff', 0);

        $this->expectException(InvalidArgumentException::class);

        app(SetChannelOverride::class)->set($this->channel, $role, ['ViewChannels' => 'maybe']);
    }

    public function test_deleting_a_role_or_channel_removes_its_overrides(): void
    {
        $role = $this->makeRole($this->server, 'Staff', 0);
        $other = $this->server->channels()->create(['name' => 'other', 'type' => Channel::TYPE_TEXT, 'position' => 1]);
        $this->overrideChannel($this->channel, $role, allow: $this->viewBit());
        $this->overrideChannel($other, $this->server->defaultRole(), deny: $this->sendBit());

        $role->delete();
        $this->assertSame([(int) $other->id], ChannelPermissionOverride::query()->pluck('channel_id')->all());

        $other->delete();
        $this->assertSame(0, ChannelPermissionOverride::query()->count());
    }
}
