<?php

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\ChannelCategoryController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChannelLayoutController;
use App\Http\Controllers\ConversationMessageController;
use App\Http\Controllers\ConversationReactionController;
use App\Http\Controllers\DirectMessageController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\GroupConversationController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageSearchController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerMembershipController;
use App\Http\Controllers\ServerOwnershipController;
use App\Http\Controllers\UserProfileController;
use Illuminate\Support\Facades\Route;

// There is no landing page (ISSUE-44): guests go to the login page,
// signed-in users straight to the dashboard.
Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

// Auth routes (register, login, logout, password reset, email verification)
// are registered by Fortify.
Route::view('/dashboard', 'dashboard')->middleware('auth')->name('dashboard');

// Message attachments (ISSUE-19). Only reachable through the temporary
// signed links in Message::toPayload() and messages/item.blade.php; the
// signature is the access check, so no session is needed (like a CDN
// link). {filename} is only there so saved files get a sensible name.
// {id} is not model bound: binding runs before `signed`, and a 404 for
// unknown ids would tell anyone which attachments exist.
Route::get('/attachments/{id}/{filename}', [AttachmentController::class, 'show'])
    // One page can show 50 messages with 5 images each.
    ->middleware(['signed', 'throttle:600,1'])
    ->where(['id' => '[0-9]+', 'filename' => '[^/]+'])
    ->name('attachments.show');

// Account settings (ISSUE-9). Only `auth`, not `verified`: a user with a
// mistyped address must be able to fix it. Every change re-asks for the
// current password; the password form uses Fortify's PUT /user/password.
Route::middleware('auth')->prefix('settings/account')->group(function () {
    Route::get('/', [AccountSettingsController::class, 'show'])
        ->name('settings.account');
    Route::put('/email', [AccountSettingsController::class, 'updateEmail'])
        ->middleware('throttle:6,1')
        ->name('settings.account.email');
    Route::delete('/', [AccountSettingsController::class, 'destroy'])
        ->middleware('throttle:6,1')
        ->name('settings.account.destroy');
});

