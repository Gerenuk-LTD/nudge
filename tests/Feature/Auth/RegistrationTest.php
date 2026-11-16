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
    config(['registration.setup_token' => 'setup-secret']);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'setup_token' => 'setup-secret',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('the first user can register even when sign-up is closed', function () {
    config(['registration.mode' => 'closed', 'registration.setup_token' => 'setup-secret']);

    $this->post(route('register.store'), [...registrationData('first@example.com'), 'setup_token' => 'setup-secret']);

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

test('closed sign-up sends every request to register to the login page', function () {
    User::factory()->create();
    config(['registration.mode' => 'closed']);

    $this->get(route('register'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Sign-up is closed on this installation.');

    $this->post(route('register.store'), registrationData('stranger@example.com'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse();

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

test('the first account needs the setup token', function () {
    config(['registration.mode' => 'open', 'registration.setup_token' => 'setup-secret']);

    $this->get(route('register'))->assertInertia(fn ($page) => $page->where('needsSetupToken', true));

    $this->post(route('register.store'), registrationData('first@example.com'))->assertSessionHasErrors('email');
    $this->post(route('register.store'), [...registrationData('first@example.com'), 'setup_token' => 'wrong'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post(route('register.store'), [...registrationData('first@example.com'), 'setup_token' => 'setup-secret'])->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});

test('a failed first sign-up does not keep the setup token in the session', function () {
    config(['registration.mode' => 'open', 'registration.setup_token' => 'setup-secret']);

    $this->post(route('register.store'), [...registrationData('first@example.com'), 'password_confirmation' => 'different', 'setup_token' => 'setup-secret'])
        ->assertSessionHasErrors('password')
        ->assertSessionHas('_old_input.email', 'first@example.com')
        ->assertSessionMissing('_old_input.setup_token');
});

test('without a configured setup token the first account cannot be created on the website', function () {
    config(['registration.mode' => 'open', 'registration.setup_token' => null]);

    $this->post(route('register.store'), [...registrationData('first@example.com'), 'setup_token' => ''])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::query()->exists())->toBeFalse();
});

test('the setup token is ignored once an owner exists', function () {
    User::factory()->create();
    config(['registration.mode' => 'invite', 'registration.setup_token' => 'setup-secret']);

    $this->get(route('register'))->assertInertia(fn ($page) => $page->where('needsSetupToken', false));

    $this->post(route('register.store'), [...registrationData('stranger@example.com'), 'setup_token' => 'setup-secret'])
        ->assertSessionHasErrors(['email' => 'Sign-up is by invitation. Use the email address your invitation was sent to.']);

    $this->assertGuest();
});
