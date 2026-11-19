<?php

namespace Tests\Feature\Notifications;

use App\Actions\Servers\CreateServer;
use App\Enums\Permission;
use App\Http\Controllers\MentionSuggestionController;
use App\Models\Channel;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\GivesPermissions;
use Tests\TestCase;

/**
 * ISSUE-33: what "@q" in a channel's composer could become.
 */
class MentionSuggestionsTest extends TestCase
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

        $this->owner = User::factory()->create(['username' => 'owner', 'name' => 'Owner']);
        $this->bob = User::factory()->create(['username' => 'bob', 'name' => 'Robert']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Suggest');
        $this->joinServer($this->server, $this->bob);

        /** @var Channel $general */
        $general = $this->server->channels()->where('type', Channel::TYPE_TEXT)->orderBy('position')->firstOrFail();
        $this->general = $general;
    }

    /**
     * @return TestResponse<Response>
     */
    private function suggest(User $as, string $query, ?Channel $channel = null): TestResponse
    {
        return $this->actingAs($as)->getJson(route('mentions.index', [$this->server, $channel ?? $this->general, 'q' => $query]));
    }

    public function test_members_match_by_username_nickname_or_name(): void
    {
        $carol = User::factory()->create(['username' => 'carol', 'name' => 'Carol']);
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $carol->id, 'nickname' => 'Bobcat']);
        User::factory()->create(['username' => 'bobby']);

        $this->suggest($this->owner, 'bo')
            ->assertOk()
            ->assertJsonPath('users.*.username', ['bob', 'carol']);

        $this->suggest($this->owner, 'ROB')->assertJsonPath('users.*.username', ['bob']);
        $this->suggest($this->owner, 'bobc')->assertJsonPath('users.0.name', 'Bobcat');
    }

    public function test_a_user_suggestion_has_what_the_list_shows(): void
    {
        $this->suggest($this->owner, 'bob')->assertJsonPath('users.0', [
            'id' => $this->bob->id,
            'username' => 'bob',
            'name' => 'Robert',
            'avatar_url' => $this->bob->avatarUrl(),
        ]);
    }

    public function test_members_who_cannot_see_the_channel_are_left_out(): void
    {
        $secret = $this->server->channels()->create(['name' => 'secret', 'type' => Channel::TYPE_TEXT, 'position' => 9, 'is_private' => true]);

        $this->suggest($this->owner, '', $secret)->assertJsonPath('users.*.username', ['owner']);
    }

    public function test_roles_match_by_name_without_everyone(): void
    {
        $mods = $this->makeRole($this->server, 'Mods', 0);
        $mods->update(['color' => '#ff0000']);
        $this->makeRole($this->server, 'Members', 0);

        $this->suggest($this->owner, 'mo')->assertJsonPath('roles', [['id' => $mods->id, 'name' => 'Mods', 'color' => '#ff0000']]);
        $this->suggest($this->owner, 'every')->assertJsonPath('roles', []);
    }

    public function test_everyone_is_only_offered_to_those_who_may_use_it(): void
    {
        $this->suggest($this->owner, 'ev')->assertJsonPath('everyone', true);
        $this->suggest($this->owner, '')->assertJsonPath('everyone', true);
        $this->suggest($this->owner, 'bob')->assertJsonPath('everyone', false);
        $this->suggest($this->bob, 'ev')->assertJsonPath('everyone', false);

        $herald = $this->memberWith($this->server, Permission::MentionEveryone);
        $this->suggest($herald, 'ev')->assertJsonPath('everyone', true);
    }

    public function test_like_wildcards_are_taken_literally(): void
    {
        $this->joinServer($this->server, User::factory()->create(['username' => 'a_b']));
        $this->joinServer($this->server, User::factory()->create(['username' => 'axb']));

        $this->suggest($this->owner, 'a_')->assertJsonPath('users.*.username', ['a_b']);
        $this->suggest($this->owner, '%')->assertJsonPath('users', []);
    }

    public function test_the_list_is_capped(): void
    {
        foreach (range(1, MentionSuggestionController::MAX_USERS + 3) as $i) {
            $this->joinServer($this->server, User::factory()->create(['username' => "zed{$i}"]));
        }

        $this->suggest($this->owner, 'zed')->assertJsonCount(MentionSuggestionController::MAX_USERS, 'users');
    }

    public function test_only_members_who_can_send_get_suggestions(): void
    {
        $this->suggest(User::factory()->create(), 'b')->assertForbidden();

        $this->revokeFromEveryone($this->server, Permission::SendMessages);
        $this->suggest($this->bob, 'b')->assertForbidden();
    }

    public function test_guests_get_a_401(): void
    {
        $this->getJson(route('mentions.index', [$this->server, $this->general]))->assertUnauthorized();
    }

    public function test_the_query_is_validated(): void
    {
        $this->suggest($this->owner, str_repeat('a', MentionSuggestionController::MAX_QUERY_LENGTH + 1))->assertUnprocessable();
    }
}
