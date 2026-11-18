<?php

use Illuminate\Support\Facades\Route;

// Self-hosted installs start at sign-in; signed-in visitors are passed on to the app from there.
Route::redirect('/', '/login')->name('home');

// The core package owns the signed-in experience; its home page is the first screen after signing in.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/home')->name('dashboard');
});

require __DIR__.'/settings.php';
