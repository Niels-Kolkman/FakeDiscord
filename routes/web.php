<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\UserProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Auth routes (register, login, logout, password reset) are registered by Fortify.
Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

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
});
