<?php

namespace Tests\Feature\Messages;

use App\Actions\Servers\CreateServer;
use App\Actions\Servers\LeaveServer;
use App\Models\Channel;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * ISSUE-17: routes/channels.php lets only members of the channel's server
 * join the "presence-typing.{id}" channel used for typing whispers, and
 * hands the browser {id, name} as their member info.
 */
class TypingChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-key';

    private const SECRET = 'test-secret';

    private User $owner;

    private User $member;

    private Server $server;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml uses the "null" broadcaster; channel auth needs Reverb.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => self::KEY,
            'broadcasting.connections.reverb.secret' => self::SECRET,
            'broadcasting.connections.reverb.app_id' => '1001',
        ]);
        require base_path('routes/channels.php');

        $this->owner = User::factory()->create(['name' => 'Owner Name']);
        $this->member = User::factory()->create(['name' => 'Member Name']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Test Server');
        ServerMember::factory()->create([
            'server_id' => $this->server->id,
            'user_id' => $this->member->id,
            'nickname' => null,
        ]);

        /** @var Channel $general */
        $general = $this->server->channels()->firstOrFail();
        $this->channel = $general;
    }

    /**
     * @return TestResponse<Response>
     */
    private function authorize(string $channel): TestResponse
    {
        return $this->post('/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ]);
    }

    private function typingChannel(): string
    {
        return "presence-typing.{$this->channel->id}";
    }

    public function test_members_join_with_their_id_and_name_as_member_info(): void
    {
        $response = $this->actingAs($this->member)->authorize($this->typingChannel())->assertOk();

        $channelData = $response->json('channel_data');
        $this->assertIsString($channelData);
        // Laravel sends user_id as a string; the script compares ids as strings.
        $this->assertSame(
            ['user_id' => (string) $this->member->id, 'user_info' => ['id' => $this->member->id, 'name' => 'Member Name']],
            json_decode($channelData, true),
        );

        // Pusher protocol: the signature covers socket id, channel and channel_data.
        $expected = self::KEY.':'.hash_hmac('sha256', "1234.5678:{$this->typingChannel()}:{$channelData}", self::SECRET);
        $this->assertSame($expected, $response->json('auth'));
    }

    public function test_the_server_nickname_is_shown_instead_of_the_name(): void
    {
        ServerMember::query()
            ->where('server_id', $this->server->id)
            ->where('user_id', $this->member->id)
            ->update(['nickname' => 'Nick']);

        $response = $this->actingAs($this->member)->authorize($this->typingChannel())->assertOk();

        $this->assertSame('Nick', json_decode((string) $response->json('channel_data'), true)['user_info']['name']);
    }

    public function test_the_owner_can_join(): void
    {
        $response = $this->actingAs($this->owner)->authorize($this->typingChannel())->assertOk();

        $this->assertSame((string) $this->owner->id, json_decode((string) $response->json('channel_data'), true)['user_id']);
    }

    public function test_non_members_and_members_of_another_server_cannot_join(): void
    {
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->authorize($this->typingChannel())->assertForbidden();

        $neighbour = User::factory()->create();
        $other = app(CreateServer::class)->create($neighbour, 'Other Server');
        $this->actingAs($neighbour)->authorize($this->typingChannel())->assertForbidden();
        $this->actingAs($this->member)
            ->authorize('presence-typing.'.$other->channels()->firstOrFail()->id)
            ->assertForbidden();
    }

    public function test_a_member_who_left_can_no_longer_join(): void
    {
        app(LeaveServer::class)->leave($this->server, $this->member);

        $this->actingAs($this->member)->authorize($this->typingChannel())->assertForbidden();
    }

    public function test_guests_cannot_join(): void
    {
        $this->authorize($this->typingChannel())->assertForbidden();
    }

    public function test_unknown_or_malformed_channel_ids_are_rejected(): void
    {
        foreach (['presence-typing.999999', 'presence-typing.abc', 'presence-typing.'] as $name) {
            $this->actingAs($this->member)->authorize($name)->assertForbidden();
        }
    }

    public function test_members_can_join_the_typing_channel_of_any_channel_of_their_server(): void
    {
        $random = Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'random']);

        $response = $this->actingAs($this->member)->authorize("presence-typing.{$random->id}")->assertOk();

        $this->assertSame('Member Name', json_decode((string) $response->json('channel_data'), true)['user_info']['name']);
    }

    public function test_reverb_accepts_whispers_from_members_by_default(): void
    {
        // The indicator relies on Reverb adding the sender's user_id, which
        // it only does when client events are limited to channel members.
        $app = collect(config()->array('reverb.apps.apps'))->first();

        $this->assertSame('members', $app['accept_client_events_from']);
    }
}
