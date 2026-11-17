<?php

namespace App\Http\Requests;

use App\Models\Channel;

/**
 * Rename a channel, change its topic or move it to another category.
 */
class UpdateChannelRequest extends StoreChannelRequest
{
    public function channel(): Channel
    {
        $channel = $this->route('channel');
        abort_unless($channel instanceof Channel, 404);

        return $channel;
    }

    /**
     * Voice channel names keep their spaces and capitals, like Discord;
     * only whitespace runs are collapsed.
     */
    protected function normalizeName(string $name): string
    {
        $channel = $this->route('channel');

        if ($channel instanceof Channel && $channel->isVoice()) {
            return (string) preg_replace('/\s+/u', ' ', trim($name));
        }

        return parent::normalizeName($name);
    }
}
