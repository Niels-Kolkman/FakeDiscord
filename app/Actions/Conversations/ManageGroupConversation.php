<?php

namespace App\Actions\Conversations;

use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Group DMs (ISSUE-24): create one, add and remove participants, leave and
 * rename it.
 *
 * Like Discord, a group DM has at most Conversation::MAX_PARTICIPANTS
 * people, its creator included, and you can only bring in your own
 * friends (an accepted friendship, so never across a block). Any
 * participant may add people and rename the group; only the owner removes
 * others (ConversationPolicy). Anyone may leave. When the owner leaves,
 * the longest-standing participant becomes the owner; when the last
 * person leaves, the group is deleted with its messages and their files.
 *
 * Every change sends ConversationUpdated to everyone who was or is in the
 * group, so their sidebar and the open group page follow along.
 *
 * Seats are counted inside a transaction with the conversation row locked,
 * so two people adding at the same moment cannot go past the limit.
 */
class ManageGroupConversation
{
    /**
     * @param  array<int, mixed>  $userIds  the others to invite (the creator is skipped)
     *
     * @throws ValidationException
     */
    public function create(User $owner, array $userIds, ?string $name = null): Conversation
    {
        $ids = self::pickedIds($owner, $userIds);

        if ($ids === []) {
            throw self::fail(__('Pick at least one friend.'));
        }

        if (count($ids) + 1 > Conversation::MAX_PARTICIPANTS) {
            throw self::fail(self::tooMany());
        }

        self::ensureFriends($owner, $ids);

        $conversation = DB::transaction(function () use ($owner, $ids, $name): Conversation {
            $conversation = Conversation::query()->create([
                'type' => Conversation::TYPE_GROUP,
                'name' => self::cleanName($name),
                'owner_id' => $owner->getKey(),
            ]);

            $conversation->users()->attach([$owner->getKey(), ...$ids]);

            return $conversation;
        });

        $this->announce($conversation);

        return $conversation;
    }

    /**
     * @param  array<int, mixed>  $userIds
     * @return int how many people were added (people already in it are skipped)
     *
     * @throws ValidationException
     */
    public function add(User $by, Conversation $conversation, array $userIds): int
    {
        $ids = self::pickedIds($by, $userIds);

        if ($ids === []) {
            throw self::fail(__('Pick at least one friend.'));
        }

        self::ensureFriends($by, $ids);

        $added = DB::transaction(function () use ($conversation, $ids): array {
            Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->first();

            $present = self::participantIds($conversation);
            $new = array_values(array_diff($ids, $present));

            if (count($present) + count($new) > Conversation::MAX_PARTICIPANTS) {
                throw self::fail(self::tooMany());
            }

            $conversation->users()->attach($new);

            return $new;
        });

        if ($added !== []) {
            $this->announce($conversation);
        }

        return count($added);
    }

    /**
     * Take $user out of the group: the owner removing someone, or $user
     * leaving. Returns false when they were not in it.
     */
    public function remove(Conversation $conversation, User $user): bool
    {
        /** @var list<array{disk: string, path: string}> $files */
        $files = [];

        $result = DB::transaction(function () use ($conversation, $user, &$files): ?string {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->first();

            $removed = $conversation->participants()->where('user_id', $user->getKey())->delete();
            if ($locked === null || $removed === 0) {
                return null;
            }

            $next = $conversation->participants()->orderBy('created_at')->orderBy('id')->first();

            if ($next === null) {
                $files = MessageAttachment::filesInConversation((int) $conversation->getKey());
                $conversation->delete();

                return 'deleted';
            }

            if ($locked->owner_id === null || (int) $locked->owner_id === (int) $user->getKey()) {
                $conversation->forceFill(['owner_id' => $next->user_id])->save();
            }

            return 'removed';
        });

        if ($result === null) {
            return false;
        }

        if ($result === 'deleted') {
            MessageAttachment::deleteFiles($files);
        }

        // The person who left hears about it too, so their sidebar drops it.
        $this->announce($conversation, [(int) $user->getKey()]);

        return true;
    }

    public function rename(Conversation $conversation, ?string $name): void
    {
        $conversation->forceFill(['name' => self::cleanName($name)])->save();

        $this->announce($conversation);
    }

    /**
     * Friends of $user who are not in $conversation yet, by name, for the
     * friend pickers (all friends when $conversation is NULL).
     *
     * @return Collection<int, User>
     */
    public static function invitableFriends(User $user, ?Conversation $conversation = null): Collection
    {
        $ids = self::friendIds($user);

        if ($conversation !== null) {
            $ids = array_diff($ids, self::participantIds($conversation));
        }

        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->whereKey(array_values($ids))
            ->orderBy('name')
            ->orderBy('username')
            ->get()
            ->toBase();
    }

    /**
     * Ids of the people $user has an accepted friendship with.
     *
     * @return list<int>
     */
    public static function friendIds(User $user): array
    {
        $id = (int) $user->getKey();

        return Friendship::query()
            ->involving($user)
            ->where('status', Friendship::STATUS_ACCEPTED)
            ->get(['user_id', 'friend_id'])
            ->map(fn (Friendship $friendship): int => (int) $friendship->user_id === $id ? (int) $friendship->friend_id : (int) $friendship->user_id)
            ->unique()
            ->values()
            ->all();
    }

    public static function tooMany(): string
    {
        return __('A group DM can have at most :max people.', ['max' => Conversation::MAX_PARTICIPANTS]);
    }

    /**
     * Trimmed, and NULL when empty: an unnamed group is called after its
     * people (Conversation::displayName()).
     */
    public static function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : $name;
    }

    /**
     * @return list<int>
     */
    private static function participantIds(Conversation $conversation): array
    {
        return $conversation->participants()->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * The picked ids as distinct integers, without $user themselves.
     *
     * @param  array<array-key, mixed>  $userIds
     * @return list<int>
     */
    public static function pickedIds(User $user, array $userIds): array
    {
        return collect($userIds)
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === (int) $user->getKey())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Unknown users get the same message as people who are not your friends.
     *
     * @param  list<int>  $ids
     */
    private static function ensureFriends(User $user, array $ids): void
    {
        if (array_diff($ids, self::friendIds($user)) !== []) {
            throw self::fail(__('You can only add your friends to a group DM.'));
        }
    }

    /**
     * @param  list<int>  $alsoNotify  former participants
     */
    private function announce(Conversation $conversation, array $alsoNotify = []): void
    {
        rescue(function () use ($conversation, $alsoNotify): void {
            broadcast(new ConversationUpdated($conversation, $alsoNotify));
        });
    }

    private static function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['user_ids' => $message]);
    }
}
