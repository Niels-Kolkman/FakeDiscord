<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Channel;
use App\Models\User;
use App\Services\PermissionService;

/**
 * Discovered automatically for App\Models\Channel.
 *
 * What a member may do in one channel, from their channel permissions
 * (PermissionService::channelPermissions): the server permissions with
 * the channel's role overrides applied. Every ability needs View Channels first: you cannot
 * post in, react in or moderate a channel you cannot see.
 *
 * Changing the channel itself is ServerPolicy::manageChannels.
 */
class ChannelPolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * View Channels: open the channel, read its history and pins, search
     * it and listen to its live events.
     */
    public function view(User $user, Channel $channel): bool
    {
        return $this->allows($user, $channel, Permission::ViewChannels);
    }

    /**
     * Send Messages (voice channels are refused by StoreMessageRequest).
     */
    public function sendMessages(User $user, Channel $channel): bool
    {
        return $this->allows($user, $channel, Permission::ViewChannels, Permission::SendMessages);
    }

    /**
     * Attach Files to the messages you send.
     */
    public function attachFiles(User $user, Channel $channel): bool
    {
        return $this->allows($user, $channel, Permission::ViewChannels, Permission::SendMessages, Permission::AttachFiles);
    }

    /**
     * Add Reactions: start a new reaction. Joining an existing one and
     * taking your own back only needs View Channels (MessagePolicy).
     */
    public function addReactions(User $user, Channel $channel): bool
    {
        return $this->allows($user, $channel, Permission::ViewChannels, Permission::AddReactions);
    }

    /**
     * Manage Messages: delete and pin other members' messages.
     */
    public function manageMessages(User $user, Channel $channel): bool
    {
        return $this->allows($user, $channel, Permission::ViewChannels, Permission::ManageMessages);
    }

    private function allows(User $user, Channel $channel, Permission ...$required): bool
    {
        $mask = $this->permissions->channelPermissions($user, $channel);
        $needed = Permission::mask(...$required);

        return ($mask & $needed) === $needed;
    }
}
