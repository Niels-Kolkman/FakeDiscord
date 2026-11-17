<?php

namespace Tests\Feature\Channels;

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\ChannelCategory;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ISSUE-12: edge cases for channels, categories and the reorder endpoint
 * that the main channel tests do not cover.
 */
class ChannelEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function serverOwnedBy(User $owner, string $name = 'Edge Server'): Server
    {
        return app(CreateServer::class)->create($owner, $name);
    }

    private function memberOf(Server $server): User
    {
        $user = User::factory()->create();
        ServerMember::factory()->create(['server_id' => $server->id, 'user_id' => $user->id]);

        return $user;
    }

    // --- Limits ----------------------------------------------------------

    public function test_the_500th_channel_can_still_be_created(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner); // #general is channel 1
        $now = now();
        DB::table('channels')->insert(array_map(fn (int $i) => [
            'server_id' => $server->id, 'name' => "c{$i}", 'type' => 'text', 'position' => $i,
            'created_at' => $now, 'updated_at' => $now,
        ], range(1, Channel::MAX_PER_SERVER - 2)));

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", ['name' => 'last-one'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Channel::MAX_PER_SERVER, $server->channels()->count());
    }

    public function test_the_50th_category_can_still_be_created(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $now = now();
        DB::table('channel_categories')->insert(array_map(fn (int $i) => [
            'server_id' => $server->id, 'name' => "Cat {$i}", 'position' => $i, 'created_at' => $now, 'updated_at' => $now,
        ], range(0, ChannelCategory::MAX_PER_SERVER - 2)));

        $this->actingAs($owner)->post("/servers/{$server->id}/categories", ['name' => 'Last'])
            ->assertSessionHasNoErrors();

        $last = $server->categories()->where('name', 'Last')->sole();
        $this->assertSame(ChannelCategory::MAX_PER_SERVER - 1, $last->position);
    }

    public function test_limits_count_per_server(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $other = Server::factory()->create();
        $now = now();
        DB::table('channel_categories')->insert(array_map(fn (int $i) => [
            'server_id' => $other->id, 'name' => "Cat {$i}", 'position' => $i, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, ChannelCategory::MAX_PER_SERVER)));

        $this->actingAs($owner)->post("/servers/{$server->id}/categories", ['name' => 'Mine'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $server->categories()->sole()->position);
    }

    // --- Create ----------------------------------------------------------

    public function test_an_empty_category_id_and_topic_mean_none(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", ['name' => 'plain', 'topic' => '', 'category_id' => ''])
            ->assertSessionHasNoErrors();

        $channel = $server->channels()->where('name', 'plain')->sole();
        $this->assertNull($channel->category_id);
        $this->assertNull($channel->topic);
    }

    public function test_the_first_channel_of_an_empty_category_gets_position_zero(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id]);

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", ['name' => 'first', 'category_id' => $category->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $server->channels()->where('name', 'first')->sole()->position);
    }

    public function test_duplicate_channel_names_are_allowed_like_discord(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", ['name' => 'General'])->assertSessionHasNoErrors();

        $this->assertSame(2, $server->channels()->where('name', 'general')->count());
    }

    public function test_strangers_cannot_open_the_category_create_page(): void
    {
        $server = $this->serverOwnedBy(User::factory()->create());

        $this->actingAs(User::factory()->create())->get("/servers/{$server->id}/categories/create")->assertForbidden();
        $this->actingAs(User::factory()->create())->post("/servers/{$server->id}/categories", ['name' => 'x'])->assertForbidden();
    }

    // --- Update ----------------------------------------------------------

    public function test_the_edit_page_preselects_the_current_category(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => 'Home']);
        $channel = Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id]);

        $this->actingAs($owner)->get("/servers/{$server->id}/channels/{$channel->id}/settings")
            ->assertOk()
            ->assertSee("<option value=\"{$category->id}\" selected", false);
    }

    public function test_moving_a_channel_out_of_its_category_puts_it_at_the_bottom_of_the_uncategorised_list(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner); // #general at 0
        Channel::factory()->create(['server_id' => $server->id, 'name' => 'loose', 'position' => 6]);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id]);
        $channel = Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id, 'name' => 'leaving', 'position' => 0]);

        $this->actingAs($owner)->patch("/servers/{$server->id}/channels/{$channel->id}", ['name' => 'leaving', 'category_id' => ''])
            ->assertSessionHasNoErrors();

        $channel->refresh();
        $this->assertNull($channel->category_id);
        $this->assertSame(7, $channel->position);
        $this->assertSame(['general', 'loose', 'leaving'], $server->channelLayout()->uncategorized->pluck('name')->all());
    }

    public function test_saving_a_categorised_channel_in_the_same_category_keeps_its_position(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id]);
        $channel = Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id, 'position' => 2]);
        Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id, 'position' => 8]);

        $this->actingAs($owner)->patch("/servers/{$server->id}/channels/{$channel->id}", [
            'name' => 'renamed', 'category_id' => (string) $category->id,
        ])->assertSessionHasNoErrors();

        $channel->refresh();
        $this->assertSame($category->id, $channel->category_id);
        $this->assertSame(2, $channel->position);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidUpdates(): array
    {
        return [
            'blank name' => [['name' => '   '], 'name'],
            'name without letters or digits' => [['name' => '!!!'], 'name'],
            'name too long' => [['name' => str_repeat('a', Channel::MAX_NAME_LENGTH + 1)], 'name'],
            'topic too long' => [['name' => 'ok', 'topic' => str_repeat('t', Channel::MAX_TOPIC_LENGTH + 1)], 'topic'],
            'unknown category' => [['name' => 'ok', 'category_id' => 999999], 'category_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidUpdates')]
    public function test_invalid_updates_change_nothing(array $input, string $error): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $channel = $server->channels()->sole();
        $channel->update(['topic' => 'Original']);

        $this->actingAs($owner)->patch("/servers/{$server->id}/channels/{$channel->id}", $input)
            ->assertSessionHasErrors($error);

        $channel->refresh();
        $this->assertSame('general', $channel->name);
        $this->assertSame('Original', $channel->topic);
        $this->assertNull($channel->category_id);
    }

    public function test_a_channel_cannot_be_moved_into_a_category_of_another_server(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $channel = $server->channels()->sole();
        $foreign = ChannelCategory::factory()->create();

        $this->actingAs($owner)->patch("/servers/{$server->id}/channels/{$channel->id}", ['name' => 'general', 'category_id' => $foreign->id])
            ->assertSessionHasErrors(['category_id' => 'That category does not exist in this server.']);

        $this->assertNull($channel->fresh()?->category_id);
    }

    public function test_a_voice_channel_name_keeps_punctuation_but_cannot_be_blank(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $voice = Channel::factory()->voice()->create(['server_id' => $server->id, 'name' => 'Lounge']);

        $this->actingAs($owner)->patch("/servers/{$server->id}/channels/{$voice->id}", ['name' => " \t "])
            ->assertSessionHasErrors('name');
        $this->assertSame('Lounge', $voice->fresh()?->name);

        $this->actingAs($owner)->patch("/servers/{$server->id}/channels/{$voice->id}", ['name' => 'Movie Night! 🎬'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Movie Night! 🎬', $voice->fresh()?->name);
    }

    public function test_strangers_cannot_open_channel_settings(): void
    {
        $server = $this->serverOwnedBy(User::factory()->create());
        $channel = $server->channels()->sole();

        $this->actingAs(User::factory()->create())->get("/servers/{$server->id}/channels/{$channel->id}/settings")->assertForbidden();
    }

    // --- Categories ------------------------------------------------------

    public function test_renaming_a_category_normalises_whitespace_and_validates(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => 'Old', 'position' => 3]);

        $this->actingAs($owner)->patch("/servers/{$server->id}/categories/{$category->id}", ['name' => '   '])
            ->assertSessionHasErrors('name');
        $this->actingAs($owner)->patch("/servers/{$server->id}/categories/{$category->id}", ['name' => str_repeat('a', ChannelCategory::MAX_NAME_LENGTH + 1)])
            ->assertSessionHasErrors('name');
        $this->assertSame('Old', $category->fresh()?->name);

        $this->actingAs($owner)->patch("/servers/{$server->id}/categories/{$category->id}", ['name' => "  Voice \n  Rooms  "])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Category saved.');

        $category->refresh();
        $this->assertSame('Voice Rooms', $category->name);
        $this->assertSame(3, $category->position);
    }

    public function test_category_names_keep_their_case_and_punctuation(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $this->actingAs($owner)->post("/servers/{$server->id}/categories", ['name' => 'VOICE & Stuff!'])->assertSessionHasNoErrors();

        $this->assertSame('VOICE & Stuff!', $server->categories()->sole()->name);
    }

    public function test_category_names_are_escaped_in_the_sidebar(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => '<script>alert(1)</script>']);

        $this->actingAs($owner)->get("/servers/{$server->id}")
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_deleting_an_empty_category_leaves_the_channels_alone(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $empty = ChannelCategory::factory()->create(['server_id' => $server->id]);
        $kept = ChannelCategory::factory()->create(['server_id' => $server->id]);
        $inKept = Channel::factory()->create(['server_id' => $server->id, 'category_id' => $kept->id, 'position' => 4]);

        $this->actingAs($owner)->delete("/servers/{$server->id}/categories/{$empty->id}")->assertRedirect(route('servers.show', $server));

        $this->assertModelMissing($empty);
        $this->assertSame($kept->id, $inKept->fresh()?->category_id);
        $this->assertSame(4, $inKept->fresh()?->position);
        $this->assertSame(0, $server->channels()->where('name', 'general')->sole()->position);
    }

    public function test_deleted_category_channels_start_at_zero_when_nothing_is_uncategorised(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $server->channels()->delete();
        $category = ChannelCategory::factory()->create(['server_id' => $server->id]);
        Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id, 'name' => 'b', 'position' => 9]);
        Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id, 'name' => 'a', 'position' => 3]);

        $this->actingAs($owner)->delete("/servers/{$server->id}/categories/{$category->id}");

        $this->assertSame(
            [['a', 0], ['b', 1]],
            $server->channelLayout()->uncategorized->map(fn (Channel $c) => [$c->name, $c->position])->all(),
        );
    }

    public function test_the_category_delete_form_asks_for_confirmation(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => 'Lobby']);

        $this->actingAs($owner)->get("/servers/{$server->id}/categories/{$category->id}/settings")
            ->assertSee('data-confirm="Delete the category Lobby? Its channels are kept."', false);
    }

    public function test_deleting_a_channel_keeps_its_category(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id]);
        $channel = Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id]);

        $this->actingAs($owner)->delete("/servers/{$server->id}/channels/{$channel->id}")->assertRedirect();

        $this->assertModelMissing($channel);
        $this->assertModelExists($category);
    }

    // --- Layout ----------------------------------------------------------

    public function test_a_channel_pointing_at_another_servers_category_is_listed_as_uncategorised(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $foreign = ChannelCategory::factory()->create(['name' => 'Foreign Category']);
        Channel::factory()->create(['server_id' => $server->id, 'category_id' => $foreign->id, 'name' => 'orphan', 'position' => 1]);

        $layout = $server->channelLayout();

        $this->assertSame(['general', 'orphan'], $layout->uncategorized->pluck('name')->all());
        $this->assertCount(0, $layout->categories);
        $this->actingAs($owner)->get("/servers/{$server->id}")
            ->assertOk()
            ->assertSee('orphan')
            ->assertDontSee('Foreign Category');
    }

    public function test_a_server_with_only_voice_channels_has_no_default_channel(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $server->channels()->delete();
        Channel::factory()->voice()->create(['server_id' => $server->id, 'name' => 'Lobby']);

        $this->assertNull($server->channelLayout()->defaultChannel());
        $this->actingAs($owner)->get("/servers/{$server->id}")
            ->assertOk()
            ->assertSee('This server has no text channels yet.');
    }

    public function test_empty_categories_are_still_shown_in_the_sidebar(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $member = $this->memberOf($server);
        ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => 'Nothing Here Yet']);

        $this->actingAs($member)->get("/servers/{$server->id}")->assertOk()->assertSee('Nothing Here Yet');
    }

    // --- Reorder ---------------------------------------------------------

    public function test_reorder_accepts_numeric_string_ids_and_renumbers_positions_from_zero(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $general = $server->channels()->sole();
        $general->update(['position' => 40]);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id, 'position' => 17]);
        $a = Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id, 'position' => 12]);
        $voice = Channel::factory()->voice()->create(['server_id' => $server->id, 'position' => 30]);

        $this->actingAs($owner)->putJson("/servers/{$server->id}/layout", [
            'uncategorized' => [(string) $voice->id, (string) $general->id],
            'categories' => [['id' => (string) $category->id, 'channels' => [(string) $a->id]]],
        ])->assertOk();

        $this->assertSame([null, 0], [$voice->fresh()?->category_id, $voice->fresh()?->position]);
        $this->assertSame([null, 1], [$general->fresh()?->category_id, $general->fresh()?->position]);
        $this->assertSame([$category->id, 0], [$a->fresh()?->category_id, $a->fresh()?->position]);
        $this->assertSame(0, $category->fresh()?->position);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function moreMalformedLayouts(): array
    {
        return [
            'category is a scalar' => [['uncategorized' => [], 'categories' => [5]], ['categories.0']],
            'category is null' => [['uncategorized' => [], 'categories' => [null]], ['categories.0']],
            'category channels not an array' => [['uncategorized' => [], 'categories' => [['id' => 1, 'channels' => 'x']]], ['categories.0.channels']],
            'non-integer channel in a category' => [['uncategorized' => [], 'categories' => [['id' => 1, 'channels' => [1.5]]]], ['categories.0.channels.0']],
            'non-integer category id' => [['uncategorized' => [], 'categories' => [['id' => 'abc', 'channels' => []]]], ['categories.0.id']],
            'too many uncategorised ids' => [['uncategorized' => range(1, Channel::MAX_PER_SERVER + 1), 'categories' => []], ['uncategorized']],
            'too many categories' => [['uncategorized' => [], 'categories' => array_map(fn (int $i) => ['id' => $i, 'channels' => []], range(1, ChannelCategory::MAX_PER_SERVER + 1))], ['categories']],
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     * @param  list<string>  $errors
     */
    #[DataProvider('moreMalformedLayouts')]
    public function test_more_malformed_layouts_fail_validation_without_a_server_error(array $layout, array $errors): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $this->actingAs($owner)->putJson("/servers/{$server->id}/layout", $layout)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);
    }

    public function test_the_reorder_endpoint_is_rate_limited(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $layout = ['uncategorized' => [$server->channels()->sole()->id], 'categories' => []];

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($owner)->putJson("/servers/{$server->id}/layout", $layout)->assertOk();
        }

        $this->actingAs($owner)->putJson("/servers/{$server->id}/layout", $layout)->assertTooManyRequests();
    }

    public function test_reorder_on_an_unknown_server_is_not_found(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/servers/999999/layout', ['uncategorized' => [], 'categories' => []])
            ->assertNotFound();
    }
}
