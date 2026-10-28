<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// The marketing core owns the signed-in experience; its contacts page is the home screen.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/contacts')->name('dashboard');
});

require __DIR__.'/settings.php';
