<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// A user's own channel. Friend requests and friendship changes arrive here
// (App\Events\FriendshipChanged, ISSUE-22), and so does activity in their
// DMs for the sidebar list (App\Events\ConversationActivity, ISSUE-23).
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Live messages of a server channel (App\Events\MessageSent). Only members
// who can see the channel (ChannelPolicy::view, ISSUE-27) may listen, so a
// private channel or one a role override hides (ISSUE-28) is refused; an
// unknown channel id is a 403. This is checked when the browser
// subscribes, so someone removed from the server, or who loses View
// Channels, keeps an open subscription until they reload.
Broadcast::channel('channel.{channel}', function (User $user, Channel $channel) {
    return $user->can('view', $channel);
});

// Typing indicators (ISSUE-17): a presence channel per server channel, used
// only for "client-typing" whispers between the people viewing it. Same
// membership rule as above. The returned array is the member info every
// other subscriber sees, so the name shown in "X is typing…" comes from
// here (the server nickname, like the member list), not from the whisper.
// Live messages of a DM (ISSUE-23): MessageSent, MessageUpdated,
// MessageDeleted and ReactionsUpdated for conversation messages. Only the
// participants may listen; an unknown conversation id is a 403.
Broadcast::channel('conversation.{conversation}', function (User $user, Conversation $conversation) {
    return $conversation->hasParticipant($user);
});

// Typing indicators in a DM: a presence channel, like "typing.{channel}"
// below, for the participants only. The name shown is the user's name.
Broadcast::channel('conversation-typing.{conversation}', function (User $user, Conversation $conversation) {
    if (! $conversation->hasParticipant($user)) {
        return false;
    }

    return [
        'id' => (int) $user->getKey(),
        'name' => $user->name,
    ];
});

Broadcast::channel('typing.{channel}', function (User $user, Channel $channel) {
    $member = $channel->server->memberships()->where('user_id', $user->getKey())->first();

    if ($member === null || ! $user->can('view', $channel)) {
        return false;
    }

    return [
        'id' => (int) $user->getKey(),
        'name' => $member->nickname ?? $user->name,
    ];
});
