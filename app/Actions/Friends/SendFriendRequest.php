<?php

namespace App\Actions\Friends;

use App\Events\FriendshipChanged;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Send a friend request by username (ISSUE-22).
 *
 * - "@name", capitals and surrounding spaces are accepted; usernames are
 *   stored lowercase (CreateNewUser).
 * - If they already sent you a request, sending one back accepts it, like
 *   Discord.
 * - An unknown username and someone who blocked you give the same error,
 *   so a request cannot be used to find out that you were blocked. If you
 *   blocked them, you are told to unblock them first.
 *
 * The recipient gets FriendshipChanged ("request" or "accepted").
 */
class SendFriendRequest
{
    /**
     * @return Friendship pending, or accepted when it answered their request
     *
     * @throws ValidationException
     */
    public function send(User $from, string $username): Friendship
    {
        $username = mb_strtolower(ltrim(trim($username), '@'));

        $to = $username === '' ? null : User::query()->where('username', $username)->first();

        if ($to === null) {
            throw self::fail(self::genericError());
        }

        if ($to->is($from)) {
            throw self::fail(__('You cannot send a friend request to yourself.'));
        }

        try {
            [$friendship, $type] = DB::transaction(fn (): array => $this->sendTo($from, $to));
        } catch (UniqueConstraintViolationException) {
            // Two clicks at once: the other one created the request.
            throw self::fail(__('You already sent :name a friend request.', ['name' => $to->name]));
        }

        $friendship->setRelation('user', $type === FriendshipChanged::REQUEST ? $from : $to);
        $friendship->setRelation('friend', $type === FriendshipChanged::REQUEST ? $to : $from);
        FriendshipChanged::dispatchFor($type, $friendship, $from);

        return $friendship;
    }

    /**
     * @return array{Friendship, string}
     */
    private function sendTo(User $from, User $to): array
    {
        $existing = Friendship::query()->between($from, $to)->lockForUpdate()->first();

        if ($existing === null) {
            $friendship = Friendship::query()->create([
                'user_id' => $from->getKey(),
                'friend_id' => $to->getKey(),
                'status' => Friendship::STATUS_PENDING,
            ]);

            return [$friendship, FriendshipChanged::REQUEST];
        }

        if ($existing->isBlocked()) {
            throw self::fail($existing->sentBy($from)
                ? __('You blocked :name. Unblock them before sending a friend request.', ['name' => $to->name])
                : self::genericError());
        }

        if ($existing->isAccepted()) {
            throw self::fail(__('You are already friends with :name.', ['name' => $to->name]));
        }

        if ($existing->sentBy($from)) {
            throw self::fail(__('You already sent :name a friend request.', ['name' => $to->name]));
        }

        // They asked first: this answers their request.
        $existing->forceFill(['status' => Friendship::STATUS_ACCEPTED, 'accepted_at' => now()])->save();

        return [$existing, FriendshipChanged::ACCEPTED];
    }

    public static function genericError(): string
    {
        return __('That didn\'t work. Double-check the username and try again.');
    }

    private static function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['username' => $message]);
    }
}
