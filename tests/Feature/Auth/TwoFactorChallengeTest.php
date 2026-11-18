<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

test('two factor challenge redirects to login when not authenticated', function () {
    $response = $this->get(route('two-factor.login'));

    $response->assertRedirect(route('login'));
});

test('two factor challenge can be rendered', function () {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/two-factor-challenge'),
        );
});

test('two factor attempts are capped per day', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    foreach (range(1, 50) as $attempt) {
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'not-a-real-code'])->assertRedirect();

        // Stay under the per-minute limit so only the daily cap can trip.
        if ($attempt % 5 === 0) {
            $this->travel(61)->seconds();
        }
    }

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'not-a-real-code'])->assertTooManyRequests();
    $this->assertGuest();
});
