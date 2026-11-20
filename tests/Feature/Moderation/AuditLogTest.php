<?php

namespace Tests\Feature\Moderation;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Events\MemberModerated;
use App\Models\AuditLogEntry;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\RealtimeSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * Moderation and settings changes go into the server's audit log
 * (who, what, when) through model observers, and the owner can read and
 * filter it.
 */
class AuditLogTest extends TestCase
{
    use GivesPermissions, RefreshDatabase;

    private User $owner;

    private Server $server;

    private Channel $channel;

    private User $moderator;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MemberModerated::class]);
        $this->mock(RealtimeSessions::class, function (MockInterface $mock): void {
            $mock->shouldReceive('disconnect');
        });

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Audit Server');
        /** @var Channel $channel */
        $channel = $this->server->channels()->firstOrFail();
        $this->channel = $channel;

        $this->moderator = $this->memberWith(
            $this->server,
            Permission::KickMembers,
            Permission::BanMembers,
            Permission::TimeoutMembers,
            Permission::ManageMessages,
            Permission::ManageChannels,
            Permission::ManageRoles,
            Permission::ManageServer,
        );
        $this->moderator->update(['name' => 'Mod']);
        $this->member = $this->joinServer($this->server, User::factory()->create(['name' => 'Member']));
    }

    /**
     * The one entry written so far, after checking there is exactly one.
     */
    private function onlyEntry(): AuditLogEntry
    {
        $entries = AuditLogEntry::query()->where('server_id', $this->server->id)->get();
        $this->assertCount(1, $entries, 'Expected exactly one audit log entry, got: '.$entries->pluck('action')->implode(', '));

        return $entries->firstOrFail();
    }

    // --- What gets recorded ---------------------------------------------

    public function test_creating_a_server_and_test_setup_leave_the_log_empty(): void
    {
        $this->assertSame(0, AuditLogEntry::query()->count());
    }

    public function test_a_kick_records_who_kicked_whom_and_when(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]))->assertRedirect();

        $entry = $this->onlyEntry();
        $this->assertSame(AuditLogEntry::MEMBER_KICK, $entry->action);
        $this->assertSame($this->moderator->id, (int) $entry->actor_id);
        $this->assertSame('user', $entry->target_type);
        $this->assertSame($this->member->id, $entry->target_id);
        $this->assertSame('Member', $entry->target_label);
        $this->assertTrue(now()->equalTo($entry->created_at));
    }

    public function test_leaving_is_not_a_kick(): void
    {
        $this->actingAs($this->member)->delete(route('servers.leave', $this->server))->assertRedirect();

        $this->assertSame(0, AuditLogEntry::query()->count());
    }

    public function test_a_ban_records_the_reason_once_and_its_message_purge_is_not_logged_per_message(): void
    {
        Message::factory()->count(3)->create(['channel_id' => $this->channel->id, 'user_id' => $this->member->id]);

        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $this->member]), [
            'reason' => 'Spamming',
            'delete_messages' => 3600,
        ])->assertRedirect();

        $entry = $this->onlyEntry();
        $this->assertSame(AuditLogEntry::MEMBER_BAN, $entry->action);
        $this->assertSame('Spamming', $entry->reason);
        $this->assertSame($this->member->id, $entry->target_id);
        $this->assertSame(3, Message::onlyTrashed()->count());
    }

    public function test_an_unban_is_recorded(): void
    {
        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $this->member]));
        $this->actingAs($this->moderator)->delete(route('bans.destroy', [$this->server, $this->member]))->assertRedirect();

        $this->assertSame(
            [AuditLogEntry::MEMBER_BAN, AuditLogEntry::MEMBER_UNBAN],
            AuditLogEntry::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_timeouts_and_their_early_end_are_recorded_with_the_end_time(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 3600]);
        $this->actingAs($this->moderator)->delete(route('members.timeout.destroy', [$this->server, $this->member]));
        // Nothing to end: no entry.
        $this->actingAs($this->moderator)->delete(route('members.timeout.destroy', [$this->server, $this->member]));

        $entries = AuditLogEntry::query()->orderBy('id')->get();
        $this->assertSame([AuditLogEntry::MEMBER_TIMEOUT, AuditLogEntry::MEMBER_TIMEOUT_REMOVE], $entries->pluck('action')->all());
        $this->assertSame(now()->addHour()->toIso8601String(), $entries[0]->details['timed_out_until']['new'] ?? null);
        $this->assertNull($this->server->membershipOf($this->member)?->timed_out_until);
    }

    public function test_renaming_the_server_records_old_and_new_name(): void
    {
        $this->actingAs($this->moderator)->patch(route('servers.update', $this->server), ['name' => 'Renamed'])->assertRedirect();

        $entry = $this->onlyEntry();
        $this->assertSame(AuditLogEntry::SERVER_UPDATE, $entry->action);
        $this->assertSame(['Name: Audit Server → Renamed'], $entry->changeLines());
    }

    public function test_saving_the_server_without_changes_records_nothing(): void
    {
        $this->actingAs($this->moderator)->patch(route('servers.update', $this->server), ['name' => 'Audit Server'])->assertRedirect();

        $this->assertSame(0, AuditLogEntry::query()->count());
    }

    public function test_transferring_ownership_is_recorded(): void
    {
        $this->actingAs($this->owner)->patch(route('servers.owner.update', $this->server), [
            'new_owner_id' => $this->member->id,
            'confirm_name' => 'Audit Server',
        ])->assertRedirect();

        $entry = $this->onlyEntry();
        $this->assertSame(AuditLogEntry::SERVER_OWNER_TRANSFER, $entry->action);
        $this->assertSame($this->owner->id, (int) $entry->actor_id);
        $this->assertSame(['Owner: Owner → Member'], $entry->changeLines());
    }

    public function test_channel_create_update_and_delete_are_recorded_but_reordering_is_not(): void
    {
        $this->actingAs($this->moderator)->post(route('channels.store', $this->server), ['name' => 'rules'])->assertRedirect();
        /** @var Channel $rules */
        $rules = $this->server->channels()->where('name', 'rules')->firstOrFail();

        $this->actingAs($this->moderator)->patch(route('channels.update', [$this->server, $rules]), ['name' => 'the-rules', 'topic' => 'Read me'])->assertRedirect();
        $this->actingAs($this->moderator)->put(route('channels.layout', $this->server), [
            'uncategorized' => [$rules->id, $this->channel->id],
            'categories' => [],
        ]);
        $this->actingAs($this->moderator)->delete(route('channels.destroy', [$this->server, $rules]))->assertRedirect();

        $entries = AuditLogEntry::query()->orderBy('id')->get();
        $this->assertSame(
            [AuditLogEntry::CHANNEL_CREATE, AuditLogEntry::CHANNEL_UPDATE, AuditLogEntry::CHANNEL_DELETE],
            $entries->pluck('action')->all(),
        );
        $this->assertSame(['Name: rules → the-rules', 'Topic: none → Read me'], $entries[1]->changeLines());
        // The deleted channel keeps its last name in the log.
        $this->assertSame('the-rules', $entries[2]->target_label);
    }

    public function test_categories_are_recorded(): void
    {
        $this->actingAs($this->moderator)->post(route('categories.store', $this->server), ['name' => 'Info'])->assertRedirect();

        $entry = $this->onlyEntry();
        $this->assertSame(AuditLogEntry::CATEGORY_CREATE, $entry->action);
        $this->assertSame('Info', $entry->target_label);
    }

    public function test_role_changes_list_the_permissions_added_and_removed(): void
    {
        $role = $this->makeRole($this->server, 'Helper', Permission::mask(Permission::KickMembers), 0);

        $this->actingAs($this->owner)->patch(route('roles.update', [$this->server, $role]), [
            'name' => 'Helper',
            'permissions' => [Permission::BanMembers->value],
        ])->assertRedirect();

        $entries = AuditLogEntry::query()->orderBy('id')->get();
        $this->assertSame([AuditLogEntry::ROLE_UPDATE], $entries->pluck('action')->all());
        $this->assertSame(['Permissions: + Ban Members, − Kick Members'], $entries[0]->changeLines());
    }

    public function test_creating_a_role_over_http_is_recorded(): void
    {
        $this->actingAs($this->owner)->post(route('roles.store', $this->server), [
            'name' => 'Helper',
            'permissions' => [Permission::KickMembers->value],
        ])->assertRedirect();

        $entry = $this->onlyEntry();
        $this->assertSame(AuditLogEntry::ROLE_CREATE, $entry->action);
        $this->assertSame('Helper', $entry->target_label);
        $this->assertContains('Permissions: Kick Members', $entry->changeLines());
    }

    public function test_giving_and_taking_a_role_is_recorded(): void
    {
        $role = $this->makeRole($this->server, 'Helper', 0, 0);

        $this->actingAs($this->owner)->post(route('roles.members.store', [$this->server, $role]), ['user_id' => $this->member->id])->assertRedirect();
        $this->actingAs($this->owner)->delete(route('roles.members.destroy', [$this->server, $role, $this->member]))->assertRedirect();

        $entries = AuditLogEntry::query()->orderBy('id')->get();
        $this->assertSame([AuditLogEntry::MEMBER_ROLE_ADD, AuditLogEntry::MEMBER_ROLE_REMOVE], $entries->pluck('action')->all());
        $this->assertSame('Member', $entries[0]->target_label);
        $this->assertSame(['Role: Helper'], $entries[0]->changeLines());
    }

    public function test_channel_overrides_are_recorded_with_the_role(): void
    {
        $everyone = $this->server->defaultRole();

        $this->actingAs($this->owner)->put(route('channels.permissions.update', [$this->server, $this->channel, $everyone]), [
            'permissions' => ['ViewChannels' => 'deny', 'SendMessages' => 'inherit'],
        ])->assertRedirect();
        $this->actingAs($this->owner)->put(route('channels.permissions.update', [$this->server, $this->channel, $everyone]), [
            'permissions' => ['ViewChannels' => 'inherit', 'SendMessages' => 'inherit'],
        ])->assertRedirect();

        $entries = AuditLogEntry::query()->orderBy('id')->get();
        $this->assertSame([AuditLogEntry::CHANNEL_OVERRIDE_CREATE, AuditLogEntry::CHANNEL_OVERRIDE_DELETE], $entries->pluck('action')->all());
        $this->assertSame(['Role: @everyone', 'Allowed: none', 'Denied: View Channels'], $entries[0]->changeLines());
        $this->assertSame('general', $entries[0]->target_label);
    }

    public function test_a_moderator_deleting_someone_elses_message_is_recorded_but_deleting_your_own_is_not(): void
    {
        $theirs = Message::factory()->create(['channel_id' => $this->channel->id, 'user_id' => $this->member->id]);
        $mine = Message::factory()->create(['channel_id' => $this->channel->id, 'user_id' => $this->moderator->id]);

        $this->actingAs($this->moderator)->deleteJson(route('messages.destroy', [$this->server, $this->channel, $theirs]))->assertSuccessful();
        $this->actingAs($this->moderator)->deleteJson(route('messages.destroy', [$this->server, $this->channel, $mine]))->assertSuccessful();

        $entry = $this->onlyEntry();
        $this->assertSame(AuditLogEntry::MESSAGE_DELETE, $entry->action);
        $this->assertSame('Member', $entry->target_label);
        $this->assertSame(['Channel: #general'], $entry->changeLines());
    }

    public function test_invites_created_and_revoked_are_recorded(): void
    {
        $this->actingAs($this->member)->post(route('servers.invites.store', $this->server), ['expires_in' => 0, 'max_uses' => 5])->assertRedirect();
        $invite = $this->server->invites()->firstOrFail();
        $this->actingAs($this->owner)->delete(route('servers.invites.destroy', [$this->server, $invite]))->assertRedirect();

        $entries = AuditLogEntry::query()->orderBy('id')->get();
        $this->assertSame([AuditLogEntry::INVITE_CREATE, AuditLogEntry::INVITE_DELETE], $entries->pluck('action')->all());
        $this->assertSame($this->member->id, (int) $entries[0]->actor_id);
        $this->assertSame(['Max uses: 5', 'Expires: never'], $entries[0]->changeLines());
    }

    public function test_changes_without_a_signed_in_user_are_not_recorded(): void
    {
        $this->channel->update(['name' => 'console-rename']);

        $this->assertSame(0, AuditLogEntry::query()->count());
    }

    public function test_without_recording_pauses_the_log_and_resumes_after_an_exception(): void
    {
        $this->actingAs($this->owner);
        $log = app(AuditLog::class);

        try {
            $log->withoutRecording(function (): void {
                $this->channel->update(['name' => 'quiet']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertTrue($log->isRecording());
        $this->channel->update(['name' => 'loud']);

        $this->assertSame(['Name: quiet → loud'], $this->onlyEntry()->changeLines());
    }

    public function test_entries_go_with_the_server_and_outlive_their_actor(): void
    {
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));
        $this->moderator->delete();

        $entry = $this->onlyEntry();
        $this->assertNull($entry->actor_id);

        $this->actingAs($this->owner)->get(route('audit-log.index', $this->server))->assertOk()->assertSee('Deleted User');

        $this->server->delete();
        $this->assertSame(0, AuditLogEntry::query()->count());
    }

    // --- The page --------------------------------------------------------

    public function test_the_owner_sees_the_log_newest_first(): void
    {
        $this->actingAs($this->moderator)->post(route('members.ban', [$this->server, $this->member]), ['reason' => '<b>rude</b>']);
        $this->actingAs($this->moderator)->patch(route('channels.update', [$this->server, $this->channel]), ['name' => 'lobby']);

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', $this->server))
            ->assertOk()
            ->assertSeeInOrder(['Update channel', '#lobby', 'Name: general → lobby', 'Ban member', 'Member'])
            ->assertSee('Reason: &lt;b&gt;rude&lt;/b&gt;', false)
            ->assertDontSee('<b>rude</b>', false);
    }

    public function test_only_the_owner_can_open_the_log_not_even_an_administrator(): void
    {
        $admin = $this->memberWith($this->server, Permission::Administrator);
        $outsider = User::factory()->create();

        foreach ([$admin, $this->moderator, $this->member, $outsider] as $user) {
            $this->actingAs($user)->get(route('audit-log.index', $this->server))->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('audit-log.index', $this->server))->assertRedirect(route('login'));
    }

    public function test_the_sidebar_links_to_the_log_for_the_owner_only(): void
    {
        $url = route('audit-log.index', $this->server);

        $this->actingAs($this->owner)->get(route('channels.show', [$this->server, $this->channel]))->assertSee($url, false);
        $this->actingAs($this->moderator)->get(route('channels.show', [$this->server, $this->channel]))->assertDontSee($url, false);
    }

    public function test_the_log_filters_by_action_and_by_user(): void
    {
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));
        $this->actingAs($this->owner)->patch(route('servers.update', $this->server), ['name' => 'By Owner']);
        $this->actingAs($this->moderator)->patch(route('servers.update', $this->server), ['name' => 'By Mod']);

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'action' => AuditLogEntry::SERVER_UPDATE]))
            ->assertOk()
            ->assertSee('Name: Audit Server → By Owner')
            ->assertSee('Name: By Owner → By Mod')
            ->assertDontSee('data-action="member.kick"', false);

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'user' => $this->owner->id]))
            ->assertOk()
            ->assertSee('Name: Audit Server → By Owner')
            ->assertDontSee('Name: By Owner → By Mod')
            ->assertDontSee('data-action="member.kick"', false);

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'user' => $this->owner->id, 'action' => AuditLogEntry::MEMBER_KICK]))
            ->assertOk()
            ->assertSee('Nothing matches these filters.');
    }

    public function test_the_user_filter_lists_only_people_who_made_changes(): void
    {
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', $this->server))
            ->assertSee('<option value="'.$this->moderator->id.'"', false)
            ->assertDontSee('<option value="'.$this->member->id.'"', false);
    }

    public function test_invalid_filters_show_an_error_and_no_entries(): void
    {
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'action' => 'drop.tables', 'user' => $this->member->id]))
            ->assertOk()
            ->assertSee('Choose an action from the list.')
            ->assertSee('Choose a user from the list.')
            ->assertDontSee('data-audit-entry', false);
    }

    public function test_another_servers_entries_never_show(): void
    {
        $other = Server::factory()->create();
        AuditLogEntry::factory()->create(['server_id' => $other->id, 'target_label' => 'elsewhere']);

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', $this->server))
            ->assertOk()
            ->assertSee('Nothing has been logged yet.')
            ->assertDontSee('elsewhere');
    }

    public function test_the_log_is_paginated(): void
    {
        AuditLogEntry::factory()->count(51)->create(['server_id' => $this->server->id, 'actor_id' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'action' => AuditLogEntry::CHANNEL_UPDATE]))
            ->assertOk()
            ->assertSee('Page 1 of 2')
            ->assertSee('action=channel.update', false);

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'page' => 2]))
            ->assertOk()
            ->assertSee('Page 2 of 2');
    }

    // --- Formatting ------------------------------------------------------

    public function test_change_lines_format_each_kind_of_value(): void
    {
        $entry = new AuditLogEntry(['action' => AuditLogEntry::CHANNEL_UPDATE, 'details' => [
            'is_private' => ['old' => false, 'new' => true],
            'color' => ['old' => '#ff0000', 'new' => null],
            'permissions' => ['old' => 5, 'new' => 5],
            'icon' => ['new' => 'removed'],
            'something_else' => ['new' => ['a' => 1]],
        ]]);

        $this->assertSame([
            'Private: no → yes',
            'Colour: #ff0000 → default',
            'Permissions: no change',
            'Icon: removed',
            'Something else: {"a":1}',
        ], $entry->changeLines());
        $this->assertSame('Update channel', $entry->actionLabel());
        $this->assertSame('unknown.thing', (new AuditLogEntry(['action' => 'unknown.thing']))->actionLabel());
    }
}
