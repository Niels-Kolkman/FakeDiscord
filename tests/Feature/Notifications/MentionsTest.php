<?php

namespace Tests\Feature\Notifications;

use App\Actions\Messages\EditMessage;
use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Support\Mentions;
use App\Support\UnreadState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * ISSUE-33: who a message mentions (@username, @role, @everyone) is worked
 * out when it is saved (messages.mentions) and counts for the unread
 * mention badges.
 */
class MentionsTest extends TestCase
{
    use GivesPermissions;
    use RefreshDatabase;

    private User $owner;

    private User $bob;

    private Server $server;

    private Channel $general;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['username' => 'owner']);
        $this->bob = User::factory()->create(['username' => 'bob']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Mentions');
        $this->joinServer($this->server, $this->bob);

        /** @var Channel $general */
        $general = $this->server->channels()->where('type', Channel::TYPE_TEXT)->orderBy('position')->firstOrFail();
        $this->general = $general;
    }

    private function say(string $content, ?User $author = null, ?Channel $channel = null): Message
    {
        return Message::factory()->inChannel($channel ?? $this->general)->create([
            'user_id' => ($author ?? $this->owner)->id,
            'content' => $content,
        ]);
    }

    // --- Stored mentions -------------------------------------------------------

    public function test_a_message_without_mentions_stores_null(): void
    {
        $this->assertNull($this->say('hello there')->mentions);
        $this->assertNull($this->say('mail me@bob or @nobody')->mentions);
    }

    public function test_usernames_of_members_are_stored(): void
    {
        $message = $this->say('hey @Bob and @owner.');

        $this->assertSame(['users' => [$this->owner->id, $this->bob->id], 'roles' => [], 'everyone' => false], $message->fresh()?->mentions);
    }

    public function test_users_who_are_not_members_are_not_stored(): void
    {
        User::factory()->create(['username' => 'stranger']);

        $this->assertNull($this->say('hi @stranger')->mentions);
    }

    public function test_roles_of_the_server_are_stored_with_spaces_and_any_case(): void
    {
        $mods = $this->makeRole($this->server, 'Mods', 0);
        $night = $this->makeRole($this->server, 'Game Night', 0);
        $this->makeRole($this->server, 'Modsquad', 0);
        $other = app(CreateServer::class)->create($this->owner, 'Elsewhere');
        $this->makeRole($other, 'Elsewhere Role', 0);

        $message = $this->say('@mods, who is up for @game night? @Elsewhere Role');

        $this->assertSame(['users' => [], 'roles' => [$mods->id, $night->id], 'everyone' => false], $message->mentions);
    }

    public function test_the_everyone_role_is_never_a_role_mention(): void
    {
        $this->assertNull($this->say('@everyone', $this->bob)->mentions);
    }

    public function test_everyone_needs_the_mention_everyone_permission(): void
    {
        $this->assertNull($this->say('@everyone look', $this->bob)->mentions);
        $this->assertTrue($this->say('@everyone look', $this->owner)->mentions['everyone']);

        $herald = $this->memberWith($this->server, Permission::MentionEveryone);
        $this->assertTrue($this->say('@everyone look', $herald)->mentions['everyone']);
    }

    public function test_everyone_and_here_are_not_usernames(): void
    {
        $this->assertSame([], Mentions::usernames('@everyone @here'));
    }

    public function test_dm_messages_store_no_mentions(): void
    {
        $conversation = Conversation::factory()->create();
        $message = Message::factory()->create([
            'channel_id' => null,
            'conversation_id' => $conversation->id,
            'user_id' => $this->owner->id,
            'content' => '@bob',
        ]);

        $this->assertNull($message->mentions);
    }

    public function test_mentions_are_worked_out_again_when_the_content_changes(): void
    {
        $message = $this->say('hi all');

        app(EditMessage::class)->edit($message, 'hi @bob');
        $this->assertSame([$this->bob->id], $message->refresh()->mentions['users'] ?? null);

        app(EditMessage::class)->edit($message, 'hi nobody');
        $this->assertNull($message->refresh()->mentions);
    }

    public function test_mentions_cannot_be_mass_assigned(): void
    {
        $message = $this->say('no mentions');
        $message->fill(['mentions' => ['users' => [$this->bob->id]]])->save();

        $this->assertNull($message->fresh()?->mentions);
    }

    public function test_a_message_without_an_at_sign_costs_no_extra_query(): void
    {
        DB::enableQueryLog();
        $this->say('plain text');

        // The insert only.
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_includes_checks_users_roles_and_everyone(): void
    {
        $this->assertFalse(Mentions::includes(null, 1));
        $this->assertTrue(Mentions::includes(['users' => [1], 'roles' => [], 'everyone' => false], 1));
        $this->assertFalse(Mentions::includes(['users' => [2], 'roles' => [5], 'everyone' => false], 1));
        $this->assertTrue(Mentions::includes(['users' => [], 'roles' => [5], 'everyone' => false], 1, [4, 5]));
        $this->assertTrue(Mentions::includes(['users' => [], 'roles' => [], 'everyone' => true], 1));
    }

    // --- Unread badges ---------------------------------------------------------

    private function state(User $viewer): UnreadState
    {
        return UnreadState::forChannels($viewer, $this->server->channelLayout($viewer)->channels());
    }

    public function test_a_role_you_hold_counts_as_a_mention(): void
    {
        $mods = $this->makeRole($this->server, 'Mods', 0);
        $this->giveRole($this->bob, $mods);
        $this->makeRole($this->server, 'Admins', 0);

        $this->say('@mods please');
        $this->say('@admins please');

        $this->assertSame(1, $this->state($this->bob)->mentions($this->general));
    }

    public function test_everyone_counts_when_the_author_may_use_it(): void
    {
        $carol = $this->joinServer($this->server);
        $this->say('@everyone meeting');
        $this->say('@everyone spam', $carol);

        $this->assertSame(1, $this->state($this->bob)->mentions($this->general));
    }

    public function test_the_role_lookup_only_happens_when_a_role_was_mentioned(): void
    {
        $mods = $this->makeRole($this->server, 'Mods', 0);
        $this->giveRole($this->bob, $mods);
        $channels = $this->server->channelLayout($this->bob)->channels();

        $this->say('@bob hi');
        DB::enableQueryLog();
        UnreadState::forChannels($this->bob, $channels);
        $this->assertCount(2, DB::getQueryLog());

        $this->say('@mods hi');
        DB::flushQueryLog();
        $state = UnreadState::forChannels($this->bob, $channels);
        $this->assertCount(3, DB::getQueryLog());
        $this->assertSame(2, $state->mentions($this->general));
    }

    // --- Page ------------------------------------------------------------------

    public function test_messages_that_mention_you_are_highlighted(): void
    {
        $mention = $this->say('@bob look');
        $plain = $this->say('nothing here');

        $html = (string) $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->general]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="message-'.$mention->id.'"[^>]*data-mentions-viewer/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="message-'.$plain->id.'"[^>]*data-mentions-viewer/', $html);
    }

    public function test_the_page_tells_the_scripts_the_viewers_roles_and_the_suggestions_url(): void
    {
        $mods = $this->makeRole($this->server, 'Mods', 0);
        $this->giveRole($this->bob, $mods);

        $this->actingAs($this->bob)->get(route('channels.show', [$this->server, $this->general]))
            ->assertOk()
            ->assertSee('data-viewer-role-ids="'.e((string) json_encode([$mods->id])).'"', false)
            ->assertSee('data-mentions-url="'.route('mentions.index', [$this->server, $this->general]).'"', false)
            ->assertSee('data-mention-suggestions', false);
    }

    public function test_the_payload_carries_the_mentions(): void
    {
        $message = $this->say('@bob');

        $this->assertSame(['users' => [$this->bob->id], 'roles' => [], 'everyone' => false], $message->toPayload()['mentions']);
    }

    public function test_everyone_and_here_are_reserved_usernames(): void
    {
        foreach (['everyone', 'here'] as $username) {
            $this->post('/register', [
                'name' => 'X',
                'username' => $username,
                'email' => "{$username}@example.test",
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
            ])->assertSessionHasErrors(['username' => 'This username is reserved.']);
        }
    }
}
