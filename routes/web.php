<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Auth routes (register, login, logout, password reset) are registered by Fortify.
Route::view('/dashboard', 'dashboard')->middleware('auth')->name('dashboard');
