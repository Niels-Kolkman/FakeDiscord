<?php

namespace App\Support;

use App\Models\Message;
use App\Models\Reaction;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Emoji reactions on messages: which emoji are accepted, how a
 * message's reactions are grouped for display, and the tooltip text.
 *
 * A group is one emoji with its count and the first few people who used
 * it (for the tooltip "Alice, Bob and 3 others reacted with 👍"). Groups
 * are ordered by their first reaction, the people by when they reacted,
 * like Discord. `me` tells the viewer whether they are one of them; it is
 * left out of broadcasts, which go to everyone (the browser keeps its own).
 *
 * resources/js/channel-messages.js builds the same tooltip (reactionTooltip()).
 *
 * @phpstan-type ReactionGroup array{emoji: string, count: int, users: list<array{id: int, name: string}>, me?: bool}
 */
final class Reactions
{
    /** Discord's limit of different emoji on one message. */
    public const MAX_PER_MESSAGE = 20;

    /** How many names the tooltip shows before "and N others". */
    public const NAMES_SHOWN = 3;

    /** The emoji offered by the "Add reaction" picker. */
    public const QUICK = ['👍', '❤️', '😂', '🎉', '😮', '😢', '🔥', '👀'];

    /**
     * Relations to eager load so groups() runs no queries.
     *
     * @return array<int|string, string|\Closure(Builder): mixed>
     */
    public static function relations(): array
    {
        return [
            'reactions' => fn (Builder $query) => $query->orderBy('id'),
            'reactions.user:id,name',
        ];
    }

    /**
     * A single unicode emoji: one grapheme made only of pictographs,
     * regional indicators (flags), keycaps and their modifiers. Plain text,
     * markup and custom ":name:" emoji (not supported yet) are refused.
     */
    public static function isValidEmoji(mixed $emoji): bool
    {
        if (! is_string($emoji) || $emoji === '' || strlen($emoji) > 64) {
            return false;
        }

        $pictographic = '\p{Extended_Pictographic}|\p{Regional_Indicator}|[#*0-9]\x{FE0F}?\x{20E3}';
        $modifier = '[\x{FE0F}\x{200D}\x{1F3FB}-\x{1F3FF}\x{E0020}-\x{E007F}]';

        if (preg_match("/^(?=.*(?:{$pictographic}))(?:{$pictographic}|{$modifier})+$/u", $emoji) !== 1) {
            return false;
        }

        // One emoji, not several. PCRE's \X is the fallback without intl.
        return function_exists('grapheme_strlen')
            ? grapheme_strlen($emoji) === 1
            : preg_match('/^\X$/u', $emoji) === 1;
    }

    /**
     * The grouped reactions of a message. Pass the viewer's id to get `me`.
     *
     * @return list<ReactionGroup>
     */
    public static function groups(Message $message, ?int $viewerId = null): array
    {
        /** @var array<string, ReactionGroup> $groups */
        $groups = [];

        /** @var Reaction $reaction */
        foreach ($message->reactions->sortBy('id') as $reaction) {
            $emoji = (string) $reaction->emoji;
            $group = $groups[$emoji] ?? ['emoji' => $emoji, 'count' => 0, 'users' => []];
            if ($viewerId !== null) {
                $group['me'] ??= false;
            }

            $group['count']++;
            if ($reaction->user !== null && count($group['users']) < self::NAMES_SHOWN) {
                $group['users'][] = ['id' => (int) $reaction->user->id, 'name' => $reaction->user->name];
            }
            if ($viewerId !== null && (int) $reaction->user_id === $viewerId) {
                $group['me'] = true;
            }

            $groups[$emoji] = $group;
        }

        return array_values($groups);
    }

    /**
     * "Alice, Bob and 3 others reacted with 👍".
     *
     * @param  ReactionGroup  $group
     */
    public static function tooltip(array $group): string
    {
        $names = array_column($group['users'], 'name');
        $others = $group['count'] - count($names);

        if ($others > 0) {
            $names[] = $others === 1 ? '1 other' : "{$others} others";
        }
        if ($names === []) {
            return "Reacted with {$group['emoji']}";
        }

        $last = array_pop($names);
        $who = $names === [] ? $last : implode(', ', $names).' and '.$last;

        return "{$who} reacted with {$group['emoji']}";
    }

    /**
     * The accessible name of a reaction button: "👍, 3 reactions".
     *
     * @param  ReactionGroup  $group
     */
    public static function label(array $group): string
    {
        return $group['emoji'].', '.$group['count'].' '.($group['count'] === 1 ? 'reaction' : 'reactions');
    }
}
