<?php

namespace Tests\Feature\DirectMessages;

use App\Actions\Conversations\ManageGroupConversation;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The edges of group DMs that GroupDirectMessageTest leaves out:
 * JSON answers of every action, the single-pick branch, ownership when
 * people leave or delete their account, broadcast failures, files of
 * deleted messages, names and the friend pickers.
 */
class GroupDirectMessageEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['name' => 'Alice', 'username' => 'alice']);
        $this->bob = User::factory()->create(['name' => 'Bob', 'username' => 'bob']);
        $this->carol = User::factory()->create(['name' => 'Carol', 'username' => 'carol']);
        $this->stranger = User::factory()->create(['name' => 'Stranger', 'username' => 'stranger']);

        $this->befriend($this->alice, $this->bob);
        // Stored the other way round: friendIds() must read both directions.
        $this->befriend($this->carol, $this->alice);
    }

    private function befriend(User $a, User $b): void
    {
        Friendship::factory()->accepted()->create(['user_id' => $a->id, 'friend_id' => $b->id]);
    }

    private function group(?string $name = null): Conversation
    {
        return app(ManageGroupConversation::class)->create($this->alice, [$this->bob->id, $this->carol->id], $name);
    }

    /**
     * @return list<User>
     */
    private function friendsOfAlice(int $count): array
    {
        return User::factory()->count($count)->create()->each(fn (User $user) => $this->befriend($this->alice, $user))->all();
    }

    /**
     * @return list<int>
     */
    private function participantIds(Conversation $conversation): array
    {
        return $conversation->participants()->orderBy('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function attachFile(Conversation $conversation, string $file): Message
    {
        $message = Message::factory()->inConversation($conversation)->create(['user_id' => $this->alice->id]);
        $path = 'attachments/conversations/'.$conversation->id.'/'.$file;
        Storage::disk(MessageAttachment::disk())->put($path, 'x');
        $message->attachments()->create([
            'disk' => MessageAttachment::disk(),
            'path' => $path,
            'original_name' => $file,
            'mime_type' => 'text/plain',
            'size' => 1,
        ]);

        return $message;
    }

    // --- Creating: the single-pick branch and limits ------------------------------

    public function test_picking_one_friend_as_json_answers_with_the_one_to_one_dm(): void
    {
        $this->actingAs($this->alice)
            ->postJson(route('dms.groups.store'), ['user_ids' => [$this->bob->id]])
            ->assertCreated()
            ->assertJsonPath('conversation_id', Conversation::sole()->id)
            ->assertJsonPath('status', null);

        $this->assertTrue(Conversation::sole()->isDirect());
    }

    public function test_picking_one_friend_twice_reuses_the_existing_dm(): void
    {
        $dm = Conversation::factory()->between($this->alice, $this->bob)->create();

        $this->actingAs($this->alice)
            ->post(route('dms.groups.store'), ['user_ids' => [$this->bob->id], 'name' => 'Ignored'])
            ->assertRedirect(route('dms.show', $dm));

        $this->assertSame(1, Conversation::count());
        $this->assertNull($dm->fresh()?->name);
    }

    public function test_picking_one_person_who_is_not_a_friend_fails_with_the_friends_message(): void
    {
        foreach ([$this->stranger->id, 999999] as $id) {
            $this->actingAs($this->alice)
                ->post(route('dms.groups.store'), ['user_ids' => [$id]])
                ->assertSessionHasErrors(['user_ids' => 'You can only add your friends to a group DM.']);
        }

        $this->assertSame(0, Conversation::count());
    }

    public function test_an_empty_pick_has_its_own_message(): void
    {
        $this->actingAs($this->alice)
            ->post(route('dms.groups.store'), ['user_ids' => [$this->alice->id, $this->alice->id]])
            ->assertSessionHasErrors(['user_ids' => 'Pick at least one friend.']);
    }

    public function test_more_than_twenty_ids_is_rejected_by_validation(): void
    {
        $ids = range(1000, 1020);

        $this->actingAs($this->alice)
            ->postJson(route('dms.groups.store'), ['user_ids' => $ids])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_ids');
    }

    public function test_a_name_of_exactly_100_characters_is_kept(): void
    {
        $name = str_repeat('a', Conversation::NAME_MAX_LENGTH);

        $this->actingAs($this->alice)
            ->post(route('dms.groups.store'), ['user_ids' => [$this->bob->id, $this->carol->id], 'name' => $name])
            ->assertSessionHasNoErrors();

        $this->assertSame($name, Conversation::sole()->name);
    }

    public function test_duplicate_ids_count_once_towards_the_limit(): void
    {
        $nine = array_map(fn (User $u): int => $u->id, $this->friendsOfAlice(9));

        $this->actingAs($this->alice)
            ->post(route('dms.groups.store'), ['user_ids' => [...$nine, ...$nine, $this->alice->id]])
            ->assertSessionHasNoErrors();

        $this->assertCount(10, Conversation::sole()->participants);
    }

    // --- Adding ----------------------------------------------------------------

    public function test_adding_several_people_says_how_many(): void
    {
        $group = $this->group();
        [$dave, $erin] = $this->friendsOfAlice(2);

        $this->actingAs($this->alice)
            ->post(route('dms.participants.store', $group), ['user_ids' => [$dave->id, $erin->id, $dave->id]])
            ->assertSessionHas('status', 'Added 2 people.');

        $this->assertCount(5, $this->participantIds($group));
    }

    public function test_adding_only_people_already_there_redirects_without_a_status(): void
    {
        $group = $this->group();

        $this->actingAs($this->alice)
            ->post(route('dms.participants.store', $group), ['user_ids' => [$this->bob->id]])
            ->assertRedirect(route('dms.show', $group))
            ->assertSessionMissing('status');

        $this->actingAs($this->alice)
            ->postJson(route('dms.participants.store', $group), ['user_ids' => [$this->carol->id]])
            ->assertOk()
            ->assertJsonPath('status', null);
    }

    public function test_adding_as_json_answers_with_the_status(): void
    {
        $group = $this->group();
        [$dave] = $this->friendsOfAlice(1);

        $this->actingAs($this->alice)
            ->postJson(route('dms.participants.store', $group), ['user_ids' => [$dave->id]])
            ->assertOk()
            ->assertExactJson([
                'conversation_id' => $group->id,
                'url' => route('dms.show', $group),
                'status' => 'Added 1 person.',
            ]);
    }

    public function test_adding_only_yourself_is_an_empty_pick(): void
    {
        $group = $this->group();

        $this->actingAs($this->bob)
            ->post(route('dms.participants.store', $group), ['user_ids' => [$this->bob->id]])
            ->assertSessionHasErrors(['user_ids' => 'Pick at least one friend.']);
    }

    public function test_a_full_group_still_accepts_people_already_in_it(): void
    {
        $group = $this->group();
        $seven = $this->friendsOfAlice(7);
        app(ManageGroupConversation::class)->add($this->alice, $group, array_map(fn (User $u): int => $u->id, $seven));
        $this->assertCount(10, $this->participantIds($group));

        $this->actingAs($this->alice)
            ->post(route('dms.participants.store', $group), ['user_ids' => [$this->bob->id]])
            ->assertSessionHasNoErrors();

        [$eleventh] = $this->friendsOfAlice(1);
        $this->actingAs($this->alice)
            ->post(route('dms.participants.store', $group), ['user_ids' => [$this->bob->id, $eleventh->id]])
            ->assertSessionHasErrors(['user_ids' => ManageGroupConversation::tooMany()]);
        $this->assertCount(10, $this->participantIds($group));
    }

    public function test_a_mixed_pick_with_one_non_friend_adds_nobody(): void
    {
        $group = $this->group();
        [$dave] = $this->friendsOfAlice(1);

        $this->actingAs($this->alice)
            ->post(route('dms.participants.store', $group), ['user_ids' => [$dave->id, $this->stranger->id]])
            ->assertSessionHasErrors('user_ids');

        $this->assertCount(3, $this->participantIds($group));
    }

    public function test_someone_removed_can_be_added_back(): void
    {
        $group = $this->group();
        $actions = app(ManageGroupConversation::class);

        $actions->remove($group, $this->bob);
        $this->assertSame(1, $actions->add($this->alice, $group, [$this->bob->id]));

        $this->assertContains($this->bob->id, $this->participantIds($group));
        $this->actingAs($this->bob)->get(route('dms.show', $group))->assertOk();
    }

    // --- Removing, leaving and ownership ------------------------------------------

    public function test_removing_someone_keeps_the_owner(): void
    {
        $group = $this->group();

        $this->actingAs($this->alice)->delete(route('dms.participants.destroy', [$group, $this->bob]));
        $group->refresh();
        $this->assertTrue($group->isOwnedBy($this->alice));

        $this->actingAs($this->carol)->delete(route('dms.participants.destroy', [$group, $this->carol]));
        $group->refresh();
        $this->assertTrue($group->isOwnedBy($this->alice));
    }

    public function test_an_ownerless_group_passes_ownership_to_the_longest_standing_member(): void
    {
        $group = $this->group();
        $dave = $this->friendsOfAlice(1)[0];
        app(ManageGroupConversation::class)->add($this->alice, $group, [$dave->id]);
        $group->forceFill(['owner_id' => null])->save();
        $group->participants()->where('user_id', $dave->id)->update(['created_at' => now()->subWeek()]);

        app(ManageGroupConversation::class)->remove($group, $this->bob);

        $this->assertTrue($group->fresh()?->isOwnedBy($dave));
    }

    public function test_when_the_owner_deletes_their_account_the_group_stays_without_an_owner(): void
    {
        $group = $this->group();

        $this->alice->delete();

        $fresh = $group->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->owner_id);
        $this->assertSame([$this->bob->id, $this->carol->id], $this->participantIds($group));

        // Nobody owns it, so nobody may remove others; leaving still works.
        $this->actingAs($this->bob)->delete(route('dms.participants.destroy', [$group, $this->carol]))->assertForbidden();
        $this->actingAs($this->bob)->delete(route('dms.participants.destroy', [$group, $this->bob]))->assertRedirect(route('friends.index'));
        $this->assertTrue($group->fresh()?->isOwnedBy($this->carol));
    }

    public function test_leaving_as_json_points_to_the_friends_page(): void
    {
        $group = $this->group();

        $this->actingAs($this->bob)
            ->deleteJson(route('dms.participants.destroy', [$group, $this->bob]))
            ->assertOk()
            ->assertExactJson([
                'conversation_id' => $group->id,
                'url' => route('friends.index'),
                // An unnamed group is named as the leaver saw it.
                'status' => 'You left Alice, Carol.',
            ]);
    }

    public function test_removing_as_json_answers_with_the_status(): void
    {
        $group = $this->group();

        $this->actingAs($this->alice)
            ->deleteJson(route('dms.participants.destroy', [$group, $this->carol]))
            ->assertOk()
            ->assertJsonPath('url', route('dms.show', $group))
            ->assertJsonPath('status', 'Carol was removed from the group.');
    }

    public function test_the_owner_removing_themselves_counts_as_leaving(): void
    {
        $group = $this->group('Squad');

        $this->actingAs($this->alice)
            ->delete(route('dms.participants.destroy', [$group, $this->alice]))
            ->assertRedirect(route('friends.index'))
            ->assertSessionHas('status', 'You left Squad.');
    }

    public function test_deleting_the_group_also_deletes_files_of_deleted_messages_but_not_other_conversations(): void
    {
        Storage::fake(MessageAttachment::disk());
        $group = Conversation::factory()->group()->create(['owner_id' => $this->alice->id]);
        $group->users()->attach([$this->alice->id]);
        $other = $this->group();

        $trashed = $this->attachFile($group, 'gone.txt');
        $trashed->delete();
        $this->attachFile($other, 'kept.txt');

        app(ManageGroupConversation::class)->remove($group, $this->alice);

        $this->assertNull($group->fresh());
        Storage::disk(MessageAttachment::disk())->assertMissing('attachments/conversations/'.$group->id.'/gone.txt');
        Storage::disk(MessageAttachment::disk())->assertExists('attachments/conversations/'.$other->id.'/kept.txt');
        $this->assertSame(1, $other->messages()->count());
    }

    // --- Renaming ------------------------------------------------------------------

    public function test_renaming_as_json_answers_with_the_status(): void
    {
        $group = $this->group();

        $this->actingAs($this->bob)
            ->patchJson(route('dms.update', $group), ['name' => 'Squad'])
            ->assertOk()
            ->assertJsonPath('status', 'Group renamed.');

        $this->assertSame('Squad', $group->fresh()?->name);
    }

    public function test_renaming_without_a_name_clears_it(): void
    {
        $group = $this->group('Squad');

        $this->actingAs($this->bob)->patch(route('dms.update', $group))->assertSessionHasNoErrors();

        $this->assertNull($group->fresh()?->name);
    }

    public function test_the_new_name_shows_on_the_group_page_and_in_the_sidebar(): void
    {
        $group = $this->group();

        $this->actingAs($this->alice)->patch(route('dms.update', $group), ['name' => 'Book club']);

        $this->actingAs($this->carol)->get(route('dms.show', $group))
            ->assertOk()
            ->assertSee('<title>Book club', false);
        $this->actingAs($this->bob)->get(route('friends.index'))->assertSee('Book club');
    }

    // --- Broadcasts ------------------------------------------------------------------

    public function test_a_broadcast_failure_does_not_break_the_actions(): void
    {
        Broadcast::shouldReceive('event')->andThrow(new \RuntimeException('Pusher is down'));
        $actions = app(ManageGroupConversation::class);

        $group = $actions->create($this->alice, [$this->bob->id, $this->carol->id], 'Squad');
        $actions->rename($group, 'Renamed');
        $this->assertSame(1, $actions->add($this->alice, $group, [$this->friendsOfAlice(1)[0]->id]));
        $this->assertTrue($actions->remove($group, $this->bob));

        $this->assertSame('Renamed', $group->fresh()?->name);
        $this->assertCount(3, $this->participantIds($group));
    }

    public function test_leaving_or_failing_actions_send_nothing(): void
    {
        $group = $this->group();
        Event::fake([ConversationUpdated::class]);

        $this->assertFalse(app(ManageGroupConversation::class)->remove($group, $this->stranger));
        $this->actingAs($this->bob)->post(route('dms.participants.store', $group), ['user_ids' => [$this->stranger->id]]);
        $this->actingAs($this->bob)->delete(route('dms.participants.destroy', [$group, $this->carol]));

        Event::assertNotDispatched(ConversationUpdated::class);
    }

    public function test_the_event_keeps_its_recipients_after_the_group_is_gone(): void
    {
        $group = $this->group();
        $event = new ConversationUpdated($group, [$this->stranger->id, $this->bob->id]);
        $group->delete();

        $this->assertSame(
            collect([$this->alice, $this->bob, $this->carol, $this->stranger])->map(fn (User $u): int => $u->id)->sort()->values()->all(),
            $event->recipientIds,
        );
        $this->assertCount(4, $event->broadcastOn());
    }

    // --- Messaging inside a group ------------------------------------------------------

    public function test_a_block_between_two_members_does_not_stop_them_writing_in_the_group(): void
    {
        $group = $this->group();
        Friendship::factory()->blocked()->create(['user_id' => $this->bob->id, 'friend_id' => $this->carol->id]);

        $this->actingAs($this->bob)->postJson(route('dms.messages.store', $group), ['content' => 'Hi'])->assertCreated();
        $this->actingAs($this->carol)->postJson(route('dms.messages.store', $group), ['content' => 'Hi'])->assertCreated();
    }

    // --- Names, member order and pickers ---------------------------------------------------

    public function test_a_group_with_only_you_is_called_after_you(): void
    {
        $group = Conversation::factory()->group()->create(['owner_id' => $this->alice->id, 'name' => null]);
        $group->users()->attach([$this->alice->id]);

        $this->assertSame('Alice', $group->load('users')->displayName($this->alice));
    }

    public function test_unnamed_groups_sort_the_names_case_insensitively(): void
    {
        $zed = User::factory()->create(['name' => 'zed']);
        $adam = User::factory()->create(['name' => 'Adam']);
        $this->befriend($this->alice, $zed);
        $this->befriend($this->alice, $adam);

        $group = app(ManageGroupConversation::class)->create($this->alice, [$zed->id, $adam->id]);

        $this->assertSame('Adam, zed', $group->load('users')->displayName($this->alice));
    }

    public function test_the_group_page_lists_the_owner_first(): void
    {
        $group = $this->group();
        $group->forceFill(['owner_id' => $this->carol->id])->save();

        $this->actingAs($this->bob)->get(route('dms.show', $group))
            ->assertSeeInOrder([
                'data-member-id="'.$this->carol->id.'"',
                'data-member-id="'.$this->alice->id.'"',
                'data-member-id="'.$this->bob->id.'"',
            ], false);
    }

    public function test_the_add_form_counts_one_spot_left(): void
    {
        $group = $this->group();
        app(ManageGroupConversation::class)->add($this->alice, $group, array_map(fn (User $u): int => $u->id, $this->friendsOfAlice(6)));
        $this->friendsOfAlice(1);

        $this->actingAs($this->alice)->get(route('dms.show', $group))->assertSee('1 spot left.');
    }

    public function test_invitable_friends_are_sorted_and_skip_the_members(): void
    {
        $zoe = User::factory()->create(['name' => 'Zoe']);
        $abe = User::factory()->create(['name' => 'Abe']);
        $this->befriend($zoe, $this->alice);
        $this->befriend($this->alice, $abe);
        $group = $this->group();

        $this->assertSame(
            ['Abe', 'Bob', 'Carol', 'Zoe'],
            ManageGroupConversation::invitableFriends($this->alice)->pluck('name')->all(),
        );
        $this->assertSame(
            ['Abe', 'Zoe'],
            ManageGroupConversation::invitableFriends($this->alice, $group)->pluck('name')->all(),
        );
        $this->assertTrue(ManageGroupConversation::invitableFriends($this->stranger)->isEmpty());
    }

    public function test_friend_ids_read_both_directions_and_skip_pending_and_blocked(): void
    {
        $pending = User::factory()->create();
        Friendship::factory()->create(['user_id' => $pending->id, 'friend_id' => $this->alice->id]);
        $blocked = User::factory()->create();
        Friendship::factory()->blocked()->create(['user_id' => $this->alice->id, 'friend_id' => $blocked->id]);

        $ids = ManageGroupConversation::friendIds($this->alice);
        sort($ids);

        $this->assertSame([$this->bob->id, $this->carol->id], $ids);
    }

    public function test_clean_name_and_picked_ids(): void
    {
        $this->assertNull(ManageGroupConversation::cleanName(null));
        $this->assertNull(ManageGroupConversation::cleanName("  \t "));
        $this->assertSame('Squad', ManageGroupConversation::cleanName(' Squad '));

        $this->assertSame(
            [$this->bob->id, $this->carol->id],
            ManageGroupConversation::pickedIds($this->alice, [(string) $this->bob->id, $this->carol->id, $this->alice->id, $this->bob->id]),
        );
    }
}
