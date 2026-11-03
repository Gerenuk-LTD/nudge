<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('it sets the password for a user', function () {
    $user = User::factory()->create();

    $this->artisan('app:set-password', ['email' => $user->email, '--password' => 'new-password-123'])->assertSuccessful();

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
});

test('it prompts for the password when not given', function () {
    $user = User::factory()->create();

    $this->artisan('app:set-password', ['email' => $user->email])
        ->expectsQuestion('New password', 'new-password-123')
        ->expectsQuestion('Confirm password', 'new-password-123')
        ->assertSuccessful();

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
});

test('it fails when the confirmation does not match', function () {
    $user = User::factory()->create();
    $original = $user->password;

    $this->artisan('app:set-password', ['email' => $user->email])
        ->expectsQuestion('New password', 'new-password-123')
        ->expectsQuestion('Confirm password', 'something-else')
        ->assertFailed();

    expect($user->fresh()->password)->toBe($original);
});

test('it fails for an unknown email', function () {
    $this->artisan('app:set-password', ['email' => 'nobody@example.com', '--password' => 'new-password-123'])->assertFailed();
});
