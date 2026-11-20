<?php

namespace Tests\Support;

use App\Models\Channel;
use App\Models\Server;
use App\Models\User;

/**
 * A server as most feature tests need it (ISSUE-39): its owner, its
 * #general channel and one plain member. Made by serverScene() in
 * tests/Pest.php.
 */
final class ServerScene
{
    public function __construct(
        public readonly User $owner,
        public readonly Server $server,
        public readonly Channel $channel,
        public readonly User $member,
    ) {}

    /**
     * Where the channel's messages are posted and listed.
     */
    public function messagesUrl(): string
    {
        return messagesUrl($this->channel);
    }

    public function channelUrl(): string
    {
        return "/servers/{$this->server->id}/channels/{$this->channel->id}";
    }
}
