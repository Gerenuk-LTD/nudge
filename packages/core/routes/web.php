<?php

use Gerenuk\Marketing\Http\Controllers\ApiTokenController;
use Gerenuk\Marketing\Http\Controllers\ContactController;
use Gerenuk\Marketing\Http\Controllers\ContactListController;
use Gerenuk\Marketing\Http\Controllers\CurrentWorkspaceController;
use Gerenuk\Marketing\Http\Controllers\CustomFieldController;
use Gerenuk\Marketing\Http\Controllers\ImportController;
use Gerenuk\Marketing\Http\Controllers\InvitationController;
use Gerenuk\Marketing\Http\Controllers\MemberController;
use Gerenuk\Marketing\Http\Controllers\RoleController;
use Gerenuk\Marketing\Http\Controllers\SegmentController;
use Gerenuk\Marketing\Http\Controllers\TagController;
use Gerenuk\Marketing\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

// Routes that work before the user has a workspace.
Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');

Route::middleware('marketing.workspace')->group(function () {
    Route::put('current-workspace', [CurrentWorkspaceController::class, 'update'])->name('current-workspace.update');

    Route::middleware('can:contacts.view')->group(function () {
        Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::get('contacts/create', [ContactController::class, 'create'])->middleware('can:contacts.manage')->name('contacts.create');
        Route::get('contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
        Route::get('lists', [ContactListController::class, 'index'])->name('lists.index');
        Route::get('tags', [TagController::class, 'index'])->name('tags.index');
        Route::get('custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
        Route::get('segments', [SegmentController::class, 'index'])->name('segments.index');
    });

    Route::middleware('can:contacts.manage')->group(function () {
        Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::put('contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
        Route::post('contacts/{contact}/archive', [ContactController::class, 'archive'])->name('contacts.archive');
        Route::post('contacts/{contact}/restore', [ContactController::class, 'restore'])->name('contacts.restore');

        Route::post('lists', [ContactListController::class, 'store'])->name('lists.store');
        Route::put('lists/{list}', [ContactListController::class, 'update'])->name('lists.update');
        Route::delete('lists/{list}', [ContactListController::class, 'destroy'])->name('lists.destroy');

        Route::put('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
        Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

        Route::post('custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
        Route::put('custom-fields/{customField}', [CustomFieldController::class, 'update'])->name('custom-fields.update');
        Route::delete('custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');
    });

    Route::middleware('can:segments.manage')->group(function () {
        Route::get('segments/create', [SegmentController::class, 'create'])->name('segments.create');
        Route::post('segments', [SegmentController::class, 'store'])->name('segments.store');
        Route::post('segments/preview', [SegmentController::class, 'preview'])->name('segments.preview');
        Route::get('segments/{segment}/edit', [SegmentController::class, 'edit'])->name('segments.edit');
        Route::put('segments/{segment}', [SegmentController::class, 'update'])->name('segments.update');
        Route::delete('segments/{segment}', [SegmentController::class, 'destroy'])->name('segments.destroy');
    });

    Route::middleware('can:contacts.import')->group(function () {
        Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
        Route::get('imports/create', [ImportController::class, 'create'])->name('imports.create');
        Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
        Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
        Route::post('imports/{import}/start', [ImportController::class, 'start'])->name('imports.start');
        Route::get('imports/{import}/errors', [ImportController::class, 'errors'])->name('imports.errors');
    });

    Route::prefix('workspace')->group(function () {
        Route::get('settings', [WorkspaceController::class, 'edit'])->name('workspace.edit');
        Route::put('settings', [WorkspaceController::class, 'update'])->middleware('can:workspace.manage')->name('workspace.update');

        Route::middleware('can:members.manage')->group(function () {
            Route::get('members', [MemberController::class, 'index'])->name('members.index');
            Route::post('members/invitations', [MemberController::class, 'invite'])->name('members.invite');
            Route::delete('members/invitations/{invitation}', [MemberController::class, 'revokeInvitation'])->name('members.invitations.destroy');
            Route::put('members/{membership}', [MemberController::class, 'update'])->name('members.update');
            Route::delete('members/{membership}', [MemberController::class, 'destroy'])->name('members.destroy');
        });

        Route::middleware('can:roles.manage')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        Route::middleware('can:api_tokens.manage')->group(function () {
            Route::get('api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
            Route::post('api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
            Route::delete('api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
        });
    });
});
