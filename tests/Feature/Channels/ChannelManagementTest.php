<?php

namespace Tests\Feature\Channels;

use App\Actions\Servers\CreateServer;
use App\Models\Channel;
use App\Models\ChannelCategory;
use App\Models\Invite;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ISSUE-12: creating, opening, renaming and deleting text channels, and the
 * channel topic.
 */
class ChannelManagementTest extends TestCase
{
    use RefreshDatabase;

    private function serverOwnedBy(User $owner, string $name = 'Test Server'): Server
    {
        return app(CreateServer::class)->create($owner, $name);
    }

    private function memberOf(Server $server): User
    {
        $user = User::factory()->create();
        ServerMember::factory()->create(['server_id' => $server->id, 'user_id' => $user->id]);

        return $user;
    }

    // --- Create ----------------------------------------------------------

    public function test_the_owner_can_create_a_text_channel_with_a_topic(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $response = $this->actingAs($owner)->post("/servers/{$server->id}/channels", [
            'name' => 'Help Desk',
            'topic' => 'Ask your questions here',
        ]);

        $channel = $server->channels()->where('name', 'help-desk')->sole();
        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('channels.show', [$server, $channel]))
            ->assertSessionHas('status', '#help-desk has been created.');

        $this->assertSame(Channel::TYPE_TEXT, $channel->type);
        $this->assertSame('Ask your questions here', $channel->topic);
        $this->assertNull($channel->category_id);
        // #general is at position 0, so the new channel goes below it.
        $this->assertSame(1, $channel->position);
    }

    public function test_the_create_page_preselects_a_category_of_this_server_only(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => 'Talk']);
        $foreign = ChannelCategory::factory()->create();

        $this->actingAs($owner)->get("/servers/{$server->id}/channels/create?category={$category->id}")
            ->assertOk()
            ->assertSee("<option value=\"{$category->id}\" selected", false);

        $this->actingAs($owner)->get("/servers/{$server->id}/channels/create?category={$foreign->id}")
            ->assertOk()
            ->assertDontSee('selected', false)
            ->assertDontSee($foreign->name);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function channelNames(): array
    {
        return [
            'spaces and capitals' => ['My Cool Chat', 'my-cool-chat'],
            'surrounding and repeated whitespace' => ["  off \t  topic  ", 'off-topic'],
            'punctuation is dropped' => ['what?! #news & stuff', 'what-news-stuff'],
            'repeated dashes collapse' => ['a -- b', 'a-b'],
            'underscores stay' => ['dev_ops', 'dev_ops'],
            'unicode letters stay' => ['Café Ünïcode', 'café-ünïcode'],
            'leading and trailing dashes are trimmed' => ['-edge-', 'edge'],
        ];
    }

    #[DataProvider('channelNames')]
    public function test_text_channel_names_are_normalised_like_discord(string $input, string $expected): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", ['name' => $input])->assertSessionHasNoErrors();

        $this->assertTrue($server->channels()->where('name', $expected)->exists(), "Expected a channel named [{$expected}].");
    }

    public function test_a_name_without_letters_or_digits_is_rejected(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", ['name' => '!!! ???'])
            ->assertSessionHasErrors(['name' => 'Enter a channel name with at least one letter or number.']);

        $this->assertSame(1, $server->channels()->count());
    }

    public function test_name_and_topic_lengths_are_limited(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", [
            'name' => str_repeat('a', Channel::MAX_NAME_LENGTH + 1),
            'topic' => str_repeat('t', Channel::MAX_TOPIC_LENGTH + 1),
        ])->assertSessionHasErrors(['name', 'topic']);

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", [
            'name' => str_repeat('a', Channel::MAX_NAME_LENGTH),
            'topic' => str_repeat('t', Channel::MAX_TOPIC_LENGTH),
        ])->assertSessionHasNoErrors();
    }

    public function test_non_string_input_is_rejected_without_errors(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", ['name' => ['x'], 'topic' => ['y'], 'category_id' => 'abc'])
            ->assertSessionHasErrors(['name', 'topic', 'category_id']);
    }

    public function test_a_channel_can_be_created_in_a_category_at_its_bottom(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id]);
        Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id, 'position' => 7]);

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", ['name' => 'inside', 'category_id' => $category->id])
            ->assertSessionHasNoErrors();

        $channel = $server->channels()->where('name', 'inside')->sole();
        $this->assertSame($category->id, $channel->category_id);
        $this->assertSame(8, $channel->position);
    }

    public function test_a_category_of_another_server_is_rejected(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $foreign = ChannelCategory::factory()->create();

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", ['name' => 'sneaky', 'category_id' => $foreign->id])
            ->assertSessionHasErrors(['category_id' => 'That category does not exist in this server.']);

        $this->assertFalse(Channel::where('name', 'sneaky')->exists());
    }

    public function test_mass_assigned_type_and_position_are_ignored(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $other = Server::factory()->create();

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", [
            'name' => 'plain',
            'type' => Channel::TYPE_VOICE,
            'position' => 99,
            'server_id' => $other->id,
        ])->assertSessionHasNoErrors();

        $channel = Channel::where('name', 'plain')->sole();
        $this->assertSame(Channel::TYPE_TEXT, $channel->type);
        $this->assertSame(1, $channel->position);
        $this->assertSame($server->id, $channel->server_id);
    }

    public function test_a_server_can_have_at_most_500_channels(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $now = now();
        DB::table('channels')->insert(array_map(fn (int $i) => [
            'server_id' => $server->id, 'name' => "c{$i}", 'type' => 'text', 'position' => $i,
            'created_at' => $now, 'updated_at' => $now,
        ], range(1, Channel::MAX_PER_SERVER - 1)));

        $this->actingAs($owner)->post("/servers/{$server->id}/channels", ['name' => 'one-too-many'])
            ->assertSessionHasErrors(['name' => 'A server can have at most 500 channels.']);

        $this->assertSame(Channel::MAX_PER_SERVER, $server->channels()->count());
    }

    public function test_only_the_owner_can_create_channels(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $member = $this->memberOf($server);
        $stranger = User::factory()->create();

        foreach ([$member, $stranger] as $user) {
            $this->actingAs($user)->get("/servers/{$server->id}/channels/create")->assertForbidden();
            $this->actingAs($user)->post("/servers/{$server->id}/channels", ['name' => 'nope'])->assertForbidden();
        }

        $this->assertSame(1, $server->channels()->count());
    }

    public function test_guests_and_unverified_users_are_sent_away(): void
    {
        $server = Server::factory()->create();

        $this->post("/servers/{$server->id}/channels", ['name' => 'x'])->assertRedirect('/login');
        $this->actingAs(User::factory()->unverified()->create())
            ->post("/servers/{$server->id}/channels", ['name' => 'x'])
            ->assertRedirect(route('verification.notice'));
    }

    // --- Open ------------------------------------------------------------

    public function test_members_can_open_a_channel_and_see_its_topic(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Topical');
        $member = $this->memberOf($server);
        $channel = Channel::factory()->create(['server_id' => $server->id, 'name' => 'news', 'topic' => 'Fresh <b>news</b>', 'position' => 3]);

        $this->actingAs($member)->get("/servers/{$server->id}/channels/{$channel->id}")
            ->assertOk()
            ->assertViewIs('servers.show')
            ->assertSee('<title>#news · Topical', false)
            ->assertSee('Welcome to #news!')
            ->assertSee('Fresh &lt;b&gt;news&lt;/b&gt;', false)
            ->assertDontSee('Fresh <b>news</b>', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_strangers_cannot_open_channels(): void
    {
        $server = $this->serverOwnedBy(User::factory()->create());
        $channel = $server->channels()->sole();

        $this->actingAs(User::factory()->create())
            ->get("/servers/{$server->id}/channels/{$channel->id}")
            ->assertForbidden();
    }

    public function test_a_channel_of_another_server_is_not_found(): void
    {
        $owner = User::factory()->create();
        $mine = $this->serverOwnedBy($owner, 'Mine');
        $theirs = $this->serverOwnedBy(User::factory()->create(), 'Theirs');
        $foreign = $theirs->channels()->sole();

        $this->actingAs($owner)->get("/servers/{$mine->id}/channels/{$foreign->id}")->assertNotFound();
        $this->actingAs($owner)->get("/servers/{$mine->id}/channels/{$foreign->id}/settings")->assertNotFound();
        $this->actingAs($owner)->patch("/servers/{$mine->id}/channels/{$foreign->id}", ['name' => 'hijack'])->assertNotFound();
        $this->actingAs($owner)->delete("/servers/{$mine->id}/channels/{$foreign->id}")->assertNotFound();

        $this->assertSame('general', $foreign->fresh()?->name);
    }

    public function test_the_server_page_opens_the_first_text_channel_in_sidebar_order(): void
    {
        $owner = User::factory()->create();
        $server = Server::factory()->create(['owner_id' => $owner->id]);
        ServerMember::factory()->create(['server_id' => $server->id, 'user_id' => $owner->id]);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id, 'position' => 0]);
        Channel::factory()->voice()->create(['server_id' => $server->id, 'name' => 'Lobby', 'position' => 0]);
        Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id, 'name' => 'in-category', 'position' => 0]);
        Channel::factory()->create(['server_id' => $server->id, 'name' => 'uncategorised', 'position' => 5]);

        $this->actingAs($owner)->get("/servers/{$server->id}")
            ->assertOk()
            ->assertViewHas('channel', fn (?Channel $channel) => $channel?->name === 'uncategorised');
    }

    public function test_a_server_without_text_channels_says_so(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $server->channels()->delete();
        $member = $this->memberOf($server);

        $this->actingAs($owner)->get("/servers/{$server->id}")
            ->assertOk()
            ->assertSee('This server has no text channels yet.')
            ->assertSee(route('channels.create', $server), false);

        $this->actingAs($member)->get("/servers/{$server->id}")
            ->assertOk()
            ->assertSee('This server has no text channels yet.')
            ->assertDontSee(route('channels.create', $server), false);
    }

    // --- Update ----------------------------------------------------------

    public function test_the_owner_can_rename_a_channel_and_change_or_clear_its_topic(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $channel = $server->channels()->sole();

        $this->actingAs($owner)->get("/servers/{$server->id}/channels/{$channel->id}/settings")
            ->assertOk()
            ->assertSee('Channel settings')
            ->assertSee('value="general"', false);

        $this->actingAs($owner)->patch("/servers/{$server->id}/channels/{$channel->id}", ['name' => 'Lobby Chat', 'topic' => 'Say hi'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('channels.edit', [$server, $channel]))
            ->assertSessionHas('status', 'Channel settings saved.');

        $channel->refresh();
        $this->assertSame('lobby-chat', $channel->name);
        $this->assertSame('Say hi', $channel->topic);

        $this->actingAs($owner)->patch("/servers/{$server->id}/channels/{$channel->id}", ['name' => 'lobby-chat', 'topic' => '   '])
            ->assertSessionHasNoErrors();
        $this->assertNull($channel->fresh()?->topic);
    }

    public function test_moving_a_channel_to_another_category_puts_it_at_the_bottom(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id]);
        Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id, 'position' => 4]);
        $channel = $server->channels()->where('name', 'general')->sole();

        $this->actingAs($owner)->patch("/servers/{$server->id}/channels/{$channel->id}", ['name' => 'general', 'category_id' => $category->id])
            ->assertSessionHasNoErrors();

        $channel->refresh();
        $this->assertSame($category->id, $channel->category_id);
        $this->assertSame(5, $channel->position);
    }

    public function test_saving_without_changing_the_category_keeps_the_position(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $channel = Channel::factory()->create(['server_id' => $server->id, 'name' => 'middle', 'position' => 3]);
        Channel::factory()->create(['server_id' => $server->id, 'position' => 9]);

        $this->actingAs($owner)->patch("/servers/{$server->id}/channels/{$channel->id}", ['name' => 'renamed'])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $channel->fresh()?->position);
    }

    public function test_voice_channels_keep_their_spaces_and_have_no_topic(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $voice = Channel::factory()->voice()->create(['server_id' => $server->id, 'name' => 'Lounge']);

        $this->actingAs($owner)->get("/servers/{$server->id}/channels/{$voice->id}/settings")
            ->assertOk()
            ->assertDontSee('Channel topic');

        $this->actingAs($owner)->patch("/servers/{$server->id}/channels/{$voice->id}", ['name' => '  Gaming   Room ', 'topic' => 'ignored'])
            ->assertSessionHasNoErrors();

        $voice->refresh();
        $this->assertSame('Gaming Room', $voice->name);
        $this->assertNull($voice->topic);
    }

    public function test_only_the_owner_can_edit_channels(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $member = $this->memberOf($server);
        $channel = $server->channels()->sole();

        $this->actingAs($member)->get("/servers/{$server->id}/channels/{$channel->id}/settings")->assertForbidden();
        $this->actingAs($member)->patch("/servers/{$server->id}/channels/{$channel->id}", ['name' => 'mine-now'])->assertForbidden();

        $this->assertSame('general', $channel->fresh()?->name);
    }

    // --- Delete ----------------------------------------------------------

    public function test_the_owner_can_delete_a_channel_with_its_messages(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $doomed = Channel::factory()->create(['server_id' => $server->id, 'name' => 'doomed']);
        $kept = $server->channels()->where('name', 'general')->sole();
        Message::factory()->count(3)->create(['channel_id' => $doomed->id, 'user_id' => $owner->id]);
        $keptMessage = Message::factory()->create(['channel_id' => $kept->id, 'user_id' => $owner->id]);
        $invite = Invite::factory()->create(['server_id' => $server->id, 'channel_id' => $doomed->id, 'inviter_id' => $owner->id]);

        $this->actingAs($owner)->delete("/servers/{$server->id}/channels/{$doomed->id}")
            ->assertRedirect(route('servers.show', $server))
            ->assertSessionHas('status', '#doomed has been deleted.');

        $this->assertModelMissing($doomed);
        $this->assertSame(0, Message::where('channel_id', $doomed->id)->count());
        $this->assertModelExists($keptMessage);
        $this->assertNull($invite->fresh()?->channel_id);
    }

    public function test_the_last_channel_can_be_deleted(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $this->actingAs($owner)->delete("/servers/{$server->id}/channels/{$server->channels()->sole()->id}")
            ->assertRedirect(route('servers.show', $server));

        $this->actingAs($owner)->get("/servers/{$server->id}")->assertOk()->assertSee('This server has no text channels yet.');
    }

    public function test_only_the_owner_can_delete_channels(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $member = $this->memberOf($server);
        $channel = $server->channels()->sole();

        $this->actingAs($member)->delete("/servers/{$server->id}/channels/{$channel->id}")->assertForbidden();
        $this->actingAs(User::factory()->create())->delete("/servers/{$server->id}/channels/{$channel->id}")->assertForbidden();

        $this->assertModelExists($channel);
    }

    public function test_the_delete_form_asks_for_confirmation(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $channel = $server->channels()->sole();

        $this->actingAs($owner)->get("/servers/{$server->id}/channels/{$channel->id}/settings")
            ->assertSee('data-confirm="Delete #general? Its messages are deleted too. This cannot be undone."', false);
    }
}
