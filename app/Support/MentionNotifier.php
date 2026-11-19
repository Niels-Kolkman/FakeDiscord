<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Notifications\MentionedInMessage;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Sends the mention notifications of a server channel message (ISSUE-33)
 * and keeps them in step with it.
 *
 * Who gets one (recipients()): everyone the message mentions
 * (messages.mentions, App\Support\Mentions) by name, through a role they
 * hold, or with @everyone, who
 *
 * - is still a member of the server and can see the channel;
 * - is not the author (mentioning yourself does nothing);
 * - has not blocked the author (ISSUE-25), who stays hidden from them.
 *
 * One notification per person per message, with the most direct reason:
 * by name, else by role, else @everyone.
 *
 * sync() runs after a message is sent or edited: new recipients get a
 * notification, people no longer mentioned lose theirs (editing a mention
 * out takes the "ghost ping" back), the others keep the one they have.
 * Deleting the message (forgetMessage()) or leaving the server
 * (forgetServer()) removes them too.
 *
 * Each notification is stored first and then broadcast with the same id
 * (so the inbox can mark that row read); a failed broadcast is reported
 * and the rest carry on, like the message events.
 *
 * People who muted the channel or its server (ISSUE-34,
 * NotificationMutes) still get the notification, with `push: false` in
 * the broadcast: no browser notification pops up for it.
 */
class MentionNotifier
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function sync(Message $message): void
    {
        $recipients = $this->recipients($message);

        $existing = self::forMessage((int) $message->id)
            ->pluck('notifiable_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $gone = array_values(array_diff($existing, array_keys($recipients)));
        if ($gone !== []) {
            self::forMessage((int) $message->id)->whereIn('notifiable_id', $gone)->delete();
        }

        $new = array_diff_key($recipients, array_flip($existing));
        if ($new === []) {
            return;
        }

        // Mute settings (ISSUE-34): stored and counted, but no browser notification.
        $muted = array_flip(NotificationMutes::mutedUserIds($message->channel, array_keys($new)));

        foreach (User::query()->whereKey(array_keys($new))->orderBy('id')->get() as $user) {
            [$reason, $roleName] = $new[(int) $user->id];
            $notification = new MentionedInMessage($message, $reason, $roleName, push: ! isset($muted[(int) $user->id]));
            $notification->id = (string) Str::uuid();

            Notification::sendNow($user, $notification, ['database']);

            // Stored either way; if Reverb is down, report it and carry on.
            rescue(fn () => Notification::sendNow($user, $notification, ['broadcast']));
        }
    }

    /**
     * Who should be notified: user id => [reason, role name], by user id.
     *
     * @return array<int, array{string, string|null}>
     */
    public function recipients(Message $message): array
    {
        $mentions = $message->mentions;

        if (! is_array($mentions) || $message->channel_id === null || $message->trashed() || $message->channel === null) {
            return [];
        }

        $channel = $message->channel;
        $server = $channel->server;
        /** @var array<int, array{string, string|null}> $recipients */
        $recipients = [];

        // Least direct first, so a more direct reason overwrites it.
        if (($mentions['everyone'] ?? false) === true) {
            foreach ($server->memberships()->pluck('user_id') as $userId) {
                $recipients[(int) $userId] = [MentionedInMessage::REASON_EVERYONE, null];
            }
        }

        $roleIds = array_map('intval', (array) ($mentions['roles'] ?? []));
        if ($roleIds !== []) {
            $roles = Role::query()->whereKey($roleIds)->where('server_id', $server->id)->pluck('name', 'id');
            $holders = DB::table('role_user')->whereIn('role_id', $roles->keys())->orderBy('role_id')->get(['role_id', 'user_id']);
            foreach ($holders as $holder) {
                $recipients[(int) $holder->user_id] = [MentionedInMessage::REASON_ROLE, (string) $roles[$holder->role_id]];
            }
        }

        foreach (array_map('intval', (array) ($mentions['users'] ?? [])) as $userId) {
            $recipients[$userId] = [MentionedInMessage::REASON_USER, null];
        }

        unset($recipients[(int) $message->user_id]);

        if ($recipients === []) {
            return [];
        }

        $ids = array_keys($recipients);
        $members = $server->memberships()->whereIn('user_id', $ids)->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all();
        $blockers = $message->user_id === null ? [] : Friendship::query()
            ->where('friend_id', $message->user_id)
            ->where('status', Friendship::STATUS_BLOCKED)
            ->whereIn('user_id', $ids)
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $visible = [];
        foreach (User::query()->whereKey(array_values(array_diff($members, $blockers)))->orderBy('id')->get() as $user) {
            if ($this->permissions->hasInChannel($user, $channel, Permission::ViewChannels)) {
                $visible[(int) $user->id] = $recipients[(int) $user->id];
            }
        }

        return $visible;
    }

    /** A deleted message's notifications go with it. */
    public static function forgetMessage(Message $message): void
    {
        self::forMessage((int) $message->id)->delete();
    }

    /** Someone who leaves (or is kicked or banned) loses the server's. */
    public static function forgetServer(User $user, Server $server): void
    {
        $user->notifications()
            ->where('type', MentionedInMessage::class)
            ->where('data->server_id', (int) $server->id)
            ->delete();
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private static function forMessage(int $messageId): Builder
    {
        return DatabaseNotification::query()
            ->where('type', MentionedInMessage::class)
            ->where('notifiable_type', (new User)->getMorphClass())
            ->where('data->message_id', $messageId);
    }
}
