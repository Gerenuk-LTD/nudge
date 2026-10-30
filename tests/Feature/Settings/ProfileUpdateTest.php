<?php

use App\Models\User;
use App\Notifications\EmailAddressChanged;
use Gerenuk\Marketing\Actions\Workspaces\CreateWorkspace;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile page shares the current workspace so the sidebar keeps its navigation', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspace::class)->handle($user, 'Acme');

    $response = $this
        ->actingAs($user->refresh())
        ->get(route('profile.edit'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('workspace.current.id', $workspace->id)
        ->has('workspace.permissions'));
});

test('profile page is displayed without a workspace instead of redirecting to create one', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->missing('workspace'));
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'current_password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('changing the email needs the current password', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com'])
        ->assertSessionHasErrors('current_password');

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com', 'current_password' => 'wrong'])
        ->assertSessionHasErrors('current_password');

    expect($user->fresh()->email)->toBe('old@example.com');
});

test('changing the email verifies the new address and tells the old one', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, VerifyEmail::class);
    Notification::assertSentOnDemand(EmailAddressChanged::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@example.com');
});

test('changing only the name needs no password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Renamed', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('Renamed');
});
