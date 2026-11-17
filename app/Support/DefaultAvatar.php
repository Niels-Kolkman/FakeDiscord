<?php

namespace App\Support;

use App\Actions\Profile\UpdateUserAvatar;
use App\Models\User;

/**
 * Generated avatar for users who have not uploaded one: their initials on a
 * coloured square. The colour is derived from the username, so it stays the
 * same between requests without storing anything.
 */
class DefaultAvatar
{
    /** Discord-style background colours. */
    public const COLORS = ['#5865f2', '#757e8a', '#3ba55c', '#faa61a', '#ed4245', '#eb459e'];

    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_substr($word, 0, 1);
        }

        return $initials === '' ? '?' : mb_strtoupper($initials);
    }

    public static function color(string $seed): string
    {
        return self::COLORS[crc32($seed) % count(self::COLORS)];
    }

    public static function svg(User $user): string
    {
        $initials = e(self::initials($user->name));
        $color = self::color($user->username);
        $size = UpdateUserAvatar::SIZE;

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$size.' '.$size.'">'
            .'<rect width="100%" height="100%" fill="'.$color.'"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#ffffff" font-family="Arial, Helvetica, sans-serif" font-size="104" font-weight="600">'.$initials.'</text>'
            .'</svg>';
    }

    public static function dataUri(User $user): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($user));
    }
}
