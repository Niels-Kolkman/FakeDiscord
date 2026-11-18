<?php

namespace App\Enums;

/**
 * Server permission flags (ISSUE-26), stored OR-ed together in
 * roles.permissions. The values are Discord's bits, so the masks read the
 * same as Discord's; every flag stays within bits 0-62 (SQLite stores the
 * column as a signed 64-bit INTEGER).
 *
 * Storing flags is all this does: which flags a member effectively has, and
 * what each one allows, is resolved by ISSUE-27.
 */
enum Permission: int
{
    case CreateInvite = 1 << 0;
    case KickMembers = 1 << 1;
    case BanMembers = 1 << 2;
    case Administrator = 1 << 3;
    case ManageChannels = 1 << 4;
    case ManageServer = 1 << 5;
    case AddReactions = 1 << 6;
    case ViewChannels = 1 << 10;
    case SendMessages = 1 << 11;
    case ManageMessages = 1 << 13;
    case AttachFiles = 1 << 15;
    case ManageRoles = 1 << 28;
    case TimeoutMembers = 1 << 40;

    public function label(): string
    {
        return match ($this) {
            self::CreateInvite => 'Create Invite',
            self::KickMembers => 'Kick Members',
            self::BanMembers => 'Ban Members',
            self::Administrator => 'Administrator',
            self::ManageChannels => 'Manage Channels',
            self::ManageServer => 'Manage Server',
            self::AddReactions => 'Add Reactions',
            self::ViewChannels => 'View Channels',
            self::SendMessages => 'Send Messages',
            self::ManageMessages => 'Manage Messages',
            self::AttachFiles => 'Attach Files',
            self::ManageRoles => 'Manage Roles',
            self::TimeoutMembers => 'Timeout Members',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CreateInvite => 'Invite new people to this server.',
            self::KickMembers => 'Remove members from this server. They can rejoin with an invite.',
            self::BanMembers => 'Remove members and stop them from rejoining.',
            self::Administrator => 'Every permission, everywhere. Give this with care.',
            self::ManageChannels => 'Create, edit, reorder and delete channels and categories.',
            self::ManageServer => 'Rename the server and change its icon.',
            self::AddReactions => 'Add new reactions to messages.',
            self::ViewChannels => 'See the server\'s channels and read their messages.',
            self::SendMessages => 'Post messages in text channels.',
            self::ManageMessages => 'Delete and pin other members\' messages.',
            self::AttachFiles => 'Upload files and images with messages.',
            self::ManageRoles => 'Create and edit roles below their own highest role.',
            self::TimeoutMembers => 'Stop members from chatting or reacting for a while.',
        };
    }

    /**
     * What the @everyone role of a new server gets: what every member could
     * already do before roles existed.
     */
    public static function everyoneDefaults(): int
    {
        return self::mask(
            self::CreateInvite,
            self::AddReactions,
            self::ViewChannels,
            self::SendMessages,
            self::AttachFiles,
        );
    }

    /**
     * Every known flag OR-ed together.
     */
    public static function all(): int
    {
        return self::mask(...self::cases());
    }

    public static function mask(self ...$permissions): int
    {
        return array_reduce($permissions, fn (int $mask, self $permission): int => $mask | $permission->value, 0);
    }

    /**
     * The known flags set in $mask, in declaration order. Unknown bits are
     * ignored.
     *
     * @return list<self>
     */
    public static function fromMask(int $mask): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $permission): bool => $permission->in($mask),
        ));
    }

    public function in(int $mask): bool
    {
        return ($mask & $this->value) === $this->value;
    }
}
