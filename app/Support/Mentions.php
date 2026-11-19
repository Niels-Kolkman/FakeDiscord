<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Message;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Services\PermissionService;

/**
 * Who a message mentions (ISSUE-32 for @username, ISSUE-33 for @role and
 * @everyone).
 *
 * A mention is "@" followed by a username (usernames are [a-z0-9_.], see
 * CreateNewUser) or a role name, case-insensitive, not inside a word or an
 * e-mail address ("me@bob" is not one) and not followed by more username
 * characters ("@bobby" does not mention bob). A trailing period ends the
 * sentence, not the name: "thanks @bob." mentions bob. Role names may hold
 * spaces ("@Game Night"); the same boundaries apply around the whole name.
 *
 * "@everyone" mentions every member who can see the channel, but only
 * when the author has Mention @everyone there (Permission::MentionEveryone,
 * like Discord); otherwise it is plain text. "everyone" and "here" cannot
 * be usernames. Any member may mention any role.
 *
 * Message saves this as messages.mentions (resolve(), server channels only)
 * when its content is written, so the badges, the notifications
 * (App\Support\MentionNotifier) and the browser (mentionsViewer() in
 * resources/js/unread.js) all agree on who was mentioned, and later
 * renames or permission changes do not rewrite history. Only members of
 * the server are stored as mentioned users.
 *
 * @phpstan-type MentionSet array{users: list<int>, roles: list<int>, everyone: bool}
 */
final class Mentions
{
    public const EVERYONE = 'everyone';

    /** Never usernames: "@everyone" (and Discord's "@here") mean everyone. */
    public const RESERVED_USERNAMES = ['everyone', 'here'];

    private const BEFORE = '(?<![a-z0-9_.@])@';

    private const AFTER = '(?![a-z0-9_]|\.[a-z0-9_])';

    /** The pattern of one mention of $name (a username or a role name). */
    public static function pattern(string $name): string
    {
        return '/'.self::BEFORE.preg_quote(mb_strtolower($name), '/').self::AFTER.'/iu';
    }

    public static function mentionsUser(string $content, User $user): bool
    {
        $username = (string) $user->username;

        return $username !== '' && preg_match(self::pattern($username), $content) === 1;
    }

    public static function mentionsEveryone(string $content): bool
    {
        return preg_match(self::pattern(self::EVERYONE), $content) === 1;
    }

    /**
     * Every "@name" in $content that could be a username, lower case, once
     * each and without "everyone": "@bob.smith." gives "bob.smith".
     *
     * @return list<string>
     */
    public static function usernames(string $content): array
    {
        preg_match_all('/'.self::BEFORE.'([a-z0-9_]+(?:\.[a-z0-9_]+)*)'.self::AFTER.'/iu', $content, $matches);

        $names = array_unique(array_map(mb_strtolower(...), $matches[1]));

        return array_values(array_diff($names, self::RESERVED_USERNAMES));
    }

    /**
     * Who a server channel message mentions, for messages.mentions: NULL
     * when nobody (and for DMs). Users must be members of the server;
     * roles are the server's own, without @everyone.
     *
     * @return MentionSet|null
     */
    public static function resolve(Message $message): ?array
    {
        $content = (string) $message->content;

        // No query at all for a message without "@" or outside a channel.
        if ($message->channel_id === null || ! str_contains($content, '@')) {
            return null;
        }

        $channel = $message->channel;
        if ($channel === null) {
            return null;
        }

        $usernames = self::usernames($content);
        $users = $usernames === [] ? [] : User::query()
            ->whereIn('username', $usernames)
            ->whereHas('servers', fn ($servers) => $servers->whereKey($channel->server_id))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $roles = Role::query()
            ->where('server_id', $channel->server_id)
            ->where('is_default', false)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->filter(fn (Role $role): bool => trim((string) $role->name) !== ''
                && preg_match(self::pattern((string) $role->name), $content) === 1)
            ->map(fn (Role $role): int => (int) $role->id)
            ->values()
            ->all();

        $author = $message->user_id === null ? null : $message->author;
        $everyone = $author !== null
            && self::mentionsEveryone($content)
            && app(PermissionService::class)->hasInChannel($author, $channel, Permission::MentionEveryone);

        if ($users === [] && $roles === [] && ! $everyone) {
            return null;
        }

        return ['users' => $users, 'roles' => $roles, 'everyone' => $everyone];
    }

    /**
     * The ids of the roles (not @everyone) the user holds in the server:
     * what includes() needs to tell whether a role mention is for them.
     *
     * @return list<int>
     */
    public static function roleIdsOf(User $user, Server $server): array
    {
        return $user->roles()
            ->where('roles.server_id', $server->id)
            ->orderBy('roles.id')
            ->pluck('roles.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Whether a stored mention set (messages.mentions) mentions the user,
     * given the ids of the roles they hold.
     *
     * @param  array<string, mixed>|null  $mentions
     * @param  list<int>  $roleIds
     */
    public static function includes(?array $mentions, int $userId, array $roleIds = []): bool
    {
        if ($mentions === null) {
            return false;
        }

        if (($mentions['everyone'] ?? false) === true) {
            return true;
        }

        $users = array_map('intval', (array) ($mentions['users'] ?? []));
        if (in_array($userId, $users, true)) {
            return true;
        }

        $roles = array_map('intval', (array) ($mentions['roles'] ?? []));

        return $roleIds !== [] && array_intersect($roles, $roleIds) !== [];
    }
}
