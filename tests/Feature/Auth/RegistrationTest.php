<?php

use App\Models\User;
use Carbon\CarbonInterface;
use Gerenuk\Marketing\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Marketing\Models\Invitation;
use Gerenuk\Marketing\Models\Role;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

/**
 * @return array<string, string>
 */
function registrationData(string $email): array
{
    return [
        'name' => 'Test User',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ];
}

function inviteEmail(string $email, CarbonInterface $expiresAt): void
{
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspace::class)->handle($owner, 'Acme');

    Invitation::withoutGlobalScopes()->create([
        'workspace_id' => $workspace->id,
        'role_id' => Role::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail()->id,
        'invited_by' => $owner->id,
        'email' => $email,
        'token_hash' => hash('sha256', 'token'),
        'expires_at' => $expiresAt,
    ]);
}

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('the first user can register even when sign-up is closed', function () {
    config(['registration.mode' => 'closed']);

    $this->post(route('register.store'), registrationData('first@example.com'));

    $this->assertAuthenticated();
});

test('invite-only sign-up refuses addresses without an invitation', function () {
    User::factory()->create();
    config(['registration.mode' => 'invite']);

    $this->post(route('register.store'), registrationData('stranger@example.com'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::where('email', 'stranger@example.com')->exists())->toBeFalse();
});

test('invite-only sign-up accepts an invited address', function () {
    config(['registration.mode' => 'invite']);
    inviteEmail('invited@example.com', now()->addDay());

    $this->post(route('register.store'), registrationData('Invited@Example.com'));

    $this->assertAuthenticated();
});

test('an expired invitation does not allow sign-up', function () {
    config(['registration.mode' => 'invite']);
    inviteEmail('invited@example.com', now()->subDay());

    $this->post(route('register.store'), registrationData('invited@example.com'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('closed sign-up refuses everyone after the first user, and hides the page', function () {
    User::factory()->create();
    config(['registration.mode' => 'closed']);

    $this->get(route('register'))->assertNotFound();

    $this->post(route('register.store'), registrationData('stranger@example.com'))
        ->assertSessionHasErrors('email');

    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('canRegister', false));
});

test('open sign-up allows anyone', function () {
    User::factory()->create();
    config(['registration.mode' => 'open']);

    $this->post(route('register.store'), registrationData('stranger@example.com'));

    $this->assertAuthenticated();
});

test('registration is rate limited', function () {
    User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('register.store'), registrationData("uninvited{$attempt}@example.com"))->assertRedirect();
    }

    $this->post(route('register.store'), registrationData('uninvited6@example.com'))->assertTooManyRequests();
    $this->assertGuest();
});
