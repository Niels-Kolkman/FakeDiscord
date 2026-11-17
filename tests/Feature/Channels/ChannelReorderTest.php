<?php

namespace Tests\Feature\Channels;

use App\Actions\Channels\ReorderChannels;
use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\ChannelCategory;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * ISSUE-12: PUT /servers/{server}/layout, which saves the sidebar order
 * after a drag and drop.
 */
class ChannelReorderTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    private Channel $general;

    private Channel $random;

    private Channel $help;

    private ChannelCategory $talk;

    private ChannelCategory $games;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->server = app(CreateServer::class)->create($this->owner, 'Sortable');
        $this->general = $this->server->channels()->sole();
        $this->talk = ChannelCategory::factory()->create(['server_id' => $this->server->id, 'name' => 'Talk', 'position' => 0]);
        $this->games = ChannelCategory::factory()->create(['server_id' => $this->server->id, 'name' => 'Games', 'position' => 1]);
        $this->random = Channel::factory()->create(['server_id' => $this->server->id, 'category_id' => $this->talk->id, 'name' => 'random', 'position' => 0]);
        $this->help = Channel::factory()->create(['server_id' => $this->server->id, 'category_id' => $this->talk->id, 'name' => 'help', 'position' => 1]);
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return TestResponse<Response>
     */
    private function reorder(array $layout, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->putJson("/servers/{$this->server->id}/layout", $layout);
    }

    /**
     * @return array{uncategorized: list<string>, categories: array<string, list<string>>}
     */
    private function sidebar(): array
    {
        $layout = $this->server->channelLayout();

        return [
            'uncategorized' => $layout->uncategorized->pluck('name')->all(),
            'categories' => $layout->categories
                ->mapWithKeys(fn (ChannelCategory $category) => [$category->name => $category->channels->pluck('name')->all()])
                ->all(),
        ];
    }

    public function test_channels_move_between_groups_and_categories_reorder(): void
    {
        $this->reorder([
            'uncategorized' => [$this->help->id],
            'categories' => [
                ['id' => $this->games->id, 'channels' => [$this->random->id, $this->general->id]],
                ['id' => $this->talk->id, 'channels' => []],
            ],
        ])->assertOk()->assertExactJson(['status' => 'saved']);

        $this->assertSame([
            'uncategorized' => ['help'],
            'categories' => ['Games' => ['random', 'general'], 'Talk' => []],
        ], $this->sidebar());

        $this->actingAs($this->owner)->get("/servers/{$this->server->id}")
            ->assertSeeInOrder(['help', 'Games', 'random', 'general', 'Talk']);
    }

    public function test_sending_the_current_order_changes_nothing(): void
    {
        $before = $this->sidebar();

        $this->reorder([
            'uncategorized' => [$this->general->id],
            'categories' => [
                ['id' => $this->talk->id, 'channels' => [$this->random->id, $this->help->id]],
                ['id' => $this->games->id, 'channels' => []],
            ],
        ])->assertOk();

        $this->assertSame($before, $this->sidebar());
    }

    public function test_a_server_without_channels_or_categories_accepts_an_empty_layout(): void
    {
        $owner = User::factory()->create();
        $server = app(CreateServer::class)->create($owner, 'Empty');
        $server->channels()->delete();

        $this->actingAs($owner)->putJson("/servers/{$server->id}/layout", ['uncategorized' => [], 'categories' => []])
            ->assertOk();
    }

    /**
     * @return array<string, array{\Closure(self): array<string, mixed>}>
     */
    public static function staleLayouts(): array
    {
        return [
            'a channel is missing' => [fn (self $t) => [
                'uncategorized' => [$t->general->id],
                'categories' => [['id' => $t->talk->id, 'channels' => [$t->random->id]], ['id' => $t->games->id, 'channels' => []]],
            ]],
            'a channel is listed twice' => [fn (self $t) => [
                'uncategorized' => [$t->general->id, $t->help->id],
                'categories' => [['id' => $t->talk->id, 'channels' => [$t->random->id, $t->help->id]], ['id' => $t->games->id, 'channels' => []]],
            ]],
            'a channel of another server' => [fn (self $t) => [
                'uncategorized' => [$t->general->id, Channel::factory()->create()->id],
                'categories' => [['id' => $t->talk->id, 'channels' => [$t->random->id, $t->help->id]], ['id' => $t->games->id, 'channels' => []]],
            ]],
            'an unknown channel id' => [fn (self $t) => [
                'uncategorized' => [$t->general->id, 999999],
                'categories' => [['id' => $t->talk->id, 'channels' => [$t->random->id, $t->help->id]], ['id' => $t->games->id, 'channels' => []]],
            ]],
            'a category is missing' => [fn (self $t) => [
                'uncategorized' => [$t->general->id],
                'categories' => [['id' => $t->talk->id, 'channels' => [$t->random->id, $t->help->id]]],
            ]],
            'a category is listed twice' => [fn (self $t) => [
                'uncategorized' => [$t->general->id],
                'categories' => [
                    ['id' => $t->talk->id, 'channels' => [$t->random->id]],
                    ['id' => $t->talk->id, 'channels' => [$t->help->id]],
                    ['id' => $t->games->id, 'channels' => []],
                ],
            ]],
            'a category of another server' => [fn (self $t) => [
                'uncategorized' => [$t->general->id],
                'categories' => [
                    ['id' => $t->talk->id, 'channels' => [$t->random->id, $t->help->id]],
                    ['id' => $t->games->id, 'channels' => []],
                    ['id' => ChannelCategory::factory()->create()->id, 'channels' => []],
                ],
            ]],
        ];
    }

    /**
     * @param  \Closure(self): array<string, mixed>  $layout
     */
    #[DataProvider('staleLayouts')]
    public function test_a_layout_that_does_not_match_the_server_is_rejected_and_changes_nothing(\Closure $layout): void
    {
        $before = $this->sidebar();

        $this->reorder($layout($this))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['layout' => ReorderChannels::STALE_MESSAGE]);

        $this->assertSame($before, $this->sidebar());
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function malformedLayouts(): array
    {
        return [
            'nothing sent' => [[], ['uncategorized', 'categories']],
            'not arrays' => [['uncategorized' => 'x', 'categories' => 5], ['uncategorized', 'categories']],
            'non-integer channel id' => [['uncategorized' => ['abc'], 'categories' => []], ['uncategorized.0']],
            'category without id' => [['uncategorized' => [], 'categories' => [['channels' => []]]], ['categories.0.id']],
            'category without channels' => [['uncategorized' => [], 'categories' => [['id' => 1]]], ['categories.0.channels']],
            'unexpected category key' => [['uncategorized' => [], 'categories' => [['id' => 1, 'channels' => [], 'position' => 3]]], ['categories.0']],
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     * @param  list<string>  $errors
     */
    #[DataProvider('malformedLayouts')]
    public function test_malformed_layouts_fail_validation(array $layout, array $errors): void
    {
        $this->reorder($layout)->assertUnprocessable()->assertJsonValidationErrors($errors);
    }

    public function test_only_the_owner_can_reorder(): void
    {
        $member = User::factory()->create();
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $member->id]);
        $layout = [
            'uncategorized' => [$this->random->id, $this->help->id, $this->general->id],
            'categories' => [['id' => $this->games->id, 'channels' => []], ['id' => $this->talk->id, 'channels' => []]],
        ];
        $before = $this->sidebar();

        $this->reorder($layout, $member)->assertForbidden();
        $this->reorder($layout, User::factory()->create())->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->putJson("/servers/{$this->server->id}/layout", $layout)->assertUnauthorized();

        $this->assertSame($before, $this->sidebar());
    }

    public function test_reordering_another_server_does_not_touch_this_one(): void
    {
        $other = app(CreateServer::class)->create($this->owner, 'Other');
        $before = $this->sidebar();

        // This server's ids are not the other server's channels, so the layout is rejected.
        $this->actingAs($this->owner)->putJson("/servers/{$other->id}/layout", [
            'uncategorized' => [$this->general->id, $this->random->id, $this->help->id],
            'categories' => [],
        ])->assertUnprocessable();

        $this->assertSame($before, $this->sidebar());
    }
}
