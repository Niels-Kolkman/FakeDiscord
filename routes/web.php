<?php

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\ChannelCategoryController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChannelLayoutController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerMembershipController;
use App\Http\Controllers\ServerOwnershipController;
use App\Http\Controllers\UserProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Auth routes (register, login, logout, password reset, email verification)
// are registered by Fortify.
Route::view('/dashboard', 'dashboard')->middleware('auth')->name('dashboard');

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

// Servers need a verified email address. Who may do what is decided by
// App\Policies\ServerPolicy (rename and delete are owner only).
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/servers/create', [ServerController::class, 'create'])->name('servers.create');
    Route::post('/servers', [ServerController::class, 'store'])->name('servers.store');
    Route::get('/servers/{server}', [ServerController::class, 'show'])->name('servers.show');
    Route::get('/servers/{server}/settings', [ServerController::class, 'edit'])->name('servers.edit');
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
