<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('the owner can be created from the command line', function () {
    $this->artisan('app:create-owner', ['email' => 'Owner@Example.com', '--name' => 'Olive'])
        ->expectsQuestion('Password', 'a-long-password-123')
        ->expectsQuestion('Confirm password', 'a-long-password-123')
        ->assertExitCode(0);

    $user = User::query()->sole();

    expect($user->email)->toBe('owner@example.com')
        ->and($user->name)->toBe('Olive')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-long-password-123', $user->password))->toBeTrue();
});

test('create-owner refuses when an account already exists', function () {
    User::factory()->create();

    $this->artisan('app:create-owner', ['email' => 'o@example.com'])->assertExitCode(1);

    expect(User::query()->count())->toBe(1);
});

test('create-owner refuses mismatched passwords', function () {
    $this->artisan('app:create-owner', ['email' => 'o@example.com'])
        ->expectsQuestion('Password', 'a-long-password-123')
        ->expectsQuestion('Confirm password', 'something-else-456')
        ->assertExitCode(1);

    expect(User::query()->exists())->toBeFalse();
});
