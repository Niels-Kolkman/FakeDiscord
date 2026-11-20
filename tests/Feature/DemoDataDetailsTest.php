<?php

namespace Tests\Feature;

use App\Models\Ban;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Invite;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Factory states not covered by FactoriesAndSeedersTest, and the
 * finer details of the DemoSeeder data set that manual testing relies on.
 */
class DemoDataDetailsTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // Factory states
    // ---------------------------------------------------------------------

    public function test_invite_states(): void
    {
        $default = Invite::factory()->create();
        $this->assertNull($default->expires_at);
        $this->assertNull($default->max_uses);
        $this->assertSame(0, (int) $default->uses);
        $this->assertSame(8, strlen($default->code));

        $expired = Invite::factory()->expired()->create();
        $this->assertNotNull($expired->expires_at);
        $this->assertTrue(now()->greaterThan($expired->expires_at));

        $exhausted = Invite::factory()->exhausted(3)->create();
        $this->assertSame(3, (int) $exhausted->max_uses);
        $this->assertSame(3, (int) $exhausted->uses);
    }

    public function test_friendship_states(): void
    {
        $pending = Friendship::factory()->create();
        $this->assertSame(Friendship::STATUS_PENDING, $pending->status);
        $this->assertNull($pending->accepted_at);
        $this->assertNotSame($pending->user_id, $pending->friend_id);

        $accepted = Friendship::factory()->accepted()->create();
        $this->assertSame(Friendship::STATUS_ACCEPTED, $accepted->status);
        $this->assertNotNull($accepted->accepted_at);

        $blocked = Friendship::factory()->blocked()->create();
        $this->assertSame(Friendship::STATUS_BLOCKED, $blocked->status);
        $this->assertNull($blocked->accepted_at);
    }

    public function test_attachment_states(): void
    {
        $image = MessageAttachment::factory()->create();
        $this->assertSame('image/png', $image->mime_type);
        $this->assertStringEndsWith('/'.$image->original_name, $image->path);
        $metadata = is_string($image->metadata) ? json_decode($image->metadata, true) : $image->metadata;
        $this->assertArrayHasKey('width', $metadata);
        $this->assertArrayHasKey('height', $metadata);

        $document = MessageAttachment::factory()->document()->create();
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertStringEndsWith('.pdf', $document->original_name);
        $this->assertStringEndsWith('/'.$document->original_name, $document->path);
        $this->assertNull($document->metadata);
    }

    public function test_role_channel_message_and_user_states(): void
    {
        $this->assertSame(0, (int) Role::factory()->create()->permissions);
        $this->assertFalse((bool) Role::factory()->create()->is_default);
        $this->assertSame(0b1011, (int) Role::factory()->withPermissions(0b1011)->create()->permissions);

        $this->assertSame(Channel::TYPE_TEXT, Channel::factory()->create()->type);
        $this->assertSame(Channel::TYPE_ANNOUNCEMENT, Channel::factory()->announcement()->create()->type);
        $this->assertNull(Channel::factory()->voice()->create()->topic);

        $this->assertNull(Message::factory()->create()->edited_at);
        $this->assertNotNull(Message::factory()->edited()->create()->edited_at);

        $this->assertNotNull(User::factory()->create()->email_verified_at);
        $this->assertNull(User::factory()->unverified()->create()->email_verified_at);
    }

    public function test_direct_conversation_factory_has_no_name_or_owner(): void
    {
        $direct = Conversation::factory()->create();

        $this->assertSame(Conversation::TYPE_DIRECT, $direct->type);
        $this->assertNull($direct->name);
        $this->assertNull($direct->owner_id);
    }

    public function test_server_factory_creates_only_the_server_row(): void
    {
        $server = Server::factory()->create();

        $this->assertSame(0, $server->channels()->count());
        $this->assertSame(0, $server->roles()->count());
        $this->assertSame(0, $server->memberships()->count());
    }

    public function test_reply_in_channel_stays_in_that_channel(): void
    {
        $original = Message::factory()->create();
        $reply = Message::factory()->replyTo($original)->create();

        $this->assertSame($original->channel_id, $reply->channel_id);
        $this->assertNull($reply->conversation_id);
        $this->assertSame(1, Channel::count(), 'replyTo() must not create a new channel');
    }

    // ---------------------------------------------------------------------
    // DemoSeeder details
    // ---------------------------------------------------------------------

    public function test_demo_user_can_log_in_with_the_documented_password(): void
    {
        $this->seed(DemoSeeder::class);

        $demo = User::where('email', 'test@example.com')->sole();
        $this->assertTrue(Hash::check('password', $demo->password));
        $this->assertNotNull($demo->email_verified_at);

        User::all()->each(fn (User $u) => $this->assertTrue(Hash::check('password', $u->password), "{$u->username} password"));
    }

    public function test_demo_servers_membership_roles_and_owners(): void
    {
        $this->seed(DemoSeeder::class);

        $lounge = Server::where('name', 'Laravel Lounge')->sole();
        $pixel = Server::where('name', 'Pixel Pals')->sole();
        $demo = User::where('username', 'testuser')->sole();

        $this->assertTrue($lounge->owner->is($demo));
        $this->assertFalse($pixel->owner->is($demo));

        $this->assertSame(DemoSeeder::USER_COUNT, $lounge->memberships()->count());
        $this->assertSame(7, $pixel->memberships()->count());

        foreach ([$lounge, $pixel] as $server) {
            $moderator = $server->roles()->where('name', 'Moderator')->sole();
            $this->assertCount(1, $moderator->users);
            $this->assertNotSame($server->owner_id, $moderator->users->first()->id, 'moderator is not the owner');
            $this->assertTrue($server->members()->whereKey($moderator->users->first()->id)->exists(), 'moderator is a member');

            // Members never joined before the server existed.
            $this->assertSame(0, $server->memberships()->where('joined_at', '<', $server->created_at)->count());
        }
    }

    public function test_demo_invites_and_ban(): void
    {
        $this->seed(DemoSeeder::class);

        $lounge = Invite::where('code', 'lounge')->sole();
        $this->assertSame('Laravel Lounge', $lounge->server->name);
        $this->assertSame('general', $lounge->channel->name);
        $this->assertTrue($lounge->channel->server->is($lounge->server));
        $this->assertSame(DemoSeeder::USER_COUNT - 1, (int) $lounge->uses);
        $this->assertNull($lounge->expires_at);

        $pixel = Invite::where('code', 'pixelpals')->sole();
        $this->assertSame('Pixel Pals', $pixel->server->name);
        $this->assertSame(6, (int) $pixel->uses);

        $expired = Invite::where('code', 'expired')->sole();
        $this->assertTrue(now()->greaterThan($expired->expires_at));

        $ban = Ban::sole();
        $this->assertSame('Pixel Pals', $ban->server->name);
        $this->assertTrue($ban->bannedBy->is($ban->server->owner));
        $this->assertFalse(
            $ban->server->members()->whereKey($ban->user_id)->exists(),
            'a banned user must not still be a member of the server',
        );
    }

    public function test_only_the_owner_posts_in_announcement_channels(): void
    {
        $this->seed(DemoSeeder::class);

        $announcements = Channel::where('type', Channel::TYPE_ANNOUNCEMENT)->with('server')->get();
        $this->assertNotEmpty($announcements);

        foreach ($announcements as $channel) {
            $this->assertSame(
                0,
                $channel->messages()->where('user_id', '!=', $channel->server->owner_id)->count(),
                "non-owner posted in #{$channel->name}",
            );
        }
    }

    public function test_demo_conversations(): void
    {
        $this->seed(DemoSeeder::class);

        $direct = Conversation::where('type', Conversation::TYPE_DIRECT)->sole();
        $this->assertCount(2, $direct->users);
        $this->assertTrue($direct->users->contains('username', 'testuser'));
        $this->assertSame(20, $direct->messages()->count());

        $group = Conversation::where('type', Conversation::TYPE_GROUP)->sole();
        $this->assertSame('Weekend Plans', $group->name);
        $this->assertSame('testuser', $group->owner->username);
        $this->assertCount(3, $group->users);
        $this->assertTrue($group->users->contains($group->owner), 'group owner is a participant');
        $this->assertSame(15, $group->messages()->count());
    }

    public function test_demo_friendships_cover_every_status(): void
    {
        $this->seed(DemoSeeder::class);

        $demo = User::where('username', 'testuser')->sole();

        $this->assertSame(0, Friendship::whereColumn('user_id', 'friend_id')->count(), 'no self-friendships');

        $mine = Friendship::where('user_id', $demo->id)->orWhere('friend_id', $demo->id)->get();
        $this->assertSame(3, $mine->where('status', Friendship::STATUS_ACCEPTED)->count());
        $this->assertSame(1, $mine->where('status', Friendship::STATUS_BLOCKED)->count());

        $pending = $mine->where('status', Friendship::STATUS_PENDING);
        $this->assertSame(1, $pending->where('friend_id', $demo->id)->count(), 'incoming request');
        $this->assertSame(1, $pending->where('user_id', $demo->id)->count(), 'outgoing request');

        // Each unordered pair appears once.
        $pairs = Friendship::all()->map(fn (Friendship $f) => min($f->user_id, $f->friend_id).'-'.max($f->user_id, $f->friend_id));
        $this->assertSame($pairs->count(), $pairs->unique()->count());

        $this->assertSame(0, Friendship::where('status', Friendship::STATUS_ACCEPTED)->whereNull('accepted_at')->count());
    }

    public function test_demo_reactions_come_from_server_members(): void
    {
        $this->seed(DemoSeeder::class);

        $nonMemberReactions = DB::table('reactions')
            ->join('messages', 'messages.id', '=', 'reactions.message_id')
            ->join('channels', 'channels.id', '=', 'messages.channel_id')
            ->leftJoin('server_members', function ($join) {
                $join->on('server_members.server_id', '=', 'channels.server_id')
                    ->on('server_members.user_id', '=', 'reactions.user_id');
            })
            ->whereNull('server_members.id')
            ->count();

        $this->assertSame(0, $nonMemberReactions);
    }

    /**
     * The seeder is random; check its invariants hold for several Faker seeds,
     * not just whatever seed the run happens to get.
     *
     * @return array<string, array{int}>
     */
    public static function fakerSeeds(): array
    {
        return ['seed 1' => [1], 'seed 42' => [42], 'seed 1337' => [1337]];
    }

    #[DataProvider('fakerSeeds')]
    public function test_demo_seeder_invariants_hold_for_any_faker_seed(int $seed): void
    {
        fake()->seed($seed);
        mt_srand($seed);

        $this->seed(DemoSeeder::class);

        $this->assertSame(DemoSeeder::USER_COUNT, User::count());
        $this->assertSame(2 * DemoSeeder::MESSAGES_PER_SERVER, Message::whereNotNull('channel_id')->count());
        $this->assertSame(35, Message::whereNotNull('conversation_id')->count());

        $this->assertSame(0, Message::whereNotNull('edited_at')->whereColumn('edited_at', '<', 'created_at')->count());
        $this->assertSame(0, Message::where('edited_at', '>', now())->count());

        // Ids ascend with created_at inside each channel, so ordering by id is chronological.
        Message::whereNotNull('channel_id')->orderBy('id')->get()->groupBy('channel_id')->each(function ($messages) {
            $timestamps = $messages->pluck('created_at')->map->getTimestamp()->all();
            $sorted = $timestamps;
            sort($sorted);
            $this->assertSame($sorted, $timestamps);
        });
    }
}
