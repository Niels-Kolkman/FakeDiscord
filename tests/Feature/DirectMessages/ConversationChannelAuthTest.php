<?php

namespace Tests\Feature\DirectMessages;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * ISSUE-23: routes/channels.php lets only the two participants subscribe
 * to a DM's private "conversation.{id}" channel and its typing presence
 * channel "conversation-typing.{id}".
 */
class ConversationChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-key';

    private const SECRET = 'test-secret';

    private User $alice;

    private User $bob;

    private Conversation $conversation;

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

        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        $this->conversation = Conversation::factory()->between($this->alice, $this->bob)->create();
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

    public function test_both_participants_can_subscribe_to_the_conversation(): void
    {
        $name = "private-conversation.{$this->conversation->id}";
        $expected = self::KEY.':'.hash_hmac('sha256', "1234.5678:{$name}", self::SECRET);

        foreach ([$this->alice, $this->bob] as $user) {
            $this->actingAs($user)->authorize($name)->assertOk()->assertJsonPath('auth', $expected);
        }
    }

    public function test_others_cannot_subscribe(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->authorize("private-conversation.{$this->conversation->id}")->assertForbidden();
        $this->actingAs($stranger)->authorize("presence-conversation-typing.{$this->conversation->id}")->assertForbidden();
        $this->actingAs($this->alice)->authorize('private-conversation.999999')->assertForbidden();
    }

    public function test_guests_cannot_subscribe(): void
    {
        $this->authorize("private-conversation.{$this->conversation->id}")->assertForbidden();
    }

    public function test_participants_join_the_typing_channel_with_their_name(): void
    {
        $response = $this->actingAs($this->bob)
            ->authorize("presence-conversation-typing.{$this->conversation->id}")
            ->assertOk();

        $channelData = json_decode((string) $response->json('channel_data'), true);
        // Laravel sends user_id as a string; the script compares ids as strings.
        $this->assertSame(['user_id' => (string) $this->bob->id, 'user_info' => ['id' => $this->bob->id, 'name' => 'Bob']], $channelData);
    }

    public function test_a_conversation_id_does_not_open_the_server_channel_with_that_number(): void
    {
        $this->actingAs($this->alice)->authorize("private-channel.{$this->conversation->id}")->assertForbidden();
    }
}
