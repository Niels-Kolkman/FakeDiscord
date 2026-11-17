<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Live messages of a server channel (App\Events\MessageSent). Only members
// of the channel's server may listen; an unknown channel id is a 403.
// Membership is checked when the browser subscribes, so someone removed
// from the server keeps an open subscription until they reload.
Broadcast::channel('channel.{channel}', function (User $user, Channel $channel) {
    return $channel->server->hasMember($user);
});