// Profile settings and the profile popover JSON (ISSUE-8).
Route::middleware('auth')->group(function () {
    Route::get('/settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    // JSON for the profile popover.
    Route::get('/users/{user:username}/profile', [UserProfileController::class, 'show'])->name('users.profile');
});

// Friends (ISSUE-22): the page has ?tab=all|pending|blocked. Like servers,
// this needs a verified email address. App\Policies\FriendshipPolicy
// decides who may answer, cancel, remove or unblock; anyone who is not one
// of the two users gets a 404. The other person hears about every change
// through App\Events\FriendshipChanged on "App.Models.User.{id}".
Route::middleware(['auth', 'verified'])->prefix('friends')->group(function () {
    Route::get('/', [FriendController::class, 'index'])->name('friends.index');
    Route::post('/', [FriendController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('friends.store');

    // Blocking (ISSUE-25), field `user_id`: App\Actions\Friends\BlockUser.
    Route::post('/block', [FriendController::class, 'block'])
        ->middleware('throttle:30,1')
        ->name('friends.block');

    Route::middleware('throttle:60,1')->whereNumber('friendship')->group(function () {
        Route::post('/{friendship}/accept', [FriendController::class, 'accept'])->name('friends.accept');
        Route::post('/{friendship}/decline', [FriendController::class, 'decline'])->name('friends.decline');
        Route::delete('/{friendship}/request', [FriendController::class, 'cancel'])->name('friends.cancel');
        Route::delete('/{friendship}/block', [FriendController::class, 'unblock'])->name('friends.unblock');
        Route::delete('/{friendship}', [FriendController::class, 'destroy'])->name('friends.destroy');
    });
});

// 1:1 direct messages (ISSUE-23). POST /dms opens (or creates) the DM with
// `user_id`: friends and people you share a server with, never across a
// block. Only participants see a conversation (ConversationPolicy, a 404
// for anyone else). The message routes mirror the channel ones, so
// resources/js/channel-messages.js works the same; events go out on the
// private "conversation.{id}" channel.
Route::middleware(['auth', 'verified'])->prefix('dms')->group(function () {
    Route::post('/', [DirectMessageController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('dms.store');

    // Group DMs (ISSUE-24): `user_ids[]` (friends only) and an optional
    // `name`; at most Conversation::MAX_PARTICIPANTS people.
    Route::post('/groups', [GroupConversationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('dms.groups.store');

    // can:view runs before the form requests, so anyone who is not a
    // participant gets ConversationPolicy's 404 on every route, never a 403.
    Route::whereNumber('conversation')->scopeBindings()->middleware('can:view,conversation')->group(function () {
        Route::get('/{conversation}', [DirectMessageController::class, 'show'])->name('dms.show');

        // Group DMs (ISSUE-24): any participant renames and adds friends;
        // the owner removes people, anyone removes themselves (leaving).
        // {user} is scoped to the participants, so anyone else is a 404.
        Route::middleware('throttle:30,1')->group(function () {
            Route::patch('/{conversation}', [GroupConversationController::class, 'update'])->name('dms.update');
            Route::post('/{conversation}/participants', [GroupConversationController::class, 'addParticipants'])
                ->name('dms.participants.store');
            Route::delete('/{conversation}/participants/{user}', [GroupConversationController::class, 'removeParticipant'])
                ->whereNumber('user')
                ->name('dms.participants.destroy');
        });

        Route::get('/{conversation}/messages', [ConversationMessageController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('dms.messages.index');
        Route::post('/{conversation}/messages', [ConversationMessageController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('dms.messages.store');
        Route::patch('/{conversation}/messages/{message}', [ConversationMessageController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('dms.messages.update');
        Route::delete('/{conversation}/messages/{message}', [ConversationMessageController::class, 'destroy'])
            ->middleware('throttle:30,1')
            ->name('dms.messages.destroy');

        Route::post('/{conversation}/messages/{message}/reactions', [ConversationReactionController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('dms.reactions.store');
        Route::delete('/{conversation}/messages/{message}/reactions', [ConversationReactionController::class, 'destroy'])
            ->middleware('throttle:60,1')
            ->name('dms.reactions.destroy');
    });
});

// Servers need a verified email address. Who may do what is decided by
// App\Policies\ServerPolicy (rename and delete are owner only).
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/servers/create', [ServerController::class, 'create'])->name('servers.create');
    Route::post('/servers', [ServerController::class, 'store'])->name('servers.store');
    Route::get('/servers/{server}', [ServerController::class, 'show'])->name('servers.show');
    Route::get('/servers/{server}/settings', [ServerController::class, 'edit'])->name('servers.edit');

    // Message search (ISSUE-21): ?q= plus the channel / author / from / to
    // filters, through Laravel Scout's database engine. Members only.
    Route::get('/servers/{server}/search', [MessageSearchController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('servers.search');
    Route::patch('/servers/{server}', [ServerController::class, 'update'])->name('servers.update');
    Route::delete('/servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');

    // Leaving and ownership (ISSUE-13). The owner cannot leave until they
    // have transferred ownership to another member (ServerPolicy::leave).
    Route::get('/servers/{server}/leave', [ServerMembershipController::class, 'create'])->name('servers.leave.confirm');
    Route::delete('/servers/{server}/leave', [ServerMembershipController::class, 'destroy'])->name('servers.leave');
    Route::patch('/servers/{server}/owner', [ServerOwnershipController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('servers.owner.update');

    // Channels and categories (ISSUE-12). Members can open channels; the
    // rest needs ServerPolicy::manageChannels. Scoped bindings make a
    // channel or category of another server a 404.
    Route::scopeBindings()->group(function () {
        Route::get('/servers/{server}/channels/create', [ChannelController::class, 'create'])->name('channels.create');
        Route::post('/servers/{server}/channels', [ChannelController::class, 'store'])->name('channels.store');
        Route::get('/servers/{server}/channels/{channel}', [ChannelController::class, 'show'])->name('channels.show');
        Route::get('/servers/{server}/channels/{channel}/settings', [ChannelController::class, 'edit'])->name('channels.edit');
        Route::patch('/servers/{server}/channels/{channel}', [ChannelController::class, 'update'])->name('channels.update');
        Route::delete('/servers/{server}/channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');

        // Messages (ISSUE-14): any member can post; MessageSent broadcasts
        // the message on the private "channel.{id}" channel. History
        // (ISSUE-15) is cursor paginated: ?before={cursor} for older pages.
        Route::get('/servers/{server}/channels/{channel}/messages', [MessageController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('messages.index');
        Route::post('/servers/{server}/channels/{channel}/messages', [MessageController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('messages.store');

        // Editing and deleting (ISSUE-16): MessagePolicy decides; the
        // MessageUpdated / MessageDeleted events go out on "channel.{id}".
        Route::patch('/servers/{server}/channels/{channel}/messages/{message}', [MessageController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('messages.update');
        Route::delete('/servers/{server}/channels/{channel}/messages/{message}', [MessageController::class, 'destroy'])
            ->middleware('throttle:30,1')
            ->name('messages.destroy');

        // Reactions (ISSUE-18): field `emoji`; any member may react
        // (MessagePolicy::react). ReactionsUpdated goes out on "channel.{id}".
        Route::post('/servers/{server}/channels/{channel}/messages/{message}/reactions', [ReactionController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('reactions.store');
        Route::delete('/servers/{server}/channels/{channel}/messages/{message}/reactions', [ReactionController::class, 'destroy'])
            ->middleware('throttle:60,1')
            ->name('reactions.destroy');

        // Pinned messages (ISSUE-20): any member sees the list (JSON for the
        // panel, a page without JavaScript); pinning needs MessagePolicy::pin.
        // MessagePinned goes out on "channel.{id}".
        Route::get('/servers/{server}/channels/{channel}/pins', [PinController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('pins.index');
        Route::post('/servers/{server}/channels/{channel}/messages/{message}/pin', [PinController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('pins.store');
        Route::delete('/servers/{server}/channels/{channel}/messages/{message}/pin', [PinController::class, 'destroy'])
            ->middleware('throttle:30,1')
            ->name('pins.destroy');

        // Drag and drop saves the whole sidebar order as JSON.
        Route::put('/servers/{server}/layout', [ChannelLayoutController::class, 'update'])
            ->middleware('throttle:60,1')
            ->name('channels.layout');

        Route::get('/servers/{server}/categories/create', [ChannelCategoryController::class, 'create'])->name('categories.create');
        Route::post('/servers/{server}/categories', [ChannelCategoryController::class, 'store'])->name('categories.store');
        Route::get('/servers/{server}/categories/{category}/settings', [ChannelCategoryController::class, 'edit'])->name('categories.edit');
        Route::patch('/servers/{server}/categories/{category}', [ChannelCategoryController::class, 'update'])->name('categories.update');
        Route::delete('/servers/{server}/categories/{category}', [ChannelCategoryController::class, 'destroy'])->name('categories.destroy');
    });

    // Invites (ISSUE-11). Any member can create one; the owner and the
    // inviter can revoke it (App\Policies\InvitePolicy).
    Route::get('/servers/{server}/invites', [InviteController::class, 'index'])->name('servers.invites.index');
    Route::post('/servers/{server}/invites', [InviteController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('servers.invites.store');
    Route::delete('/servers/{server}/invites/{invite}', [InviteController::class, 'destroy'])
        ->scopeBindings()
        ->name('servers.invites.destroy');

    // Opening and accepting an invite. Guests are sent to log in and come back here.
    Route::middleware('throttle:30,1')->group(function () {
        Route::get('/invite/{code}', [InviteController::class, 'show'])->name('invites.show');
        Route::post('/invite/{code}', [InviteController::class, 'accept'])->name('invites.accept');
    });
});
