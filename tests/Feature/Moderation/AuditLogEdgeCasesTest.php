<?php

namespace Tests\Feature\Moderation;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Events\MemberModerated;
use App\Models\AuditLogEntry;
use App\Models\Channel;
use App\Models\ChannelCategory;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\RealtimeSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * Edge cases: cascades that must not flood the log, the actions
 * AuditLogTest does not walk through, failed requests that must leave no
 * trace, AuditLog's own contract and the details of the page.
 */
class AuditLogEdgeCasesTest extends TestCase
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

        Storage::fake('public');
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
            Permission::ManageServer,
        );
        $this->moderator->update(['name' => 'Mod']);
        $this->member = $this->joinServer($this->server, User::factory()->create(['name' => 'Member']));
    }

    /**
     * @return list<string>
     */
    private function actions(): array
    {
        return AuditLogEntry::query()->where('server_id', $this->server->id)->orderBy('id')->pluck('action')->all();
    }

    private function lastEntry(): AuditLogEntry
    {
        return AuditLogEntry::query()->where('server_id', $this->server->id)->orderByDesc('id')->firstOrFail();
    }

    // --- Server creation -------------------------------------------------

    public function test_creating_a_server_over_http_while_signed_in_logs_nothing(): void
    {
        $this->actingAs($this->owner)
            ->post(route('servers.store'), ['name' => 'Fresh', 'icon' => UploadedFile::fake()->image('i.png', 64, 64)])
            ->assertRedirect();

        $fresh = Server::query()->where('name', 'Fresh')->firstOrFail();
        $this->assertNotNull($fresh->icon_path);
        $this->assertSame(0, AuditLogEntry::query()->where('server_id', $fresh->id)->count());
        $this->assertTrue(app(AuditLog::class)->isRecording());
    }

    // --- Cascades that must not flood the log ----------------------------

    public function test_deleting_a_channel_logs_one_entry_not_one_per_message_or_override(): void
    {
        Message::factory()->count(3)->create(['channel_id' => $this->channel->id, 'user_id' => $this->member->id]);
        $this->overrideChannel($this->channel, $this->server->defaultRole(), 0, Permission::mask(Permission::SendMessages));

        $this->actingAs($this->moderator)->delete(route('channels.destroy', [$this->server, $this->channel]))->assertRedirect();

        $this->assertSame([AuditLogEntry::CHANNEL_DELETE], $this->actions());
        $this->assertSame('general', $this->lastEntry()->target_label);
    }

    public function test_deleting_a_category_logs_only_the_delete_not_its_channels_moving_out(): void
    {
        $category = ChannelCategory::query()->create(['server_id' => $this->server->id, 'name' => 'Info', 'position' => 0]);
        $this->channel->update(['category_id' => $category->id]);

        $this->actingAs($this->moderator)->delete(route('categories.destroy', [$this->server, $category]))->assertRedirect();

        $this->assertSame([AuditLogEntry::CATEGORY_DELETE], $this->actions());
        $this->assertNull($this->channel->fresh()?->category_id);
    }

    public function test_deleting_a_role_logs_one_entry_not_its_overrides_or_members(): void
    {
        $role = $this->makeRole($this->server, 'Helper', 0);
        $this->giveRole($this->member, $role);
        $this->overrideChannel($this->channel, $role, Permission::mask(Permission::SendMessages));

        $this->actingAs($this->owner)->delete(route('roles.destroy', [$this->server, $role]))->assertRedirect();

        $this->assertSame([AuditLogEntry::ROLE_DELETE], $this->actions());
        $this->assertSame('Helper', $this->lastEntry()->target_label);
        $this->assertSame('role', $this->lastEntry()->target_type);
    }

    // --- Actions AuditLogTest does not cover ------------------------------

    public function test_renaming_a_category_is_logged_but_moving_it_is_not(): void
    {
        $category = ChannelCategory::query()->create(['server_id' => $this->server->id, 'name' => 'Info', 'position' => 0]);

        $this->actingAs($this->moderator)->patch(route('categories.update', [$this->server, $category]), ['name' => 'News'])->assertRedirect();
        $category->update(['position' => 5]);

        $this->assertSame([AuditLogEntry::CATEGORY_UPDATE], $this->actions());
        $this->assertSame(['Name: Info → News'], $this->lastEntry()->changeLines());
    }

    public function test_moving_a_channel_into_a_category_is_not_logged(): void
    {
        $this->actingAs($this->moderator);
        $category = ChannelCategory::query()->create(['server_id' => $this->server->id, 'name' => 'Info', 'position' => 0]);
        AuditLogEntry::query()->delete();

        $this->channel->update(['category_id' => $category->id, 'position' => 3]);

        $this->assertSame([], $this->actions());
    }

    public function test_making_a_channel_private_is_logged_as_a_channel_update(): void
    {
        $this->actingAs($this->owner)
            ->patch(route('channels.privacy.update', [$this->server, $this->channel]), ['is_private' => '1'])
            ->assertRedirect();

        $this->assertSame([AuditLogEntry::CHANNEL_UPDATE], $this->actions());
        $this->assertSame(['Private: no → yes'], $this->lastEntry()->changeLines());
    }

    public function test_changing_an_existing_channel_override_lists_what_moved(): void
    {
        $everyone = $this->server->defaultRole();
        $url = route('channels.permissions.update', [$this->server, $this->channel, $everyone]);

        $this->actingAs($this->owner)->put($url, ['permissions' => ['ViewChannels' => 'inherit', 'SendMessages' => 'deny']])->assertRedirect();
        $this->actingAs($this->owner)->put($url, ['permissions' => ['ViewChannels' => 'inherit', 'SendMessages' => 'allow']])->assertRedirect();
        // Same again: nothing changed, nothing logged.
        $this->actingAs($this->owner)->put($url, ['permissions' => ['ViewChannels' => 'inherit', 'SendMessages' => 'allow']])->assertRedirect();

        $this->assertSame([AuditLogEntry::CHANNEL_OVERRIDE_CREATE, AuditLogEntry::CHANNEL_OVERRIDE_UPDATE], $this->actions());
        $this->assertSame(
            ['Role: @everyone', 'Allowed: + Send Messages', 'Denied: − Send Messages'],
            $this->lastEntry()->changeLines(),
        );
    }

    public function test_role_colour_changes_are_logged_and_moving_a_role_is_not(): void
    {
        $low = $this->makeRole($this->server, 'Low', 0);
        $high = $this->makeRole($this->server, 'High', 0);

        $this->actingAs($this->owner)->patch(route('roles.update', [$this->server, $high]), [
            'name' => 'High',
            'color' => '#ff0000',
            'permissions' => [],
        ])->assertRedirect();
        $this->actingAs($this->owner)->post(route('roles.move', [$this->server, $high]), ['direction' => 'down'])->assertRedirect();

        $this->assertSame([AuditLogEntry::ROLE_UPDATE], $this->actions());
        $this->assertSame(['Colour: default → #ff0000'], $this->lastEntry()->changeLines());
        $this->assertGreaterThan((int) $high->fresh()?->position, (int) $low->fresh()?->position);
    }

    public function test_the_server_icon_is_logged_as_uploaded_or_removed_never_as_a_path(): void
    {
        $this->actingAs($this->owner)->patch(route('servers.update', $this->server), [
            'name' => 'Audit Server',
            'icon' => UploadedFile::fake()->image('i.png', 64, 64),
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->patch(route('servers.update', $this->server), [
            'name' => 'Audit Server',
            'remove_icon' => '1',
        ])->assertSessionHasNoErrors();

        $entries = AuditLogEntry::query()->orderBy('id')->get();
        $this->assertSame([AuditLogEntry::SERVER_UPDATE, AuditLogEntry::SERVER_UPDATE], $entries->pluck('action')->all());
        $this->assertSame(['Icon: uploaded'], $entries[0]->changeLines());
        $this->assertSame(['Icon: removed'], $entries[1]->changeLines());
        $this->assertStringNotContainsString('.png', (string) json_encode($entries[0]->details));
    }

    public function test_a_server_description_change_is_logged(): void
    {
        $this->actingAs($this->owner);
        $this->server->update(['description' => 'Hello']);

        $this->assertSame(['Description: none → Hello'], $this->lastEntry()->changeLines());
    }

    public function test_a_rename_and_an_ownership_transfer_in_one_save_are_two_entries(): void
    {
        $this->actingAs($this->owner);
        $this->server->update(['name' => 'Handed Over', 'owner_id' => $this->member->id]);

        $this->assertSame([AuditLogEntry::SERVER_UPDATE, AuditLogEntry::SERVER_OWNER_TRANSFER], $this->actions());
        $this->assertSame(['Owner: Owner → Member'], $this->lastEntry()->changeLines());
        $this->assertSame($this->member->id, $this->lastEntry()->target_id);
    }

    public function test_replacing_a_timeout_logs_a_second_timeout_with_the_new_end(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 60]);
        $this->actingAs($this->moderator)->put(route('members.timeout', [$this->server, $this->member]), ['duration' => 86400]);

        $this->assertSame([AuditLogEntry::MEMBER_TIMEOUT, AuditLogEntry::MEMBER_TIMEOUT], $this->actions());
        $this->assertSame(now()->addDay()->toIso8601String(), $this->lastEntry()->details['timed_out_until']['new'] ?? null);
    }

    public function test_an_invite_with_an_expiry_and_no_use_limit_is_described(): void
    {
        $this->travelTo(now()->startOfMinute());

        $this->actingAs($this->member)->post(route('servers.invites.store', $this->server), ['expires_in' => 60, 'max_uses' => 0])->assertRedirect();

        $this->assertSame(
            ['Max uses: unlimited', 'Expires: '.now()->addHour()->toDayDateTimeString()],
            $this->lastEntry()->changeLines(),
        );
    }

    public function test_using_an_invite_is_not_logged(): void
    {
        $this->actingAs($this->member)->post(route('servers.invites.store', $this->server), ['expires_in' => 0, 'max_uses' => 0]);
        $invite = $this->server->invites()->firstOrFail();
        AuditLogEntry::query()->delete();

        $this->actingAs(User::factory()->create())->post(route('invites.accept', $invite->code))->assertRedirect();

        $this->assertSame([], $this->actions());
    }

    public function test_the_owner_deleting_a_members_message_is_logged(): void
    {
        $message = Message::factory()->create(['channel_id' => $this->channel->id, 'user_id' => $this->member->id]);

        $this->actingAs($this->owner)->deleteJson(route('messages.destroy', [$this->server, $this->channel, $message]))->assertSuccessful();

        $entry = $this->lastEntry();
        $this->assertSame(AuditLogEntry::MESSAGE_DELETE, $entry->action);
        $this->assertSame($this->owner->id, (int) $entry->actor_id);
        $this->assertSame($this->member->id, $entry->target_id);
    }

    public function test_deleting_a_message_whose_author_is_gone_is_logged_as_deleted_user(): void
    {
        $message = Message::factory()->create(['channel_id' => $this->channel->id, 'user_id' => $this->member->id]);
        $this->member->delete();

        $this->actingAs($this->moderator)
            ->deleteJson(route('messages.destroy', [$this->server, $this->channel, $message->fresh()]))
            ->assertSuccessful();

        $entry = $this->lastEntry();
        $this->assertSame(AuditLogEntry::MESSAGE_DELETE, $entry->action);
        $this->assertSame('Deleted User', $entry->target_label);
        $this->assertNull($entry->target_id);
    }

    public function test_deleting_a_direct_message_is_never_logged(): void
    {
        $conversation = Conversation::factory()->between($this->owner, $this->member)->create();
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => $this->member->id]);

        $this->actingAs($this->owner);
        $message->delete();

        $this->assertSame(0, AuditLogEntry::query()->count());
    }

    // --- Failed requests leave no trace ----------------------------------

    public function test_forbidden_moderation_attempts_are_not_logged(): void
    {
        $other = $this->joinServer($this->server);

        $this->actingAs($this->member)->post(route('members.kick', [$this->server, $other]))->assertForbidden();
        $this->actingAs($this->member)->post(route('members.ban', [$this->server, $other]))->assertForbidden();
        $this->actingAs($this->member)->put(route('members.timeout', [$this->server, $other]), ['duration' => 60])->assertForbidden();
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->owner]))->assertForbidden();
        $this->actingAs($this->member)->patch(route('servers.update', $this->server), ['name' => 'Hacked'])->assertForbidden();

        $this->assertSame([], $this->actions());
    }

    public function test_a_member_failing_to_delete_someone_elses_message_is_not_logged(): void
    {
        $message = Message::factory()->create(['channel_id' => $this->channel->id, 'user_id' => $this->moderator->id]);

        $this->actingAs($this->member)->deleteJson(route('messages.destroy', [$this->server, $this->channel, $message]))->assertForbidden();

        $this->assertSame([], $this->actions());
    }

    public function test_an_update_rolled_back_by_its_transaction_leaves_no_entry(): void
    {
        $this->actingAs($this->owner);

        try {
            DB::transaction(function (): void {
                $this->channel->update(['name' => 'gone']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->actions());
        $this->assertSame('general', $this->channel->fresh()?->name);
    }

    // --- AuditLog itself ---------------------------------------------------

    public function test_an_explicit_actor_is_recorded_without_anyone_signed_in(): void
    {
        $entry = app(AuditLog::class)->record($this->server->id, AuditLogEntry::MEMBER_KICK, $this->member, 'Member', actor: $this->moderator);

        $this->assertNotNull($entry);
        $this->assertSame($this->moderator->id, (int) $entry->actor_id);
        $this->assertNull($entry->details);
        $this->assertNull($entry->reason);
    }

    public function test_an_explicit_actor_wins_over_the_signed_in_user(): void
    {
        $this->actingAs($this->owner);

        $entry = app(AuditLog::class)->record($this->server, AuditLogEntry::MEMBER_KICK, actor: $this->moderator);

        $this->assertSame($this->moderator->id, (int) $entry?->actor_id);
        $this->assertNull($entry?->target_type);
        $this->assertNull($entry?->target_id);
    }

    public function test_long_target_labels_are_cut_to_255_characters_without_splitting_a_character(): void
    {
        $this->actingAs($this->owner);

        $entry = app(AuditLog::class)->record($this->server, AuditLogEntry::ROLE_CREATE, targetLabel: str_repeat('é', 300));

        $this->assertSame(str_repeat('é', 255), $entry?->fresh()?->target_label);
    }

    public function test_an_unknown_target_model_falls_back_to_its_class_name(): void
    {
        $this->actingAs($this->owner);

        $entry = app(AuditLog::class)->record($this->server, AuditLogEntry::SERVER_UPDATE, $this->server->memberships()->firstOrFail());

        $this->assertSame('ServerMember', $entry?->target_type);
    }

    public function test_without_recording_nests_and_returns_the_callbacks_value(): void
    {
        $this->actingAs($this->owner);
        $log = app(AuditLog::class);

        $result = $log->withoutRecording(function () use ($log): string {
            $log->withoutRecording(fn () => $this->channel->update(['name' => 'inner']));
            $this->assertFalse($log->isRecording());
            $this->channel->update(['name' => 'outer']);

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertTrue($log->isRecording());
        $this->assertSame([], $this->actions());
    }

    public function test_entries_cannot_be_written_from_the_request_body(): void
    {
        $this->actingAs($this->moderator)->patch(route('servers.update', $this->server), [
            'name' => 'Renamed',
            'actor_id' => $this->owner->id,
            'action' => 'member.ban',
        ])->assertRedirect();

        $entry = $this->lastEntry();
        $this->assertSame(AuditLogEntry::SERVER_UPDATE, $entry->action);
        $this->assertSame($this->moderator->id, (int) $entry->actor_id);
    }

    // --- The page ------------------------------------------------------------

    public function test_the_filters_keep_their_selection(): void
    {
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'action' => AuditLogEntry::MEMBER_KICK, 'user' => $this->moderator->id]))
            ->assertOk()
            ->assertSee('<option value="'.AuditLogEntry::MEMBER_KICK.'" selected', false)
            ->assertSee('<option value="'.$this->moderator->id.'" selected', false)
            ->assertSee('data-action="member.kick"', false);
    }

    public function test_empty_filters_show_everything(): void
    {
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'action' => '', 'user' => '']))
            ->assertOk()
            ->assertSee('data-action="member.kick"', false)
            ->assertDontSee('Choose an');
    }

    public function test_array_and_non_numeric_filters_are_errors_not_crashes(): void
    {
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));
        $base = route('audit-log.index', $this->server);

        $this->actingAs($this->owner)->get($base.'?action[]=member.kick&user[]=1')
            ->assertOk()
            ->assertDontSee('data-audit-entry', false)
            ->assertSee('aria-invalid="true"', false);

        $this->actingAs($this->owner)->get($base.'?user=abc')
            ->assertOk()
            ->assertDontSee('data-audit-entry', false)
            ->assertSee('audit-user-error', false);
    }

    public function test_an_actor_who_has_left_can_still_be_filtered_by(): void
    {
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));
        $this->actingAs($this->moderator)->delete(route('servers.leave', $this->server))->assertRedirect();

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'user' => $this->moderator->id]))
            ->assertOk()
            ->assertSee('<option value="'.$this->moderator->id.'"', false)
            ->assertSee('data-action="member.kick"', false);
    }

    public function test_an_actor_who_deleted_their_account_drops_out_of_the_user_filter(): void
    {
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));
        $id = $this->moderator->id;
        $this->moderator->delete();

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'user' => $id]))
            ->assertOk()
            ->assertSee('Choose a user from the list.')
            ->assertDontSee('<option value="'.$id.'"', false);
    }

    public function test_actors_from_other_servers_are_not_offered_or_accepted(): void
    {
        $stranger = User::factory()->create(['name' => 'Stranger']);
        AuditLogEntry::factory()->create(['server_id' => Server::factory()->create()->id, 'actor_id' => $stranger->id]);

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'user' => $stranger->id]))
            ->assertOk()
            ->assertSee('Choose a user from the list.')
            ->assertDontSee('Stranger');
    }

    public function test_only_channels_get_a_hash_before_their_name(): void
    {
        AuditLogEntry::factory()->create([
            'server_id' => $this->server->id, 'actor_id' => $this->owner->id,
            'action' => AuditLogEntry::ROLE_CREATE, 'target_type' => 'role', 'target_label' => 'Helper', 'details' => null,
        ]);
        AuditLogEntry::factory()->create([
            'server_id' => $this->server->id, 'actor_id' => $this->owner->id,
            'action' => AuditLogEntry::CHANNEL_CREATE, 'target_type' => 'channel', 'target_label' => 'rules', 'details' => null,
        ]);

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', $this->server))
            ->assertOk()
            ->assertSee('data-audit-target>#rules<', false)
            ->assertSee('data-audit-target>Helper<', false);
    }

    public function test_entries_show_a_machine_readable_time(): void
    {
        $this->travelTo(now()->setDateTime(2026, 11, 18, 21, 30, 0));
        $this->actingAs($this->moderator)->post(route('members.kick', [$this->server, $this->member]));

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', $this->server))
            ->assertSee('datetime="'.now()->toIso8601String().'"', false)
            ->assertSee('2026-11-18 21:30');
    }

    public function test_entries_with_the_same_timestamp_are_ordered_by_id(): void
    {
        $this->freezeTime();
        foreach (['first', 'second', 'third'] as $label) {
            AuditLogEntry::factory()->create(['server_id' => $this->server->id, 'actor_id' => $this->owner->id, 'target_label' => $label]);
        }

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', $this->server))
            ->assertSeeInOrder(['third', 'second', 'first']);
    }

    public function test_the_page_query_count_does_not_grow_with_entries_or_actors(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->get(route('audit-log.index', $this->server))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        AuditLogEntry::factory()->count(2)->create(['server_id' => $this->server->id]);
        $few = $count();

        AuditLogEntry::factory()->count(20)->create(['server_id' => $this->server->id]);
        $many = $count();

        $this->assertSame($few, $many);
    }

    public function test_a_page_past_the_end_says_nothing_has_been_logged(): void
    {
        AuditLogEntry::factory()->create(['server_id' => $this->server->id, 'actor_id' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'page' => 5]))
            ->assertOk()
            ->assertDontSee('data-audit-entry', false)
            ->assertSee('data-audit-log-empty', false);
    }

    public function test_an_invalid_page_number_does_not_crash(): void
    {
        AuditLogEntry::factory()->create(['server_id' => $this->server->id, 'actor_id' => $this->owner->id]);

        // Current behaviour: page fails validation, so no entries are listed;
        // the view has no error slot for `page`, so nothing explains why.
        $this->actingAs($this->owner)
            ->get(route('audit-log.index', [$this->server, 'page' => 0]))
            ->assertOk()
            ->assertDontSee('data-audit-entry', false);
    }

    public function test_the_page_is_not_reachable_for_another_servers_owner(): void
    {
        $otherOwner = User::factory()->create();
        app(CreateServer::class)->create($otherOwner, 'Elsewhere');

        $this->actingAs($otherOwner)->get(route('audit-log.index', $this->server))->assertForbidden();
    }

    public function test_a_new_owner_gains_the_log_and_the_old_owner_loses_it(): void
    {
        $this->actingAs($this->owner)->patch(route('servers.owner.update', $this->server), [
            'new_owner_id' => $this->member->id,
            'confirm_name' => 'Audit Server',
        ])->assertRedirect();

        $this->actingAs($this->owner)->get(route('audit-log.index', $this->server))->assertForbidden();
        $this->actingAs($this->member)
            ->get(route('audit-log.index', $this->server))
            ->assertOk()
            ->assertSee('data-action="server.owner_transfer"', false);
    }
}
