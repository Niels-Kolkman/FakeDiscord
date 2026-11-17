<?php

namespace Tests\Feature\Messages;

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Support\MessageFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ISSUE-15: App\Support\MessageFeed on its own, the paging and cursor
 * decoding behind GET /servers/{id}/channels/{channel}/messages.
 */
class MessageFeedTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        /** @var Channel $general */
        $general = app(CreateServer::class)->create($owner, 'Feed Server')->channels()->firstOrFail();
        $this->channel = $general;
    }

    /**
     * @return list<int> ids, oldest first
     */
    private function createMessages(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = Message::factory()->inChannel($this->channel)->create()->id;
        }

        return $ids;
    }

    public function test_no_channel_gives_an_empty_feed(): void
    {
        $feed = MessageFeed::for(null);

        $this->assertCount(0, $feed->messages);
        $this->assertNull($feed->olderCursor);
        $this->assertFalse($feed->hasOlder);
    }

    public function test_a_voice_channel_gives_an_empty_feed(): void
    {
        $voice = Channel::factory()->voice()->create(['server_id' => $this->channel->server_id]);
        // Even if a voice channel somehow has messages, none are returned.
        Message::factory()->inChannel($voice)->create();

        $feed = MessageFeed::for($voice);

        $this->assertCount(0, $feed->messages);
        $this->assertFalse($feed->hasOlder);
    }

    public function test_the_limit_sets_the_page_size(): void
    {
        $ids = $this->createMessages(7);

        $feed = MessageFeed::for($this->channel, 3);

        $this->assertSame(array_slice($ids, 4), $feed->messages->pluck('id')->all());
        $this->assertTrue($feed->hasOlder);
        $this->assertIsString($feed->olderCursor);
    }

    public function test_paging_with_a_small_limit_covers_every_message_once(): void
    {
        $ids = $this->createMessages(10);

        $seen = [];
        $before = null;
        for ($pages = 1; $pages <= 10; $pages++) {
            $feed = MessageFeed::for($this->channel, 3, $before);
            $seen = array_merge($feed->messages->pluck('id')->all(), $seen);
            if (! $feed->hasOlder) {
                break;
            }
            $before = MessageFeed::decodeCursor($feed->olderCursor);
            $this->assertNotNull($before, 'Every cursor the feed hands out must decode again.');
        }

        $this->assertSame(4, $pages); // 3 + 3 + 3 + 1
        $this->assertSame($ids, $seen);
    }

    public function test_the_cursor_points_at_the_oldest_message_of_the_page(): void
    {
        $ids = $this->createMessages(5);

        $cursor = MessageFeed::decodeCursor(MessageFeed::for($this->channel, 2)->olderCursor);

        $this->assertNotNull($cursor);
        $this->assertSame($ids[3], $cursor->parameter('id'));
    }

    public function test_the_next_page_still_loads_when_the_cursor_message_was_deleted(): void
    {
        $ids = $this->createMessages(5);
        $feed = MessageFeed::for($this->channel, 2);

        Message::query()->whereKey($ids[3])->delete();

        $older = MessageFeed::for($this->channel, 10, MessageFeed::decodeCursor($feed->olderCursor));
        $this->assertSame(array_slice($ids, 0, 3), $older->messages->pluck('id')->all());
        $this->assertFalse($older->hasOlder);
    }

    public function test_a_cursor_past_the_newest_message_gives_the_newest_page(): void
    {
        $ids = $this->createMessages(3);

        $feed = MessageFeed::for($this->channel, before: new Cursor(['id' => max($ids) + 1000]));

        $this->assertSame($ids, $feed->messages->pluck('id')->all());
    }

    public function test_the_newest_page_ignores_a_before_parameter_on_the_current_request(): void
    {
        $ids = $this->createMessages(5);
        $this->app['request']->query->set(MessageFeed::CURSOR, (new Cursor(['id' => $ids[1]]))->encode());

        $feed = MessageFeed::for($this->channel, 2);

        $this->assertSame(array_slice($ids, 3), $feed->messages->pluck('id')->all());
    }

    public function test_the_payload_matches_the_api_shape(): void
    {
        $ids = $this->createMessages(3);

        $payload = MessageFeed::for($this->channel, 2)->toPayload();

        $this->assertSame(['messages', 'next_cursor'], array_keys($payload));
        $this->assertSame(array_slice($ids, 1), array_column($payload['messages'], 'id'));
        $this->assertSame(['id', 'channel_id', 'content', 'created_at', 'edited_at', 'author', 'reactions'], array_keys($payload['messages'][0]));
        $this->assertIsString($payload['next_cursor']);
        $this->assertSame(['messages' => [], 'next_cursor' => null], MessageFeed::empty()->toPayload());
    }

    /**
     * Base64 output lengths cycle through the three padding cases, so a
     * spread of ids exercises every one of them.
     *
     * @return array<string, array{int}>
     */
    public static function cursorIds(): array
    {
        return [
            'one' => [1],
            'two digits' => [42],
            'three digits' => [999],
            'four digits' => [1234],
            'large' => [987654321],
            'max int' => [PHP_INT_MAX],
        ];
    }

    #[DataProvider('cursorIds')]
    public function test_laravel_cursors_round_trip(int $id): void
    {
        $decoded = MessageFeed::decodeCursor((new Cursor(['id' => $id]))->encode());

        $this->assertNotNull($decoded);
        $this->assertSame($id, $decoded->parameter('id'));
        $this->assertTrue($decoded->pointsToNextItems());
    }

    public function test_padded_base64_is_accepted_too(): void
    {
        $padded = base64_encode((string) json_encode(['id' => 1, '_pointsToNextItems' => true]));
        $this->assertStringEndsWith('=', $padded);

        $this->assertSame(1, MessageFeed::decodeCursor($padded)?->parameter('id'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejectedValues(): array
    {
        $b64 = fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $encode = fn (mixed $value): string => $b64((string) json_encode($value));

        return [
            'null' => [null],
            'integer' => [5],
            'array' => [['id' => 5]],
            'negative id' => [$encode(['id' => -1, '_pointsToNextItems' => true])],
            'float id' => [$encode(['id' => 5.5, '_pointsToNextItems' => true])],
            'id beyond PHP_INT_MAX' => [$b64('{"id":99999999999999999999,"_pointsToNextItems":true}')],
            'boolean id' => [$encode(['id' => true, '_pointsToNextItems' => true])],
            'direction 1 instead of true' => [$encode(['id' => 5, '_pointsToNextItems' => 1])],
            'keys in another order' => [$encode(['_pointsToNextItems' => true, 'id' => 5])],
            'json list' => [$encode([5, true])],
            'not base64 characters' => ['abc$def'],
            '201 characters' => [str_repeat('A', 201)],
        ];
    }

    #[DataProvider('rejectedValues')]
    public function test_other_values_do_not_decode(mixed $value): void
    {
        $this->assertNull(MessageFeed::decodeCursor($value));
    }
}
