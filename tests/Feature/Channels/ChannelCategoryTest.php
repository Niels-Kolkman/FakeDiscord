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
use Tests\TestCase;

/**
 * Channel categories and how the sidebar groups channels under them.
 */
class ChannelCategoryTest extends TestCase
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

    public function test_the_owner_can_create_categories_below_the_existing_ones(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $this->actingAs($owner)->get("/servers/{$server->id}/categories/create")->assertOk()->assertSee('Create category');

        $this->actingAs($owner)->post("/servers/{$server->id}/categories", ['name' => '  Text   Channels '])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('servers.show', $server))
            ->assertSessionHas('status', 'The category "Text Channels" has been created.');
        $this->actingAs($owner)->post("/servers/{$server->id}/categories", ['name' => 'Voice'])->assertSessionHasNoErrors();

        $this->assertSame(
            [['Text Channels', 0], ['Voice', 1]],
            $server->categories()->get()->map(fn (ChannelCategory $c) => [$c->name, $c->position])->all(),
        );
    }

    public function test_category_names_are_required_and_limited(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);

        $this->actingAs($owner)->post("/servers/{$server->id}/categories", ['name' => '   '])->assertSessionHasErrors('name');
        $this->actingAs($owner)->post("/servers/{$server->id}/categories", ['name' => ['x']])->assertSessionHasErrors('name');
        $this->actingAs($owner)->post("/servers/{$server->id}/categories", ['name' => str_repeat('a', ChannelCategory::MAX_NAME_LENGTH + 1)])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $server->categories()->count());
    }

    public function test_a_server_can_have_at_most_50_categories(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $now = now();
        DB::table('channel_categories')->insert(array_map(fn (int $i) => [
            'server_id' => $server->id, 'name' => "Cat {$i}", 'position' => $i, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, ChannelCategory::MAX_PER_SERVER)));

        $this->actingAs($owner)->post("/servers/{$server->id}/categories", ['name' => 'Too many'])
            ->assertSessionHasErrors(['name' => 'A server can have at most 50 categories.']);
    }

    public function test_the_owner_can_rename_a_category(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => 'Old']);

        $this->actingAs($owner)->get("/servers/{$server->id}/categories/{$category->id}/settings")
            ->assertOk()
            ->assertSee('value="Old"', false);

        $this->actingAs($owner)->patch("/servers/{$server->id}/categories/{$category->id}", ['name' => 'New Name'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('categories.edit', [$server, $category]));

        $this->assertSame('New Name', $category->fresh()?->name);
    }

    public function test_deleting_a_category_keeps_its_channels_at_the_bottom_of_the_uncategorised_list(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner); // #general at position 0
        Channel::factory()->create(['server_id' => $server->id, 'name' => 'loose', 'position' => 4]);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => 'Doomed']);
        $second = Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id, 'name' => 'second', 'position' => 1]);
        $first = Channel::factory()->create(['server_id' => $server->id, 'category_id' => $category->id, 'name' => 'first', 'position' => 0]);

        $this->actingAs($owner)->delete("/servers/{$server->id}/categories/{$category->id}")
            ->assertRedirect(route('servers.show', $server))
            ->assertSessionHas('status', 'The category "Doomed" has been deleted.');

        $this->assertModelMissing($category);
        $this->assertNull($first->fresh()?->category_id);
        $this->assertNull($second->fresh()?->category_id);
        $this->assertSame(
            ['general', 'loose', 'first', 'second'],
            $server->channelLayout()->uncategorized->pluck('name')->all(),
        );
    }

    public function test_the_database_also_uncategorises_channels_when_a_category_row_is_deleted(): void
    {
        $category = ChannelCategory::factory()->create();
        $channel = Channel::factory()->create(['server_id' => $category->server_id, 'category_id' => $category->id]);

        DB::table('channel_categories')->where('id', $category->id)->delete();

        $this->assertNull($channel->fresh()?->category_id);
    }

    public function test_deleting_a_server_deletes_its_categories(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner, 'Bye');
        ChannelCategory::factory()->count(2)->create(['server_id' => $server->id]);
        $other = ChannelCategory::factory()->create();

        $this->actingAs($owner)->delete("/servers/{$server->id}", ['confirm_name' => 'Bye'])->assertSessionHasNoErrors();

        $this->assertSame(0, ChannelCategory::where('server_id', $server->id)->count());
        $this->assertModelExists($other);
    }

    public function test_only_the_owner_can_manage_categories(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $member = $this->memberOf($server);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => 'Kept']);

        $this->actingAs($member)->get("/servers/{$server->id}/categories/create")->assertForbidden();
        $this->actingAs($member)->post("/servers/{$server->id}/categories", ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($member)->get("/servers/{$server->id}/categories/{$category->id}/settings")->assertForbidden();
        $this->actingAs($member)->patch("/servers/{$server->id}/categories/{$category->id}", ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($member)->delete("/servers/{$server->id}/categories/{$category->id}")->assertForbidden();

        $this->assertSame('Kept', $category->fresh()?->name);
        $this->assertSame(1, $server->categories()->count());
    }

    public function test_a_category_of_another_server_is_not_found(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $foreign = ChannelCategory::factory()->create(['name' => 'Foreign']);

        $this->actingAs($owner)->get("/servers/{$server->id}/categories/{$foreign->id}/settings")->assertNotFound();
        $this->actingAs($owner)->patch("/servers/{$server->id}/categories/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->actingAs($owner)->delete("/servers/{$server->id}/categories/{$foreign->id}")->assertNotFound();

        $this->assertModelExists($foreign);
    }

    // --- Sidebar ---------------------------------------------------------

    public function test_the_sidebar_lists_uncategorised_channels_then_categories_in_order(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $member = $this->memberOf($server);
        $later = ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => 'Later Category', 'position' => 2]);
        $sooner = ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => 'Sooner Category', 'position' => 1]);
        Channel::factory()->create(['server_id' => $server->id, 'category_id' => $later->id, 'name' => 'in-later', 'position' => 0]);
        Channel::factory()->create(['server_id' => $server->id, 'category_id' => $sooner->id, 'name' => 'sooner-b', 'position' => 1]);
        Channel::factory()->create(['server_id' => $server->id, 'category_id' => $sooner->id, 'name' => 'sooner-a', 'position' => 0]);

        $this->actingAs($member)->get("/servers/{$server->id}")
            ->assertOk()
            ->assertSeeInOrder(['general', 'Sooner Category', 'sooner-a', 'sooner-b', 'Later Category', 'in-later'])
            ->assertSee('aria-expanded="true"', false)
            ->assertSee("aria-controls=\"category-{$sooner->id}-channels\"", false);
    }

    public function test_only_managers_get_drag_handles_and_edit_links(): void
    {
        $owner = User::factory()->create();
        $server = $this->serverOwnedBy($owner);
        $member = $this->memberOf($server);
        $category = ChannelCategory::factory()->create(['server_id' => $server->id]);
        $layoutUrl = route('channels.layout', $server);

        $this->actingAs($owner)->get("/servers/{$server->id}")
            ->assertSee("data-layout-url=\"{$layoutUrl}\"", false)
            ->assertSee('draggable="true"', false)
            ->assertSee(route('categories.edit', [$server, $category]), false)
            ->assertSee(route('categories.create', $server), false)
            ->assertSee('Create channel');

        $this->actingAs($member)->get("/servers/{$server->id}")
            ->assertOk()
            ->assertDontSee('data-layout-url', false)
            ->assertDontSee('draggable="true"', false)
            ->assertDontSee(route('categories.edit', [$server, $category]), false)
            ->assertDontSee('Create channel');
    }
}
