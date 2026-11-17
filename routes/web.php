<?php

use App\Http\Controllers\AccountSettingsController;
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

// Routes that need a verified email address (ISSUE-7). Joining a server
// (invite links, ISSUE-11) and other server-membership actions belong here.
// Unverified users are redirected to the "verify your email" notice
// (verification.notice), or get a 403 on JSON requests.
Route::middleware(['auth', 'verified'])->group(function () {
    //
});
