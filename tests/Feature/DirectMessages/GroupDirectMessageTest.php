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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ISSUE-24: group DMs. Create one with up to 10 people (friends only), add
 * and remove participants, leave, rename; the group page, the sidebar and
 * ConversationUpdated for everyone involved.
 */
class GroupDirectMessageTest extends TestCase
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
        $this->befriend($this->carol, $this->alice);
    }

    private function befriend(User $a, User $b): void
    {
        Friendship::factory()->accepted()->create(['user_id' => $a->id, 'friend_id' => $b->id]);
    }

    /**
     * Alice's group with Bob and Carol.
     */
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

    // --- Creating ------------------------------------------------------------

    public function test_a_user_creates_a_group_with_friends_and_lands_on_it(): void
    {
        $response = $this->actingAs($this->alice)->post(route('dms.groups.store'), [
            'user_ids' => [$this->bob->id, $this->carol->id],
            'name' => '  Squad  ',
        ]);

        $group = Conversation::sole();
        $response->assertRedirect(route('dms.show', $group));
        $this->assertTrue($group->isGroup());
        $this->assertSame('Squad', $group->name);
        $this->assertNull($group->direct_key);
        $this->assertTrue($group->isOwnedBy($this->alice));
        $this->assertSame([$this->alice->id, $this->bob->id, $this->carol->id], $this->participantIds($group));
    }

    public function test_creating_answers_json(): void
    {
        $this->actingAs($this->alice)
            ->postJson(route('dms.groups.store'), ['user_ids' => [$this->bob->id, $this->carol->id]])
            ->assertCreated()
            ->assertJsonPath('conversation_id', Conversation::sole()->id)
            ->assertJsonPath('url', route('dms.show', Conversation::sole()));
    }

    public function test_picking_one_friend_opens_the_one_to_one_dm(): void
    {
        $this->actingAs($this->alice)
            ->post(route('dms.groups.store'), ['user_ids' => [$this->bob->id, $this->alice->id, $this->bob->id]])
            ->assertRedirect();

        $this->assertTrue(Conversation::sole()->isDirect());
        $this->assertSame(Conversation::directKey($this->alice, $this->bob), Conversation::sole()->direct_key);
    }

    public function test_a_group_has_at_most_ten_people(): void
    {
        $nine = $this->friendsOfAlice(9);
        $this->actingAs($this->alice)
            ->post(route('dms.groups.store'), ['user_ids' => array_map(fn (User $u) => $u->id, $nine)])
            ->assertRedirect();
        $this->assertCount(10, Conversation::sole()->participants);

        Conversation::query()->delete();
        $ten = [...$nine, ...$this->friendsOfAlice(1)];
        $this->actingAs($this->alice)
            ->post(route('dms.groups.store'), ['user_ids' => array_map(fn (User $u) => $u->id, $ten)])
            ->assertSessionHasErrors(['user_ids' => ManageGroupConversation::tooMany()]);
        $this->assertSame(0, Conversation::count());
    }

    public function test_only_friends_can_be_added(): void
    {
        $pending = User::factory()->create();
        Friendship::factory()->create(['user_id' => $this->alice->id, 'friend_id' => $pending->id]);
        $blocked = User::factory()->create();
        Friendship::factory()->blocked()->create(['user_id' => $blocked->id, 'friend_id' => $this->alice->id]);

        foreach ([$this->stranger->id, $pending->id, $blocked->id, 999999] as $other) {
            $this->actingAs($this->alice)
                ->post(route('dms.groups.store'), ['user_ids' => [$this->bob->id, $other]])
                ->assertSessionHasErrors('user_ids');
        }

        $this->assertSame(0, Conversation::count());
    }

    public function test_creating_validates_its_input(): void
    {
        $this->actingAs($this->alice)->post(route('dms.groups.store'), [])->assertSessionHasErrors('user_ids');
        $this->actingAs($this->alice)->post(route('dms.groups.store'), ['user_ids' => [$this->alice->id]])->assertSessionHasErrors('user_ids');
        $this->actingAs($this->alice)->post(route('dms.groups.store'), ['user_ids' => ['abc']])->assertSessionHasErrors('user_ids.0');
        $this->actingAs($this->alice)
            ->post(route('dms.groups.store'), ['user_ids' => [$this->bob->id, $this->carol->id], 'name' => str_repeat('a', 101)])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Conversation::count());
    }

    public function test_guests_and_unverified_users_cannot_create_groups(): void
    {
        $this->post(route('dms.groups.store'), ['user_ids' => [$this->bob->id, $this->carol->id]])->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->postJson(route('dms.groups.store'), ['user_ids' => [1, 2]])->assertForbidden();
    }

    public function test_the_friends_page_has_the_create_form_on_the_all_tab(): void
    {
        $this->actingAs($this->alice)->get(route('friends.index'))
            ->assertOk()
            ->assertSee('action="'.route('dms.groups.store').'"', false)
            ->assertSee('data-pick-user="'.$this->bob->id.'"', false)
            ->assertSee('data-pick-user="'.$this->carol->id.'"', false);

        $this->actingAs($this->alice)->get(route('friends.index', ['tab' => 'pending']))
            ->assertDontSee('action="'.route('dms.groups.store').'"', false);
        $this->actingAs($this->stranger)->get(route('friends.index'))
            ->assertDontSee('action="'.route('dms.groups.store').'"', false);
    }

    // --- The group page and sidebar -------------------------------------------

    public function test_participants_see_the_group_page_with_its_people(): void
    {
        $group = $this->group('Squad');
        Message::factory()->inConversation($group)->create(['user_id' => $this->carol->id, 'content' => 'Hello group']);

        $this->actingAs($this->bob)->get(route('dms.show', $group))
            ->assertOk()
            ->assertSee('<title>Squad', false)
            ->assertSee('Hello group')
            ->assertSee('data-member-id="'.$this->alice->id.'"', false)
            ->assertSee('data-member-id="'.$this->carol->id.'"', false)
            ->assertSee('3 members')
            ->assertSee('action="'.route('dms.update', $group).'"', false)
            ->assertSee('action="'.route('dms.participants.destroy', [$group, $this->bob]).'"', false)
            // Bob is not the owner: no Remove for the others.
            ->assertDontSee('aria-label="Remove Carol from the group"', false)
            ->assertSee('data-message-form', false);

        $this->actingAs($this->alice)->get(route('dms.show', $group))
            ->assertSee('aria-label="Remove Carol from the group"', false)
            ->assertSee('aria-label="Remove Bob from the group"', false);
    }

    public function test_the_add_form_offers_only_friends_not_in_the_group(): void
    {
        $group = $this->group();
        [$dave] = $this->friendsOfAlice(1);

        $this->actingAs($this->alice)->get(route('dms.show', $group))
            ->assertSee('action="'.route('dms.participants.store', $group).'"', false)
            ->assertSee('data-pick-user="'.$dave->id.'"', false)
            ->assertDontSee('data-pick-user="'.$this->bob->id.'"', false)
            ->assertSee('7 spots left.');

        // Bob's only friend is Alice, who is already here.
        $this->actingAs($this->bob)->get(route('dms.show', $group))
            ->assertSee('All your friends are already here.');
    }

    public function test_outsiders_get_a_404_everywhere(): void
    {
        $group = $this->group();

        $this->actingAs($this->stranger)->get(route('dms.show', $group))->assertNotFound();
        $this->actingAs($this->stranger)->patch(route('dms.update', $group), ['name' => 'Mine'])->assertNotFound();
        $this->actingAs($this->stranger)->post(route('dms.participants.store', $group), ['user_ids' => [$this->bob->id]])->assertNotFound();
        $this->actingAs($this->stranger)->delete(route('dms.participants.destroy', [$group, $this->bob]))->assertNotFound();
        $this->actingAs($this->stranger)->postJson(route('dms.messages.store', $group), ['content' => 'Hi'])->assertNotFound();

        $this->assertSame(3, $group->participants()->count());
    }

    public function test_an_unnamed_group_is_called_after_the_other_people(): void
    {
        $group = $this->group()->load('users');

        $this->assertSame('Bob, Carol', $group->displayName($this->alice));
        $this->assertSame('Alice, Carol', $group->displayName($this->bob));
    }

    public function test_a_new_group_shows_in_every_participants_sidebar_with_no_messages_yet(): void
    {
        $group = $this->group('Squad');

        foreach ([$this->alice, $this->bob, $this->carol] as $user) {
            $this->assertSame([$group->id], Conversation::sidebarFor($user)->pluck('id')->all());
        }
        $this->assertTrue(Conversation::sidebarFor($this->stranger)->isEmpty());

        $this->actingAs($this->bob)->get(route('friends.index'))
            ->assertSee('data-dm-id="'.$group->id.'"', false)
            ->assertSee('Squad');
    }

    public function test_everyone_in_the_group_can_send_messages(): void
    {
        $group = $this->group();

        foreach ([$this->alice, $this->bob, $this->carol] as $user) {
            $this->actingAs($user)->postJson(route('dms.messages.store', $group), ['content' => 'Hi from '.$user->name])->assertCreated();
        }

        $this->assertSame(3, $group->messages()->count());
        $this->assertNotNull($group->fresh()?->last_message_at);
    }

    // --- Adding ----------------------------------------------------------------

    public function test_any_participant_adds_their_friends(): void
    {
        $group = $this->group();
        $dave = User::factory()->create(['name' => 'Dave']);
        $this->befriend($this->bob, $dave);

        $this->actingAs($this->bob)
            ->post(route('dms.participants.store', $group), ['user_ids' => [$dave->id, $this->alice->id]])
            ->assertRedirect(route('dms.show', $group))
            ->assertSessionHas('status', 'Added 1 person.');

        $this->assertContains($dave->id, $this->participantIds($group));
        $this->assertCount(4, $this->participantIds($group));
    }

    public function test_adding_someone_who_is_not_your_friend_fails(): void
    {
        $group = $this->group();

        $this->actingAs($this->bob)
            ->post(route('dms.participants.store', $group), ['user_ids' => [$this->stranger->id]])
            ->assertSessionHasErrors('user_ids');

        $this->assertCount(3, $this->participantIds($group));
    }

    public function test_adding_stops_at_ten_people(): void
    {
        $group = $this->group();
        $friends = $this->friendsOfAlice(8);

        $this->actingAs($this->alice)
            ->post(route('dms.participants.store', $group), ['user_ids' => array_map(fn (User $u) => $u->id, $friends)])
            ->assertSessionHasErrors(['user_ids' => ManageGroupConversation::tooMany()]);
        $this->assertCount(3, $this->participantIds($group));

        array_pop($friends);
        $this->actingAs($this->alice)
            ->post(route('dms.participants.store', $group), ['user_ids' => array_map(fn (User $u) => $u->id, $friends)])
            ->assertSessionHasNoErrors();
        $this->assertCount(10, $this->participantIds($group));

        $this->actingAs($this->alice)->get(route('dms.show', $group))->assertSee('This group is full.');
    }

    public function test_people_cannot_be_added_to_a_one_to_one_dm(): void
    {
        $dm = Conversation::factory()->between($this->alice, $this->bob)->create();

        $this->actingAs($this->alice)
            ->post(route('dms.participants.store', $dm), ['user_ids' => [$this->carol->id]])
            ->assertNotFound();
        $this->actingAs($this->alice)->patch(route('dms.update', $dm), ['name' => 'Pair'])->assertNotFound();
        $this->actingAs($this->alice)->delete(route('dms.participants.destroy', [$dm, $this->bob]))->assertNotFound();

        $this->assertSame(2, $dm->participants()->count());
    }

    // --- Removing and leaving ----------------------------------------------------

    public function test_the_owner_removes_people(): void
    {
        $group = $this->group();

        $this->actingAs($this->alice)
            ->delete(route('dms.participants.destroy', [$group, $this->bob]))
            ->assertRedirect(route('dms.show', $group))
            ->assertSessionHas('status', 'Bob was removed from the group.');

        $this->assertSame([$this->alice->id, $this->carol->id], $this->participantIds($group));
        $this->actingAs($this->bob)->get(route('dms.show', $group))->assertNotFound();
        $this->actingAs($this->bob)->postJson(route('dms.messages.store', $group), ['content' => 'Still here?'])->assertNotFound();
    }

    public function test_others_cannot_remove_people(): void
    {
        $group = $this->group();

        $this->actingAs($this->bob)
            ->delete(route('dms.participants.destroy', [$group, $this->carol]))
            ->assertForbidden();
        $this->actingAs($this->bob)
            ->delete(route('dms.participants.destroy', [$group, $this->alice]))
            ->assertForbidden();

        $this->assertCount(3, $this->participantIds($group));
    }

    public function test_removing_someone_who_is_not_in_the_group_is_a_404(): void
    {
        $group = $this->group();

        $this->actingAs($this->alice)->delete(route('dms.participants.destroy', [$group, $this->stranger]))->assertNotFound();
        $this->actingAs($this->alice)->delete('/dms/'.$group->id.'/participants/999999')->assertNotFound();
    }

    public function test_anyone_can_leave(): void
    {
        $group = $this->group('Squad');

        $this->actingAs($this->bob)
            ->delete(route('dms.participants.destroy', [$group, $this->bob]))
            ->assertRedirect(route('friends.index'))
            ->assertSessionHas('status', 'You left Squad.');

        $this->assertSame([$this->alice->id, $this->carol->id], $this->participantIds($group));
        $this->assertTrue(Conversation::sidebarFor($this->bob)->isEmpty());
    }

    public function test_when_the_owner_leaves_the_longest_standing_member_takes_over(): void
    {
        $group = $this->group();
        $group->participants()->where('user_id', $this->carol->id)->update(['created_at' => now()->subDay()]);

        $this->actingAs($this->alice)->delete(route('dms.participants.destroy', [$group, $this->alice]))->assertRedirect(route('friends.index'));

        $this->assertTrue($group->fresh()?->isOwnedBy($this->carol));
        $this->actingAs($this->carol)->get(route('dms.show', $group))->assertSee('aria-label="Remove Bob from the group"', false);
    }

    public function test_an_ownerless_group_gets_a_new_owner_when_someone_leaves(): void
    {
        $group = $this->group();
        $group->forceFill(['owner_id' => null])->save();

        $this->actingAs($this->bob)->delete(route('dms.participants.destroy', [$group, $this->bob]));

        $this->assertNotNull($group->fresh()?->owner_id);
    }

    public function test_the_last_person_leaving_deletes_the_group_with_its_messages_and_files(): void
    {
        Storage::fake(MessageAttachment::disk());
        $group = $this->group();
        $message = Message::factory()->inConversation($group)->create(['user_id' => $this->bob->id]);
        $path = 'attachments/conversations/'.$group->id.'/a.txt';
        Storage::disk(MessageAttachment::disk())->put($path, 'x');
        $message->attachments()->create([
            'disk' => MessageAttachment::disk(),
            'path' => $path,
            'original_name' => 'a.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
        ]);

        $this->actingAs($this->alice)->delete(route('dms.participants.destroy', [$group, $this->bob]));
        $this->actingAs($this->alice)->delete(route('dms.participants.destroy', [$group, $this->carol]));
        $this->assertNotNull($group->fresh());
        Storage::disk(MessageAttachment::disk())->assertExists($path);

        $this->actingAs($this->alice)->delete(route('dms.participants.destroy', [$group, $this->alice]))->assertRedirect(route('friends.index'));

        $this->assertNull($group->fresh());
        $this->assertSame(0, Message::withTrashed()->count());
        Storage::disk(MessageAttachment::disk())->assertMissing($path);
    }

    public function test_leaving_twice_changes_nothing(): void
    {
        $group = $this->group();
        $actions = app(ManageGroupConversation::class);

        $this->assertTrue($actions->remove($group, $this->bob));
        $this->assertFalse($actions->remove($group, $this->bob));
        $this->assertCount(2, $this->participantIds($group));
    }

    // --- Renaming ------------------------------------------------------------------

    public function test_any_participant_renames_the_group(): void
    {
        $group = $this->group('Squad');

        $this->actingAs($this->carol)
            ->patch(route('dms.update', $group), ['name' => ' Book club '])
            ->assertRedirect(route('dms.show', $group))
            ->assertSessionHas('status', 'Group renamed.');

        $this->assertSame('Book club', $group->fresh()?->name);
    }

    public function test_an_empty_name_goes_back_to_the_peoples_names(): void
    {
        $group = $this->group('Squad');

        $this->actingAs($this->alice)->patch(route('dms.update', $group), ['name' => '   ']);

        $fresh = $group->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->name);
        $this->assertSame('Bob, Carol', $fresh->load('users')->displayName($this->alice));
    }

    public function test_a_name_is_at_most_100_characters(): void
    {
        $group = $this->group('Squad');

        $this->actingAs($this->alice)->patch(route('dms.update', $group), ['name' => str_repeat('a', 101)])->assertSessionHasErrors('name');
        $this->assertSame('Squad', $group->fresh()?->name);
    }

    // --- Broadcasts ------------------------------------------------------------------

    public function test_every_change_is_announced_to_everyone_involved(): void
    {
        Event::fake([ConversationUpdated::class]);
        $group = $this->group();
        $dave = $this->friendsOfAlice(1)[0];

        $this->actingAs($this->alice)->patch(route('dms.update', $group), ['name' => 'Squad']);
        $this->actingAs($this->alice)->post(route('dms.participants.store', $group), ['user_ids' => [$dave->id]]);
        $this->actingAs($this->alice)->delete(route('dms.participants.destroy', [$group, $this->bob]));

        $channels = fn (ConversationUpdated $event): array => collect($event->broadcastOn())->pluck('name')->sort()->values()->all();
        $names = fn (User ...$users): array => collect($users)->map(fn (User $u): string => 'private-App.Models.User.'.$u->id)->sort()->values()->all();

        // Creating, renaming, adding, removing.
        Event::assertDispatchedTimes(ConversationUpdated::class, 4);
        Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e): bool => $channels($e) === $names($this->alice, $this->bob, $this->carol));
        // Adding: Dave too. Removing Bob: Bob still hears about it.
        $withDave = Event::dispatched(ConversationUpdated::class, fn (ConversationUpdated $e): bool => $channels($e) === $names($this->alice, $this->bob, $this->carol, $dave));
        $this->assertCount(2, $withDave);
        Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e): bool => $e->broadcastAs() === 'conversation.updated'
            && $e->broadcastWith() === ['conversation_id' => $group->id]);
    }

    public function test_the_last_person_leaving_still_hears_about_it(): void
    {
        $group = Conversation::factory()->group()->create(['owner_id' => $this->alice->id]);
        $group->users()->attach([$this->alice->id]);

        Event::fake([ConversationUpdated::class]);
        app(ManageGroupConversation::class)->remove($group, $this->alice);

        Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e): bool => collect($e->broadcastOn())->pluck('name')->all() === ['private-App.Models.User.'.$this->alice->id]);
    }

    public function test_adding_nobody_new_sends_nothing(): void
    {
        $group = $this->group();
        Event::fake([ConversationUpdated::class]);

        $this->assertSame(0, app(ManageGroupConversation::class)->add($this->alice, $group, [$this->bob->id]));
        Event::assertNotDispatched(ConversationUpdated::class);
    }

    public function test_the_action_refuses_an_empty_pick(): void
    {
        $this->expectException(ValidationException::class);

        app(ManageGroupConversation::class)->create($this->alice, [$this->alice->id]);
    }
}
