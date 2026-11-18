<?php

namespace Tests\Feature\Messages;

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Support\MessageSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\DatabaseEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * ISSUE-21: GET /servers/{id}/search finds a server's messages through
 * Laravel Scout's database engine, filtered by channel, author and date.
 */
class MessageSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Server $server;

    private Channel $general;

    private Channel $random;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->member = User::factory()->create(['name' => 'Member']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Test Server');
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $this->member->id]);

        /** @var Channel $general */
        $general = $this->server->channels()->where('type', Channel::TYPE_TEXT)->firstOrFail();
        $this->general = $general;
        $this->random = Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'random']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<Response>
     */
    private function search(array $query, ?User $as = null, ?Server $server = null): TestResponse
    {
        $server ??= $this->server;

        return $this->actingAs($as ?? $this->member)
            ->get("/servers/{$server->id}/search?".http_build_query($query));
    }

    private function message(string $content, ?Channel $channel = null, ?User $author = null, ?string $at = null): Message
    {
        $attributes = ['user_id' => ($author ?? $this->owner)->id, 'content' => $content];
        if ($at !== null) {
            $attributes['created_at'] = Carbon::parse($at);
        }

        return Message::factory()->inChannel($channel ?? $this->general)->create($attributes);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<int>
     */
    private function resultIds(TestResponse $response): array
    {
        $response->assertOk();

        /** @var LengthAwarePaginator<int, Message>|null $results */
        $results = $response->viewData('results');
        $this->assertNotNull($results, 'Expected search results');

        return array_values(array_map(fn (Message $message): int => (int) $message->id, $results->items()));
    }

    public function test_scout_uses_the_database_engine(): void
    {
        $this->assertInstanceOf(DatabaseEngine::class, app(EngineManager::class)->engine());
    }

    public function test_it_finds_messages_containing_the_text_newest_first(): void
    {
        $old = $this->message('Deploy is done', at: '2026-11-01 10:00');
        $this->message('Lunch anyone?');
        $new = $this->message('deploy again', $this->random, at: '2026-11-02 10:00');

        $this->assertSame([$new->id, $old->id], $this->resultIds($this->search(['q' => 'DEPLOY'])));
    }

    public function test_it_never_finds_messages_of_other_servers_dms_or_deleted_ones(): void
    {
        $found = $this->message('secret plan');

        $other = app(CreateServer::class)->create($this->owner, 'Other');
        /** @var Channel $otherChannel */
        $otherChannel = $other->channels()->firstOrFail();
        $this->message('secret plan elsewhere', $otherChannel);

        $conversation = Conversation::factory()->create();
        Message::factory()->inConversation($conversation)->create(['user_id' => $this->owner->id, 'content' => 'secret plan in a DM']);

        $this->message('secret plan, deleted')->delete();

        $this->assertSame([$found->id], $this->resultIds($this->search(['q' => 'secret'])));
    }

    public function test_it_filters_by_channel(): void
    {
        $this->message('hello general');
        $inRandom = $this->message('hello random', $this->random);

        $this->assertSame([$inRandom->id], $this->resultIds($this->search(['q' => 'hello', 'channel' => $this->random->id])));
    }

    public function test_it_filters_by_author(): void
    {
        $this->message('hi from owner');
        $byMember = $this->message('hi from member', author: $this->member);

        $this->assertSame([$byMember->id], $this->resultIds($this->search(['q' => 'hi', 'author' => $this->member->id])));
    }

    public function test_it_filters_by_date_with_both_days_included(): void
    {
        $this->message('report', at: '2026-11-09 23:59:59');
        $first = $this->message('report', at: '2026-11-10 00:00:00');
        $last = $this->message('report', at: '2026-11-12 23:59:59');
        $this->message('report', at: '2026-11-13 00:00:00');

        $this->assertSame(
            [$last->id, $first->id],
            $this->resultIds($this->search(['q' => 'report', 'from' => '2026-11-10', 'to' => '2026-11-12'])),
        );
        $this->assertCount(3, $this->resultIds($this->search(['q' => 'report', 'from' => '2026-11-10'])));
        $this->assertCount(3, $this->resultIds($this->search(['q' => 'report', 'to' => '2026-11-12'])));
    }

    public function test_filters_work_without_text(): void
    {
        $this->message('one');
        $byMember = $this->message('two', $this->random, $this->member);

        $this->assertSame([$byMember->id], $this->resultIds($this->search(['author' => $this->member->id])));
        $this->assertSame([$byMember->id], $this->resultIds($this->search(['channel' => $this->random->id])));
    }

    public function test_like_wildcards_in_the_text_match_literally(): void
    {
        $percent = $this->message('we are 100% done');
        $this->message('100 points');
        $underscore = $this->message('call snake_case()');
        $this->message('call snakeXcase()');
        $bang = $this->message('wow!');

        $this->assertSame([$percent->id], $this->resultIds($this->search(['q' => '100%'])));
        $this->assertSame([$underscore->id], $this->resultIds($this->search(['q' => 'snake_case'])));
        $this->assertSame([$bang->id], $this->resultIds($this->search(['q' => 'wow!'])));
    }

    public function test_results_are_paginated(): void
    {
        for ($i = 0; $i < MessageSearch::PER_PAGE + 3; $i++) {
            $this->message("item {$i}");
        }

        $first = $this->search(['q' => 'item']);
        $this->assertCount(MessageSearch::PER_PAGE, $this->resultIds($first));
        $first->assertSee((MessageSearch::PER_PAGE + 3).' results');

        $second = $this->search(['q' => 'item', 'page' => 2]);
        $this->assertCount(3, $this->resultIds($second));
    }

    public function test_the_page_shows_results_with_highlighted_and_escaped_matches(): void
    {
        $this->message('<b>bold</b> needle here', $this->random);

        $this->search(['q' => 'needle'])
            ->assertOk()
            ->assertSee('1 result')
            ->assertSee('&lt;b&gt;bold&lt;/b&gt; <mark class="rounded-sm bg-[#f0b232]/40 text-inherit">needle</mark> here', false)
            ->assertSee('#random')
            ->assertSee(route('channels.show', [$this->server, $this->random]), false)
            ->assertDontSee('<b>bold</b>', false);
    }

    public function test_messages_of_deleted_accounts_show_deleted_user(): void
    {
        $this->message('orphan text', author: $this->member);
        $this->member->delete();

        $this->search(['q' => 'orphan'], $this->owner)->assertOk()->assertSee('Deleted User');
    }

    public function test_without_text_or_filters_there_are_no_results(): void
    {
        $this->message('anything');

        $response = $this->search([])->assertOk()->assertSee('Type something to search for');
        $this->assertNull($response->viewData('results'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): array
    {
        return [
            'text too long' => [['q' => str_repeat('a', MessageSearch::MAX_QUERY_LENGTH + 1)], 'q'],
            'channel not a number' => [['channel' => 'abc'], 'channel'],
            'unknown author' => [['author' => 999999], 'author'],
            'bad date' => [['from' => '17-11-2026'], 'from'],
            'end before start' => [['from' => '2026-11-10', 'to' => '2026-11-09'], 'to'],
            'text as array' => [['q' => ['a']], 'q'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalidInput')]
    public function test_invalid_input_shows_errors_on_the_page_without_results(array $query, string $field): void
    {
        $this->message('anything');

        $this->search($query)
            ->assertOk()
            ->assertViewHas('results', null)
            ->assertSee('aria-invalid="true" aria-describedby="search-'.$field.'-error"', false);
    }

    public function test_a_channel_of_another_server_or_a_voice_channel_is_rejected(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Other');
        /** @var Channel $otherChannel */
        $otherChannel = $other->channels()->firstOrFail();
        $voice = Channel::factory()->voice()->create(['server_id' => $this->server->id]);

        foreach ([$otherChannel, $voice] as $channel) {
            $this->search(['q' => 'x', 'channel' => $channel->id])
                ->assertOk()
                ->assertViewHas('results', null)
                ->assertSee('Choose a text channel of this server.');
        }
    }

    public function test_former_members_can_still_be_searched_by_id(): void
    {
        $leaver = User::factory()->create();
        $message = $this->message('bye all', author: $leaver);

        $this->assertSame([$message->id], $this->resultIds($this->search(['author' => $leaver->id])));
    }

    public function test_only_members_can_search(): void
    {
        $this->search(['q' => 'x'], User::factory()->create())->assertForbidden();
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get("/servers/{$this->server->id}/search?q=x")->assertRedirect('/login');
    }

    public function test_the_form_keeps_the_search_and_lists_channels_and_members(): void
    {
        $this->search(['q' => 'kept "text"', 'channel' => $this->random->id, 'author' => $this->member->id, 'from' => '2026-11-01'])
            ->assertOk()
            ->assertSee('value="kept &quot;text&quot;"', false)
            ->assertSee('<option value="'.$this->random->id.'" selected>#random</option>', false)
            ->assertSee('<option value="'.$this->member->id.'" selected>', false)
            ->assertSee('value="2026-11-01"', false)
            ->assertSee('#'.$this->general->name);
    }

    public function test_the_channel_header_has_a_search_box(): void
    {
        $this->actingAs($this->member)
            ->get(route('channels.show', [$this->server, $this->general]))
            ->assertOk()
            ->assertSee('action="'.route('servers.search', $this->server).'"', false)
            ->assertSee('data-header-search', false);
    }

    public function test_highlight_marks_every_match_ignoring_case_and_escapes_everything(): void
    {
        $mark = '<mark class="rounded-sm bg-[#f0b232]/40 text-inherit">';

        $this->assertSame(
            $mark.'Cat</mark> &amp; '.$mark.'cat</mark>',
            MessageSearch::highlight('Cat & cat', 'cat')->toHtml(),
        );
        $this->assertSame('a &lt;b&gt;', MessageSearch::highlight('a <b>', null)->toHtml());
        $this->assertSame('&lt;'.$mark.'a/b</mark>&gt;', MessageSearch::highlight('<a/b>', 'a/b')->toHtml());
    }
}
