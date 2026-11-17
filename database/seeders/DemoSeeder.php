<?php

namespace Database\Seeders;

use App\Models\Ban;
use App\Models\Channel;
use App\Models\ChannelCategory;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Invite;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Reaction;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Database\Factories\ReactionFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for manual testing: 10 users, 2 servers with roles, channels and
 * invites, ~300 channel messages (replies, edits, reactions, attachments),
 * a 1:1 DM, a group DM, friendships and a ban.
 *
 * Log in as test@example.com / "password" (every demo user has that password).
 * Run on a fresh database: `php artisan migrate:fresh --seed`.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public const USER_COUNT = 10;

    public const MESSAGES_PER_SERVER = 150;

    public function run(): void
    {
        DB::transaction(function () {
            $users = $this->seedUsers();
            $demo = $users->first();

            $lounge = $this->seedServer(
                owner: $demo,
                name: 'Laravel Lounge',
                description: 'Chat about Laravel, PHP and everything in between.',
                members: $users,
                channels: [
                    ['welcome', Channel::TYPE_ANNOUNCEMENT, 'Read this first'],
                    ['general', Channel::TYPE_TEXT, 'Anything goes'],
                    ['help', Channel::TYPE_TEXT, 'Ask questions, get answers'],
                    ['random', Channel::TYPE_TEXT, null],
                    ['Voice Lounge', Channel::TYPE_VOICE, null],
                ],
                inviteCode: 'lounge',
            );

            $pixel = $this->seedServer(
                owner: $users[1],
                name: 'Pixel Pals',
                description: 'Games, art and screenshots.',
                members: $users->take(7),
                channels: [
                    ['general', Channel::TYPE_TEXT, null],
                    ['screenshots', Channel::TYPE_TEXT, 'Show off your setup'],
                    ['off-topic', Channel::TYPE_TEXT, null],
                    ['Gaming', Channel::TYPE_VOICE, null],
                ],
                inviteCode: 'pixelpals',
            );

            // Edge cases for invite/ban flows.
            Invite::factory()->expired()->create([
                'server_id' => $lounge->id,
                'inviter_id' => $demo->id,
                'code' => 'expired',
            ]);
            Ban::factory()->create([
                'server_id' => $pixel->id,
                'user_id' => $users->last()->id,
                'banned_by_id' => $pixel->owner_id,
                'reason' => 'Spamming invite links',
            ]);

            $this->seedChannelMessages($lounge, self::MESSAGES_PER_SERVER);
            $this->seedChannelMessages($pixel, self::MESSAGES_PER_SERVER);

            $this->seedConversations($users);
            $this->seedFriendships($users);
        });

        $this->command?->info('Demo data seeded. Log in as test@example.com / password.');
    }

    /**
     * @return Collection<int, User>
     */
    private function seedUsers(): Collection
    {
        $statuses = ['online', 'idle', 'dnd', 'offline'];

        $demo = User::factory()->create([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'status' => 'online',
        ]);

        $others = User::factory(self::USER_COUNT - 1)
            ->sequence(fn () => ['status' => fake()->randomElement($statuses)])
            ->create();

        return collect([$demo])->concat($others)->values();
    }

    /**
     * @param  Collection<int, User>  $members  includes the owner
     * @param  list<array{0: string, 1: string, 2: ?string}>  $channels  [name, type, topic]
     */
    private function seedServer(
        User $owner,
        string $name,
        string $description,
        Collection $members,
        array $channels,
        string $inviteCode,
    ): Server {
        $server = Server::factory()->create([
            'owner_id' => $owner->id,
            'name' => $name,
            'description' => $description,
            'created_at' => now()->subDays(60),
        ]);

        // Permission flags are defined in ISSUE-26; until then roles carry no bits.
        Role::factory()->everyone()->create(['server_id' => $server->id]);
        $moderator = Role::factory()->create([
            'server_id' => $server->id,
            'name' => 'Moderator',
            'color' => '#e67e22',
            'position' => 1,
        ]);

        foreach ($members as $member) {
            ServerMember::factory()->create([
                'server_id' => $server->id,
                'user_id' => $member->id,
                'joined_at' => $member->is($owner)
                    ? $server->created_at
                    : fake()->dateTimeBetween('-59 days', '-15 days'),
            ]);
        }

        // One non-owner moderator per server.
        $moderator->users()->attach($members->first(fn (User $u) => ! $u->is($owner)));

        // Like a fresh Discord server: announcements on top, then a "Text
        // Channels" and a "Voice Channels" category (ISSUE-12).
        $categories = [
            Channel::TYPE_TEXT => ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => 'Text Channels', 'position' => 0]),
            Channel::TYPE_VOICE => ChannelCategory::factory()->create(['server_id' => $server->id, 'name' => 'Voice Channels', 'position' => 1]),
        ];
        $positions = [];

        foreach ($channels as [$channelName, $type, $topic]) {
            $categoryId = isset($categories[$type]) ? $categories[$type]->id : null;
            $positions[$categoryId ?? 0] ??= 0;

            Channel::factory()->create([
                'server_id' => $server->id,
                'category_id' => $categoryId,
                'name' => $channelName,
                'type' => $type,
                'topic' => $topic,
                'position' => $positions[$categoryId ?? 0]++,
            ]);
        }

        Invite::factory()->create([
            'server_id' => $server->id,
            'channel_id' => $server->channels()->where('name', 'general')->value('id'),
            'inviter_id' => $owner->id,
            'code' => $inviteCode,
            'uses' => $members->count() - 1,
        ]);

        return $server;
    }

    /**
     * Spread $count messages over the server's text channels during the last
     * two weeks, in chronological order (ids ascend with created_at).
     */
    private function seedChannelMessages(Server $server, int $count): void
    {
        $channels = $server->channels()->where('type', '!=', Channel::TYPE_VOICE)->get();
        $memberIds = $server->memberships()->pluck('user_id')->all();

        /** @var array<int, list<Message>> $recentByChannel */
        $recentByChannel = [];

        foreach ($this->sortedTimestamps($count, days: 14) as $createdAt) {
            $channel = $channels->random();
            // Only the owner posts in announcement channels.
            $authorId = $channel->type === Channel::TYPE_ANNOUNCEMENT
                ? $server->owner_id
                : fake()->randomElement($memberIds);

            $recent = $recentByChannel[$channel->id] ?? [];
            $replyTo = $recent !== [] && fake()->boolean(12) ? fake()->randomElement($recent) : null;

            $message = Message::factory()->inChannel($channel)->create([
                'user_id' => $authorId,
                'reply_to_id' => $replyTo?->id,
                'edited_at' => fake()->boolean(8) ? $createdAt->copy()->addMinutes(fake()->numberBetween(1, 30))->min(now()) : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $recentByChannel[$channel->id] = array_slice([...$recent, $message], -10);

            if (fake()->boolean(25)) {
                $this->seedReactions($message, $memberIds);
            }

            if (fake()->boolean(5)) {
                MessageAttachment::factory()->create(['message_id' => $message->id]);
            }
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    private function seedReactions(Message $message, array $userIds): void
    {
        $emojis = fake()->randomElements(ReactionFactory::EMOJI, fake()->numberBetween(1, 3));

        foreach ($emojis as $emoji) {
            // Distinct users per emoji, matching the unique (message, emoji, user) index.
            foreach (fake()->randomElements($userIds, fake()->numberBetween(1, min(4, count($userIds)))) as $userId) {
                Reaction::factory()->create([
                    'message_id' => $message->id,
                    'user_id' => $userId,
                    'emoji' => $emoji,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function seedConversations(Collection $users): void
    {
        $demo = $users->first();

        $direct = Conversation::factory()->create();
        $direct->users()->attach([$demo->id, $users[1]->id]);
        $this->seedConversationMessages($direct, [$demo->id, $users[1]->id], 20);

        $group = Conversation::factory()->group()->create([
            'name' => 'Weekend Plans',
            'owner_id' => $demo->id,
        ]);
        $groupIds = [$demo->id, $users[2]->id, $users[3]->id];
        $group->users()->attach($groupIds);
        $this->seedConversationMessages($group, $groupIds, 15);
    }

    /**
     * @param  list<int>  $participantIds
     */
    private function seedConversationMessages(Conversation $conversation, array $participantIds, int $count): void
    {
        foreach ($this->sortedTimestamps($count, days: 7) as $createdAt) {
            Message::factory()->inConversation($conversation)->create([
                'user_id' => fake()->randomElement($participantIds),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function seedFriendships(Collection $users): void
    {
        $demo = $users->first();

        foreach ([1, 2, 3] as $i) {
            Friendship::factory()->accepted()->create(['user_id' => $demo->id, 'friend_id' => $users[$i]->id]);
        }

        // Incoming and outgoing pending requests, and a block, for the friends UI.
        Friendship::factory()->create(['user_id' => $users[4]->id, 'friend_id' => $demo->id]);
        Friendship::factory()->create(['user_id' => $demo->id, 'friend_id' => $users[5]->id]);
        Friendship::factory()->blocked()->create(['user_id' => $demo->id, 'friend_id' => $users->last()->id]);

        Friendship::factory()->accepted()->create(['user_id' => $users[5]->id, 'friend_id' => $users[6]->id]);
    }

    /**
     * @return list<Carbon>
     */
    private function sortedTimestamps(int $count, int $days): array
    {
        $timestamps = [];

        for ($i = 0; $i < $count; $i++) {
            $timestamps[] = Carbon::instance(fake()->dateTimeBetween("-{$days} days", '-1 minute'));
        }

        usort($timestamps, fn (Carbon $a, Carbon $b) => $a <=> $b);

        return $timestamps;
    }
}
