<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

test('security page is displayed', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
    Features::passkeys([
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('canManagePasskeys', true)
            ->where('passkeys', [])
            ->where('canManageTwoFactor', true)
            ->where('twoFactorEnabled', false),
        );
});

test('security page opens without a separate password page, while two factor changes still need a confirmed password', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->create();

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $this->actingAs($user)->get(route('security.edit'))->assertOk();

    $this->actingAs($user)
        ->postJson(route('two-factor.enable'))
        ->assertStatus(423);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('the security page lists where the user is signed in', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'other-session',
        'user_id' => $user->id,
        'ip_address' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
        'payload' => '',
        'last_activity' => now()->subHour()->timestamp,
    ]);

    $this->actingAs($user)->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sessions', 1)
            ->where('sessions.0.browser', 'Safari')
            ->where('sessions.0.platform', 'iPhone')
            ->where('sessions.0.ip_address', '203.0.113.9')
            ->where('sessions.0.current', false));
});

test('other sessions can be signed out after confirming the password', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $someoneElse = User::factory()->create();
    foreach ([[$user->id, 'mine'], [$someoneElse->id, 'theirs']] as [$userId, $id]) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => now()->timestamp]);
    }

    $this->actingAs($user)->delete(route('sessions.destroy-others'), ['password' => 'wrong'])
        ->assertSessionHasErrors('password');
    expect(DB::table('sessions')->where('id', 'mine')->exists())->toBeTrue();

    $this->actingAs($user)->delete(route('sessions.destroy-others'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect(DB::table('sessions')->pluck('id')->all())->toBe(['theirs']);
});

test('security page renders without two factor when feature is disabled', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    config(['fortify.features' => []]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('canManagePasskeys', false)
            ->where('passkeys', [])
            ->where('canManageTwoFactor', false)
            ->missing('twoFactorEnabled')
            ->missing('requiresConfirmation'),
        );
});

test('password can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasErrors('current_password')
        ->assertRedirect(route('security.edit'));
});
