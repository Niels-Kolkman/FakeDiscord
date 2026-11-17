<?php

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServerController;
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
