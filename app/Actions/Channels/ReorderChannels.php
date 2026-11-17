<?php

namespace App\Actions\Channels;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the whole sidebar order after a drag and drop.
 *
 * The client sends the complete layout, not a single move. It must name
 * every channel and category of the server exactly once. If another tab or
 * user added or deleted one in the meantime, the request is rejected and
 * the client reloads, so a stale sidebar never overwrites a newer one.
 */
class ReorderChannels
{
    public const STALE_MESSAGE = 'The channel list changed in the meantime. Reload the page and try again.';

    /**
     * @param  list<int>  $uncategorized  channel ids without a category, in order
     * @param  list<array{id: int, channels: list<int>}>  $categories  categories in order, each with its channel ids in order
     *
     * @throws ValidationException when the layout does not match the server's channels and categories
     */
    public function reorder(Server $server, array $uncategorized, array $categories): void
    {
        DB::transaction(function () use ($server, $uncategorized, $categories): void {
            $sentCategories = array_map(fn (array $category): int => $category['id'], $categories);
            $sentChannels = array_merge($uncategorized, ...array_map(fn (array $category): array => $category['channels'], $categories));

            $this->assertSameSet($sentCategories, $server->categories()->getQuery()->pluck('id')->all());
            $this->assertSameSet($sentChannels, $server->channels()->getQuery()->pluck('id')->all());

            foreach ($categories as $position => $category) {
                $server->categories()->getQuery()->whereKey($category['id'])->update(['position' => $position]);
            }

            $groups = [[null, $uncategorized]];
            foreach ($categories as $category) {
                $groups[] = [$category['id'], $category['channels']];
            }

            foreach ($groups as [$categoryId, $channelIds]) {
                foreach ($channelIds as $position => $channelId) {
                    $server->channels()->getQuery()->whereKey($channelId)->update([
                        'category_id' => $categoryId,
                        'position' => $position,
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    /**
     * @param  list<int>  $sent
     * @param  array<int, mixed>  $actual
     */
    private function assertSameSet(array $sent, array $actual): void
    {
        $actual = array_map('intval', $actual);
        sort($actual);
        $sorted = $sent;
        sort($sorted);

        // Duplicates make $sorted longer than $actual, so they fail too.
        if ($sorted !== $actual) {
            throw ValidationException::withMessages(['layout' => self::STALE_MESSAGE]);
        }
    }
}
