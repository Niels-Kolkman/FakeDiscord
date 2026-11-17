<?php

namespace App\Actions\Servers;

use App\Models\Invite;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generates an invite with a random, unguessable code.
 */
class CreateInvite
{
    public const CODE_LENGTH = 8;

    private const MAX_ATTEMPTS = 5;

    /**
     * @param  int|null  $expiresInMinutes  NULL = never expires
     * @param  int|null  $maxUses  NULL = unlimited
     */
    public function create(Server $server, User $inviter, ?int $expiresInMinutes, ?int $maxUses): Invite
    {
        // 62^8 codes make collisions very unlikely. Retry on the unique index
        // instead of checking first, so two requests cannot pick the same code.
        for ($attempt = 1; ; $attempt++) {
            try {
                return $server->invites()->create([
                    'code' => Str::random(self::CODE_LENGTH),
                    'inviter_id' => $inviter->getKey(),
                    'max_uses' => $maxUses,
                    'uses' => 0,
                    'expires_at' => $expiresInMinutes !== null ? now()->addMinutes($expiresInMinutes) : null,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException('Could not generate a unique invite code.', previous: $e);
                }
            }
        }
    }
}
