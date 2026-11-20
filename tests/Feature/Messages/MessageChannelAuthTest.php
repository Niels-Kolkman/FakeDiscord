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
 * Routes/channels.php lets only members of the channel's server
 * subscribe to "private-channel.{id}".
 */
class MessageChannelAuthTest extends TestCase
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

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->server = app(CreateServer::class)->create($this->owner, 'Test Server');
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $this->member->id]);

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

    public function test_members_and_the_owner_can_subscribe(): void
    {
        $name = "private-channel.{$this->channel->id}";
        $expected = self::KEY.':'.hash_hmac('sha256', "1234.5678:{$name}", self::SECRET);

        foreach ([$this->owner, $this->member] as $user) {
            $this->actingAs($user)->authorize($name)->assertOk()->assertJsonPath('auth', $expected);
        }
    }

    public function test_members_can_subscribe_to_every_channel_of_their_server(): void
    {
        $other = Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'random']);

        $this->actingAs($this->member)->authorize("private-channel.{$other->id}")->assertOk();
    }

    public function test_non_members_cannot_subscribe(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->authorize("private-channel.{$this->channel->id}")->assertForbidden();
    }

    public function test_members_of_another_server_cannot_subscribe(): void
    {
        $neighbour = User::factory()->create();
        $other = app(CreateServer::class)->create($neighbour, 'Other Server');

        $this->actingAs($neighbour)->authorize("private-channel.{$this->channel->id}")->assertForbidden();
        $this->actingAs($this->member)->authorize('private-channel.'.$other->channels()->firstOrFail()->id)->assertForbidden();
    }

    public function test_a_member_who_left_can_no_longer_subscribe(): void
    {
        app(LeaveServer::class)->leave($this->server, $this->member);

        $this->actingAs($this->member)->authorize("private-channel.{$this->channel->id}")->assertForbidden();
    }

    public function test_guests_cannot_subscribe(): void
    {
        $this->authorize("private-channel.{$this->channel->id}")->assertForbidden();
    }

    public function test_unknown_or_malformed_channel_ids_are_rejected(): void
    {
        foreach (['private-channel.999999', 'private-channel.abc', 'private-channel.'] as $name) {
            $this->actingAs($this->member)->authorize($name)->assertForbidden();
        }
    }
}
