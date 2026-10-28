<?php

use Gerenuk\Marketing\Http\Controllers\Public\UnsubscribeController;
use Illuminate\Support\Facades\Route;

// Public pages for recipients. They use the web middleware for Inertia, but need no login.
Route::get('u/{recipient}/{signature}', [UnsubscribeController::class, 'show'])
    ->whereNumber('recipient')->name('unsubscribe.show');
