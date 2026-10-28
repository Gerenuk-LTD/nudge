<?php

use Gerenuk\Marketing\Http\Controllers\Public\TrackingController;
use Gerenuk\Marketing\Http\Controllers\Public\UnsubscribeController;
use Gerenuk\Marketing\Http\Controllers\Public\WebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Public endpoints hit by recipients' mail clients and by providers. None of them use sessions or
 * CSRF tokens: each is authenticated by a signature in the URL or by the provider's webhook signing.
 */
Route::get('t/c/{recipient}/{link}/{signature}', [TrackingController::class, 'click'])
    ->whereNumber(['recipient', 'link'])->name('track.click');
Route::get('t/o/{recipient}/{signature}', [TrackingController::class, 'open'])
    ->whereNumber('recipient')->name('track.open');
Route::post('u/{recipient}/{signature}', [UnsubscribeController::class, 'store'])
    ->whereNumber('recipient')->name('unsubscribe.store');
Route::post('webhooks/providers/{token}', WebhookController::class)
    ->middleware('throttle:3000,1')->name('webhooks.provider');
