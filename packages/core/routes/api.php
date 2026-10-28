<?php

use Gerenuk\Marketing\Http\Controllers\Api\ContactController;
use Gerenuk\Marketing\Http\Controllers\Api\ContactListController;
use Gerenuk\Marketing\Http\Controllers\Api\CustomFieldController;
use Gerenuk\Marketing\Http\Controllers\Api\MeController;
use Gerenuk\Marketing\Http\Controllers\Api\SegmentController;
use Gerenuk\Marketing\Http\Controllers\Api\TagController;
use Illuminate\Support\Facades\Route;

Route::get('me', MeController::class)->name('me');

Route::middleware('marketing.token-can:contacts.view')->group(function () {
    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::get('contacts/{email}', [ContactController::class, 'show'])->name('contacts.show');
    Route::get('lists', [ContactListController::class, 'index'])->name('lists.index');
    Route::get('lists/{list}', [ContactListController::class, 'show'])->name('lists.show');
    Route::get('tags', [TagController::class, 'index'])->name('tags.index');
    Route::get('custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
    Route::get('segments', [SegmentController::class, 'index'])->name('segments.index');
    Route::get('segments/{segment}', [SegmentController::class, 'show'])->name('segments.show');
    Route::get('segments/{segment}/contacts', [SegmentController::class, 'contacts'])->name('segments.contacts');
});

Route::middleware('marketing.token-can:contacts.manage')->group(function () {
    Route::put('contacts/{email}', [ContactController::class, 'upsert'])->name('contacts.upsert');
    Route::delete('contacts/{email}', [ContactController::class, 'archive'])->name('contacts.archive');

    Route::post('lists', [ContactListController::class, 'store'])->name('lists.store');
    Route::put('lists/{list}', [ContactListController::class, 'update'])->name('lists.update');
    Route::delete('lists/{list}', [ContactListController::class, 'destroy'])->name('lists.destroy');
    Route::post('lists/{list}/contacts', [ContactListController::class, 'addContacts'])->name('lists.contacts.store');
    Route::delete('lists/{list}/contacts', [ContactListController::class, 'removeContacts'])->name('lists.contacts.destroy');

    Route::post('custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
});
