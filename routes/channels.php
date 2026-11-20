<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Every channel here is private or presence, so /broadcasting/auth asks
// the callback below and refuses guests. The full list and who may join
// each one is checked in tests/Feature/Security/BroadcastChannelAuthorisationTest.php:
// a new channel must be added there too. The only public
// channel is "test" (App\Events\TestBroadcast, a tinker smoke test that
// carries no user data).

// A user's own channel. Friend requests and friendship changes arrive here
// (App\Events\FriendshipChanged), and so does activity in their
// DMs for the sidebar list (App\Events\ConversationActivity).
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Live messages of a server channel (App\Events\MessageSent). Only members
// who can see the channel (ChannelPolicy::view) may listen, so a
// private channel or one a role override hides is refused; an
// unknown channel id is a 403. This is checked when the browser
// subscribes, so someone who leaves or loses View Channels keeps an open
// subscription until they reload. Kicked and banned members
// have their sockets closed by App\Support\RealtimeSessions, so they are
// checked again when pusher-js reconnects.
Broadcast::channel('channel.{channel}', function (User $user, Channel $channel) {
    return $user->can('view', $channel);
});

// Live messages of a DM: MessageSent, MessageUpdated,
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

// Online presence: one presence channel per server
// (App\Support\ServerPresence::channelName), joined by every page of each
// member (resources/js/presence.js), so Reverb knows who is online;
// ServerPresence asks it when a member list renders. Members only, like
// the server page. The member info carries the status the user picked
// when the tab subscribed; changes after that arrive as
// App\Events\UserStatusChanged (".user.status") on this channel. Kicked
// and banned members are refused when pusher-js reconnects.
Broadcast::channel('server.{server}', function (User $user, Server $server) {
    if (! $server->hasMember($user)) {
        return false;
    }

    return [
        'id' => (int) $user->getKey(),
        'status' => $user->chosenStatus(),
    ];
});

// Typing indicators: a presence channel per server channel, used
// only for "client-typing" whispers between the people viewing it. Same
// rule as "channel.{channel}". The returned array is the member info every
// other subscriber sees, so the name shown in "X is typing…" comes from
// here (the server nickname, like the member list), not from the whisper.
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
