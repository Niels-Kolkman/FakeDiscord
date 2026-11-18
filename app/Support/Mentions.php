<?php

namespace App\Support;

use App\Models\User;

/**
 * Whether a message mentions someone, for the mention badges (ISSUE-32).
 *
 * For now a mention is "@username" (usernames are [a-z0-9_.], see
 * CreateNewUser), case-insensitive, not inside a word or an e-mail address
 * ("me@bob" is not one) and not followed by more username characters
 * ("@bobby" does not mention bob). A trailing period ends the sentence,
 * not the name: "thanks @bob." mentions bob. @role and @everyone come with
 * ISSUE-33. mentionsUser() in resources/js/unread.js must match this.
 */
final class Mentions
{
    public static function pattern(string $username): string
    {
        return '/(?<![a-z0-9_.@])@'.preg_quote(mb_strtolower($username), '/').'(?![a-z0-9_]|\.[a-z0-9_])/iu';
    }

    public static function mentionsUser(string $content, User $user): bool
    {
        $username = (string) $user->username;

        return $username !== '' && preg_match(self::pattern($username), $content) === 1;
    }

    /**
     * A LIKE pattern (with ESCAPE '\') that every message mentioning
     * $username matches, to narrow down the rows before pattern() runs.
     */
    public static function likePattern(string $username): string
    {
        return '%@'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($username)).'%';
    }
}
