<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Auth routes (register, login, logout, password reset, email verification)
// are registered by Fortify.
Route::view('/dashboard', 'dashboard')->middleware('auth')->name('dashboard');

// Routes that need a verified email address (ISSUE-7). Joining a server
// (invite links, ISSUE-11) and other server-membership actions belong here.
// Unverified users are redirected to the "verify your email" notice
// (verification.notice), or get a 403 on JSON requests.
Route::middleware(['auth', 'verified'])->group(function () {
    //
});
