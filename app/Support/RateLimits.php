<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * Named rate limiters, used as `throttle:<name>` in
 * routes/web.php and by Fortify (config/fortify.php `limiters.login`).
 *
 * Why named: an inline `throttle:30,1` counts in one bucket per user that
 * every other inline throttle shares (ThrottleRequests keys it by the user
 * id alone). Typing "@" in the composer (mention autocomplete, 120/min) or
 * read marks (120/min) used to eat the 30/min message allowance. A named
 * limiter counts under its own name, so each one below is independent.
 *
 * Every limit is per user, with a per-IP limit on top where one person
 * could spread the work over several accounts (logins, invite codes).
 */
final class RateLimits
{
    /** Fortify's POST /login. */
    public const LOGIN = 'login';

    /** Sending a message, in a server channel or a DM (one budget for both). */
    public const MESSAGES = 'messages';

    /** Creating invites. */
    public const INVITES = 'invites';

    /** Opening and accepting /invite/{code}: guessing codes. */
    public const INVITE_CODES = 'invite-codes';

    /** Login attempts per email address and IP. */
    public const LOGIN_PER_EMAIL = 5;

    /** Login attempts per IP, whatever the email (password spraying). */
    public const LOGIN_PER_IP = 30;

    public const MESSAGES_PER_MINUTE = 30;

    public const INVITES_PER_MINUTE = 20;

    public const INVITE_CODES_PER_MINUTE = 30;

    /** Several accounts behind one IP guessing invite codes. */
    public const INVITE_CODES_PER_IP = 60;

    public static function register(): void
    {
        RateLimiter::for(self::LOGIN, function (Request $request): array {
            $email = Str::transliterate(Str::lower($request->string(Fortify::username())->value()));

            return [
                Limit::perMinute(self::LOGIN_PER_EMAIL)->by($email.'|'.$request->ip()),
                Limit::perMinute(self::LOGIN_PER_IP)->by('ip|'.$request->ip()),
            ];
        });

        RateLimiter::for(self::MESSAGES, fn (Request $request): Limit => Limit::perMinute(self::MESSAGES_PER_MINUTE)
            ->by(self::userKey($request)));

        RateLimiter::for(self::INVITES, fn (Request $request): Limit => Limit::perMinute(self::INVITES_PER_MINUTE)
            ->by(self::userKey($request)));

        RateLimiter::for(self::INVITE_CODES, fn (Request $request): array => [
            Limit::perMinute(self::INVITE_CODES_PER_MINUTE)->by(self::userKey($request)),
            Limit::perMinute(self::INVITE_CODES_PER_IP)->by('ip|'.$request->ip()),
        ]);
    }

    /**
     * The signed-in user, or the IP for a guest (these routes need a login,
     * so that is only a fallback).
     */
    private static function userKey(Request $request): string
    {
        $user = $request->user();

        return $user !== null ? 'user|'.$user->getAuthIdentifier() : 'ip|'.$request->ip();
    }
}
