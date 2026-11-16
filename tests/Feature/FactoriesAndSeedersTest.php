<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ISSUE-4: a factory for every Eloquent model, and the DemoSeeder.
 */
class FactoriesAndSeedersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function models(): array
    {
        $models = [];

        foreach (glob(dirname(__DIR__, 2).'/app/Models/*.php') as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $models[class_basename($class)] = [$class];
        }

        return $models;
    }

    /**
     * @param  class-string<Model>  $class
     */
    #[DataProvider('models')]
    public function test_every_model_has_a_working_factory(string $class): void
    {
        $this->assertTrue(method_exists($class, 'factory'), "{$class} does not use HasFactory");

        $models = $class::factory()->count(2)->create();

        $this->assertCount(2, $models);
        $models->each(fn (Model $model) => $this->assertModelExists($model));
    }

    public function test_message_states_keep_exactly_one_target(): void
    {
        $channelMessage = Message::factory()->create();
        $this->assertNotNull($channelMessage->channel_id);
        $this->assertNull($channelMessage->conversation_id);

        $conversation = Conversation::factory()->create();
        $dm = Message::factory()->inConversation($conversation)->create();
        $this->assertNull($dm->channel_id);
        $this->assertSame($conversation->id, $dm->conversation_id);

        $reply = Message::factory()->replyTo($dm)->create();
        $this->assertTrue($reply->replyTo->is($dm));
        $this->assertSame($conversation->id, $reply->conversation_id);
        $this->assertNull($reply->channel_id);

        // A DM must not create a stray channel/server through the default state.
        $this->assertSame(1, Channel::count());
    }

    public function test_named_states(): void
    {
        $everyone = Role::factory()->everyone()->create();
        $this->assertTrue($everyone->is_default);
        $this->assertSame('@everyone', $everyone->name);

        $this->assertSame(Channel::TYPE_VOICE, Channel::factory()->voice()->create()->type);
        $this->assertSame(Conversation::TYPE_GROUP, Conversation::factory()->group()->create()->type);
        $this->assertNotNull(Conversation::factory()->group()->create()->owner);
    }

    public function test_demo_seeder_creates_the_documented_data_set(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(DemoSeeder::USER_COUNT, User::count());
        $this->assertSame(2, Server::count());
        $this->assertDatabaseHas('users', ['username' => 'testuser', 'email' => 'test@example.com']);

        $channelMessages = Message::whereNotNull('channel_id')->count();
        $this->assertSame(2 * DemoSeeder::MESSAGES_PER_SERVER, $channelMessages);
        $this->assertGreaterThan(0, Message::whereNotNull('conversation_id')->count());

        foreach (Server::all() as $server) {
            $this->assertGreaterThanOrEqual(3, $server->channels()->where('type', '!=', Channel::TYPE_VOICE)->count());
            $this->assertSame(1, $server->roles()->where('is_default', true)->count());
            $this->assertTrue($server->members()->whereKey($server->owner_id)->exists(), 'owner is a member');
            $this->assertTrue($server->members()->where('username', 'testuser')->exists());
        }

        $this->assertGreaterThan(0, DB::table('reactions')->count());
        $this->assertGreaterThan(0, DB::table('friendships')->count());
        $this->assertGreaterThan(0, DB::table('invites')->count());
    }

    public function test_demo_messages_are_consistent(): void
    {
        $this->seed(DemoSeeder::class);

        // Exactly one of channel_id / conversation_id.
        $this->assertSame(0, Message::whereNull('channel_id')->whereNull('conversation_id')->count());
        $this->assertSame(0, Message::whereNotNull('channel_id')->whereNotNull('conversation_id')->count());

        // Channel authors are members of that server; nobody posts in voice channels.
        $nonMemberAuthors = DB::table('messages')
            ->join('channels', 'channels.id', '=', 'messages.channel_id')
            ->leftJoin('server_members', function ($join) {
                $join->on('server_members.server_id', '=', 'channels.server_id')
                    ->on('server_members.user_id', '=', 'messages.user_id');
            })
            ->whereNull('server_members.id')
            ->count();
        $this->assertSame(0, $nonMemberAuthors);
        $this->assertSame(0, Message::whereHas('channel', fn ($q) => $q->where('type', Channel::TYPE_VOICE))->count());

        // DM authors are participants of that conversation.
        $nonParticipantAuthors = DB::table('messages')
            ->whereNotNull('conversation_id')
            ->whereNotExists(function ($q) {
                $q->from('conversation_participants')
                    ->whereColumn('conversation_participants.conversation_id', 'messages.conversation_id')
                    ->whereColumn('conversation_participants.user_id', 'messages.user_id');
            })
            ->count();
        $this->assertSame(0, $nonParticipantAuthors);

        // Replies point to an older message in the same channel.
        Message::whereNotNull('reply_to_id')->with('replyTo')->get()->each(function (Message $reply) {
            $this->assertSame($reply->replyTo->channel_id, $reply->channel_id);
            $this->assertLessThan($reply->id, $reply->replyTo->id);
        });

        $this->assertSame(0, Message::where('created_at', '>', now())->count());
        $this->assertSame(0, Message::where('edited_at', '>', now())->count());
    }

    public function test_database_seeder_runs_the_demo_seeder(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(DemoSeeder::USER_COUNT, User::count());
        $this->assertSame(2, Server::count());
    }
}
