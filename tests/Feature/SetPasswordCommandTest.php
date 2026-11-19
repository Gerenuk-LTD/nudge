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

function userWithSignInMethods(): User
{
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt('[]'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $user->passkeys()->create([
        'name' => 'Laptop',
        'credential_id' => 'credential-'.$user->id,
        'credential' => ['id' => 'x'],
    ]);

    return $user;
}

test('--clear-sign-in-methods removes passkeys and two factor authentication', function () {
    $user = userWithSignInMethods();

    $this->artisan('app:set-password', ['email' => $user->email, '--password' => 'new-password-123', '--clear-sign-in-methods' => true])
        ->assertSuccessful();

    expect($user->passkeys()->count())->toBe(0)
        ->and($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('without the option, passkeys and two factor authentication are kept', function () {
    $user = userWithSignInMethods();

    $this->artisan('app:set-password', ['email' => $user->email, '--password' => 'new-password-123'])->assertSuccessful();

    expect($user->passkeys()->count())->toBe(1)
        ->and($user->fresh()->two_factor_secret)->not->toBeNull();
});

test('it fails for an unknown email', function () {
    $this->artisan('app:set-password', ['email' => 'nobody@example.com', '--password' => 'new-password-123'])->assertFailed();
});
