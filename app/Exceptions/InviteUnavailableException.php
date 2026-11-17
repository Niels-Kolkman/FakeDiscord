<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An invite cannot be used by this user right now.
 */
class InviteUnavailableException extends RuntimeException
{
    public const EXPIRED = 'expired';

    public const FULL = 'full';

    public const BANNED = 'banned';

    private const MESSAGES = [
        self::EXPIRED => 'This invite has expired.',
        self::FULL => 'This invite has reached its maximum number of uses.',
        self::BANNED => 'You are banned from this server.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'This invite is not valid.');
    }

    /**
     * Banned users are refused (403). Expired and full invites are gone (410).
     */
    public function status(): int
    {
        return $this->reason === self::BANNED ? 403 : 410;
    }
}
