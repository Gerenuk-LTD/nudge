<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// The marketing core owns the signed-in experience; its home page is the first screen after signing in.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/home')->name('dashboard');
});

require __DIR__.'/settings.php';
