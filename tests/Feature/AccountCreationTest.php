<?php

use App\Models\User;
use Gerenuk\Marketing\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Marketing\Models\Account;

/**
 * Starts the installation's first account, so later workspace-less users are not the first.
 */
function accountCreationExistingAccount(): void
{
    app(CreateWorkspace::class)->handle(User::factory()->create(), 'Acme');
}

test('in invite mode a workspace-less user cannot start a second account', function () {
    config(['registration.mode' => 'invite']);
    accountCreationExistingAccount();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('marketing.workspaces.store'), ['name' => 'Mine'])->assertForbidden();

    expect(Account::query()->count())->toBe(1);
});

test('in closed mode a workspace-less user cannot start a second account', function () {
    config(['registration.mode' => 'closed']);
    accountCreationExistingAccount();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('marketing.workspaces.store'), ['name' => 'Mine'])->assertForbidden();

    expect(Account::query()->count())->toBe(1);
});

test('in open mode a workspace-less user can start an account', function () {
    config(['registration.mode' => 'open']);
    accountCreationExistingAccount();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('marketing.workspaces.store'), ['name' => 'Mine'])->assertRedirect(route('marketing.home'));

    expect(Account::query()->count())->toBe(2);
});

test('the installation\'s first account can be started in invite mode', function () {
    config(['registration.mode' => 'invite']);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('marketing.workspaces.store'), ['name' => 'First'])->assertRedirect(route('marketing.home'));

    expect(Account::query()->count())->toBe(1);
});
