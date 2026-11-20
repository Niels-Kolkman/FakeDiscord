<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the whole sidebar order after a drag and drop.
 *
 * The client sends the complete layout, not a single move. It must name
 * every channel and category of the server exactly once. If another tab or
 * user added or deleted one in the meantime, the request is rejected and
 * the client reloads, so a stale sidebar never overwrites a newer one.
 *
 * A member who cannot see every channel only has the visible
 * ones in their sidebar. With a $viewer the layout must then name exactly
 * the channels and categories of Server::channelLayout($viewer); the hidden
 * ones keep their category and position.
 */
class ReorderChannels
{
    public const STALE_MESSAGE = 'The channel list changed in the meantime. Reload the page and try again.';

    /**
     * @param  list<int>  $uncategorized  channel ids without a category, in order
     * @param  list<array{id: int, channels: list<int>}>  $categories  categories in order, each with its channel ids in order
     * @param  User|null  $viewer  the member reordering; null checks against every channel and category
     *
     * @throws ValidationException when the layout does not match the server's channels and categories
     */
    public function reorder(Server $server, array $uncategorized, array $categories, ?User $viewer = null): void
    {
        DB::transaction(function () use ($server, $uncategorized, $categories, $viewer): void {
            $sentCategories = array_map(fn (array $category): int => $category['id'], $categories);
            $sentChannels = array_merge($uncategorized, ...array_map(fn (array $category): array => $category['channels'], $categories));

            if ($viewer === null) {
                $knownCategories = $server->categories()->getQuery()->pluck('id')->all();
                $knownChannels = $server->channels()->getQuery()->pluck('id')->all();
            } else {
                $layout = $server->channelLayout($viewer);
                $knownCategories = $layout->categories->modelKeys();
                $knownChannels = $layout->channels()->map(fn (Channel $channel): int => (int) $channel->getKey())->all();
            }

            $this->assertSameSet($sentCategories, $knownCategories);
            $this->assertSameSet($sentChannels, $knownChannels);

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
