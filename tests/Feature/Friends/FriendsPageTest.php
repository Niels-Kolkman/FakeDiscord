<?php

namespace Tests\Feature\Friends;

use App\Actions\Friends\ManageFriendship;
use App\Events\FriendshipChanged;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * ISSUE-22: the friends page and its All / Pending / Blocked tabs, the
 * request badges, the FriendshipChanged payload, and two clicks at once.
 */
class FriendsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => 'Me', 'username' => 'me']);
    }

    private function user(string $name, string $username, string $status = 'offline'): User
    {
        return User::factory()->create(['name' => $name, 'username' => $username, 'status' => $status]);
    }

    private function friendship(User $from, User $to, string $status = Friendship::STATUS_PENDING): Friendship
    {
        return Friendship::factory()->create(['user_id' => $from->id, 'friend_id' => $to->id, 'status' => $status]);
    }

    /**
     * The badge elements on a page: their text, and whether they are hidden.
     *
     * @return list<array{hidden: bool, text: string}>
     */
    private function badges(string $html): array
    {
        preg_match_all('/<span data-friend-request-badge(\s+hidden)?[^>]*>(\d+)<\/span>/', $html, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => ['hidden' => trim($match[1]) !== '', 'text' => $match[2]], $matches);
    }

    public function test_the_all_tab_lists_friends_from_both_directions_by_name(): void
    {
        $zoe = $this->user('Zoe', 'zoe', 'online');
        $adam = $this->user('adam', 'adam');
        $pending = $this->user('Pending Pete', 'pete');
        $this->friendship($this->me, $zoe, Friendship::STATUS_ACCEPTED);
        $this->friendship($adam, $this->me, Friendship::STATUS_ACCEPTED);
        $this->friendship($this->me, $pending);

        // Someone else's friendship is not listed.
        $this->friendship($zoe, $adam, Friendship::STATUS_ACCEPTED);

        $response = $this->actingAs($this->me)->get('/friends')
            ->assertOk()
            ->assertSee('All friends — 2')
            ->assertSeeInOrder(['adam', '@adam', 'Offline', 'Zoe', '@zoe', 'Online'])
            ->assertDontSee('Pending Pete')
            ->assertSee('aria-label="Remove Zoe from your friends"', false)
            ->assertSee('data-confirm="Remove Zoe from your friends?"', false);

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('friends.index', ['tab' => 'all']), '/').'"\s+aria-current="page"/',
            (string) $response->getContent(),
        );
    }

    public function test_the_all_tab_has_an_empty_state(): void
    {
        $this->actingAs($this->me)->get('/friends?tab=all')
            ->assertOk()
            ->assertSee('All friends — 0')
            ->assertSee('No friends yet.');
    }

    public function test_the_pending_tab_lists_incoming_and_outgoing_requests_with_their_actions(): void
    {
        $older = $this->user('Older', 'older');
        $newer = $this->user('Newer', 'newer');
        $asked = $this->user('Asked', 'asked');
        $friend = $this->user('Good Friend', 'goodfriend');

        $first = $this->friendship($older, $this->me);
        $first->forceFill(['created_at' => now()->subDay()])->save();
        $second = $this->friendship($newer, $this->me);
        $outgoing = $this->friendship($this->me, $asked);
        $this->friendship($this->me, $friend, Friendship::STATUS_ACCEPTED);

        $response = $this->actingAs($this->me)->get('/friends?tab=pending')
            ->assertOk()
            ->assertSeeInOrder(['Incoming — 2', 'Newer', 'Older', 'Outgoing — 1', 'Asked'])
            ->assertSee('action="'.route('friends.accept', $second).'"', false)
            ->assertSee('action="'.route('friends.decline', $first).'"', false)
            ->assertSee('action="'.route('friends.cancel', $outgoing).'"', false)
            ->assertSee('aria-label="Accept friend request from Newer"', false)
            ->assertSee('aria-label="Cancel friend request to Asked"', false)
            ->assertDontSee('Good Friend');

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('friends.index', ['tab' => 'pending']), '/').'"\s+aria-current="page"/',
            (string) $response->getContent(),
        );
    }

    public function test_the_pending_tab_has_an_empty_state(): void
    {
        $this->actingAs($this->me)->get('/friends?tab=pending')
            ->assertOk()
            ->assertSee('There are no pending friend requests.')
            ->assertDontSee('Incoming —')
            ->assertDontSee('Outgoing —');
    }

    public function test_the_blocked_tab_lists_only_people_you_blocked(): void
    {
        $blocked = $this->user('Blocked Bea', 'bea');
        $blocker = $this->user('Blocker Ben', 'ben');
        $block = $this->friendship($this->me, $blocked, Friendship::STATUS_BLOCKED);
        $this->friendship($blocker, $this->me, Friendship::STATUS_BLOCKED);

        $this->actingAs($this->me)->get('/friends?tab=blocked')
            ->assertOk()
            ->assertSee('Blocked — 1')
            ->assertSee('Blocked Bea')
            ->assertSee('action="'.route('friends.unblock', $block).'"', false)
            ->assertDontSee('Blocker Ben');

        // Being blocked does not show up anywhere.
        $this->actingAs($this->me)->get('/friends')->assertDontSee('Blocker Ben');
        $this->actingAs($this->me)->get('/friends?tab=pending')->assertDontSee('Blocker Ben');
    }

    public function test_the_blocked_tab_has_an_empty_state(): void
    {
        $this->actingAs($this->me)->get('/friends?tab=blocked')
            ->assertOk()
            ->assertSee('You have not blocked anyone.');
    }

    public function test_an_unknown_tab_shows_all_friends(): void
    {
        $this->actingAs($this->me)->get('/friends?tab=nope')->assertOk()->assertSee('All friends — 0');
        $this->actingAs($this->me)->get('/friends?tab[]=pending')->assertOk()->assertSee('All friends — 0');
    }

    public function test_the_badges_count_incoming_requests_only(): void
    {
        $this->friendship($this->user('A', 'aaa'), $this->me);
        $this->friendship($this->user('B', 'bbb'), $this->me);
        $this->friendship($this->me, $this->user('C', 'ccc'));

        $friends = (string) $this->actingAs($this->me)->get('/friends')
            ->assertSee('2<span class="sr-only"> incoming</span>', false)
            ->getContent();
        $this->assertSame([['hidden' => false, 'text' => '2']], $this->badges($friends));

        $dashboard = (string) $this->actingAs($this->me)->get('/dashboard')
            ->assertSee(route('friends.index', ['tab' => 'pending']), false)
            ->getContent();
        $this->assertSame([['hidden' => false, 'text' => '2']], $this->badges($dashboard));
    }

    public function test_the_badges_are_hidden_without_incoming_requests(): void
    {
        $dashboard = (string) $this->actingAs($this->me)->get('/dashboard')->getContent();
        $this->assertSame([['hidden' => true, 'text' => '0']], $this->badges($dashboard));

        $friends = (string) $this->actingAs($this->me)->get('/friends')
            ->assertDontSee('<span class="sr-only"> incoming', false)
            ->getContent();
        $this->assertSame([['hidden' => true, 'text' => '0']], $this->badges($friends));
    }

    public function test_pages_tell_the_script_who_is_signed_in(): void
    {
        $this->actingAs($this->me)->get('/dashboard')
            ->assertSee('<meta name="user-id" content="'.$this->me->id.'">', false)
            ->assertSee('<meta name="friends-url" content="'.route('friends.index').'">', false);

        $this->actingAs($this->me)->get('/friends')
            ->assertSee('<meta name="user-id" content="'.$this->me->id.'">', false);
    }

    public function test_guest_pages_have_no_user_meta(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('name="user-id"', false);
    }

    public function test_a_failed_request_keeps_the_username_and_shows_the_error(): void
    {
        $this->actingAs($this->me)->from('/friends')->followingRedirects()->post('/friends', ['username' => 'ghost'])
            ->assertOk()
            ->assertSee('value="ghost"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('Double-check the username');
    }

    public function test_guests_and_unverified_users_cannot_open_the_page(): void
    {
        $this->get('/friends')->assertRedirect('/login');

        $this->actingAs(User::factory()->unverified()->create())->get('/friends')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_the_dashboard_hides_friends_until_the_email_is_verified(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->get('/dashboard')
            ->assertOk()
            ->assertDontSee('href="'.route('friends.index'), false);
    }

    // --- FriendshipChanged ---------------------------------------------------

    public function test_the_event_goes_to_the_recipients_private_channel_with_their_count(): void
    {
        $alice = $this->user('Alice', 'alice');
        $friendship = $this->friendship($alice, $this->me);
        $this->friendship($this->user('Other', 'other'), $this->me);

        $event = new FriendshipChanged(FriendshipChanged::REQUEST, $friendship->id, $alice, $this->me);

        $this->assertSame('private-App.Models.User.'.$this->me->id, $event->broadcastOn()->name);
        $this->assertSame('friendship.changed', $event->broadcastAs());
        $this->assertSame([
            'type' => 'request',
            'friendship_id' => $friendship->id,
            'user' => [
                'id' => $alice->id,
                'name' => 'Alice',
                'username' => 'alice',
                'avatar_url' => $alice->avatarUrl(),
            ],
            'incoming_count' => 2,
        ], $event->broadcastWith());
    }

    public function test_the_payload_carries_no_private_fields(): void
    {
        $alice = $this->user('Alice', 'alice');
        $friendship = $this->friendship($alice, $this->me);

        $payload = (new FriendshipChanged(FriendshipChanged::REQUEST, $friendship->id, $alice, $this->me))->broadcastWith();

        $this->assertStringNotContainsString($alice->email, (string) json_encode($payload));
    }

    public function test_the_change_is_saved_when_the_broadcaster_is_down(): void
    {
        $alice = $this->user('Alice', 'alice');
        $request = $this->friendship($alice, $this->me);

        $broadcaster = new class extends BaseBroadcaster
        {
            public int $calls = 0;

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            /**
             * @param  array<int, mixed>  $channels
             * @param  array<string, mixed>  $payload
             */
            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->calls++;

                throw new RuntimeException('Reverb is down');
            }
        };
        Broadcast::extend('down', fn () => $broadcaster);
        config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);

        $this->actingAs($this->me)->post("/friends/{$request->id}/accept")
            ->assertRedirect('/friends?tab=pending')
            ->assertSessionHas('status', 'You and Alice are now friends.');

        $this->assertSame(1, $broadcaster->calls);
        $this->assertTrue($request->refresh()->isAccepted());
    }

    // --- Two clicks at once ----------------------------------------------------

    public function test_accepting_twice_changes_and_broadcasts_once(): void
    {
        Event::fake([FriendshipChanged::class]);
        $alice = $this->user('Alice', 'alice');
        $request = $this->friendship($alice, $this->me);
        $stale = Friendship::query()->findOrFail($request->id);

        $action = new ManageFriendship;

        $this->assertTrue($action->accept($this->me, $request));
        $this->assertFalse($action->accept($this->me, $stale));

        Event::assertDispatchedTimes(FriendshipChanged::class, 1);
    }

    public function test_a_request_cancelled_while_it_is_answered_is_not_accepted(): void
    {
        Event::fake([FriendshipChanged::class]);
        $alice = $this->user('Alice', 'alice');
        $request = $this->friendship($alice, $this->me);
        $stale = Friendship::query()->findOrFail($request->id);

        $action = new ManageFriendship;

        $this->assertTrue($action->cancel($alice, $request));
        $this->assertFalse($action->accept($this->me, $stale));
        $this->assertFalse($action->decline($this->me, $stale));

        $this->assertDatabaseCount('friendships', 0);
        Event::assertDispatchedTimes(FriendshipChanged::class, 1);
    }

    public function test_accept_only_works_for_the_recipient_even_without_the_policy(): void
    {
        $alice = $this->user('Alice', 'alice');
        $request = $this->friendship($alice, $this->me);

        $this->assertFalse((new ManageFriendship)->accept($alice, $request));
        $this->assertTrue($request->refresh()->isPending());
    }
}
