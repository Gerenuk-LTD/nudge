<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
    Features::passkeys([
        'confirmPassword' => true,
    ]);
});

function confirmedSession(User $user)
{
    return test()->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
}

test('an unverified user cannot get passkey registration options', function () {
    $this->skipUnlessFortifyHas(Features::passkeys());

    confirmedSession(User::factory()->unverified()->create())
        ->getJson(route('passkey.registration-options'))
        ->assertForbidden();
});

test('an unverified user cannot store a passkey', function () {
    $this->skipUnlessFortifyHas(Features::passkeys());

    confirmedSession(User::factory()->unverified()->create())
        ->postJson(route('passkey.store'), [])
        ->assertForbidden();
});

test('an unverified user cannot enable two factor authentication', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->unverified()->create();

    confirmedSession($user)->postJson(route('two-factor.enable'))->assertForbidden();

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('a verified user can enable two factor authentication', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->create();

    $response = confirmedSession($user)->postJson(route('two-factor.enable'));

    expect($response->getStatusCode())->not->toBe(403);
    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

test('a verified user can get passkey registration options', function () {
    $this->skipUnlessFortifyHas(Features::passkeys());

    confirmedSession(User::factory()->create())
        ->getJson(route('passkey.registration-options'))
        ->assertOk();
});

test('an unverified user can still remove two factor authentication', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->unverified()->create();
    $user->forceFill(['two_factor_secret' => encrypt('secret')])->save();

    $response = confirmedSession($user)->deleteJson(route('two-factor.disable'));

    expect($response->getStatusCode())->not->toBe(403);
});
