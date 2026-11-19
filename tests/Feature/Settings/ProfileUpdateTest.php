<?php

use App\Models\User;
use App\Notifications\EmailAddressChanged;
use Gerenuk\Nudge\Actions\Workspaces\CreateApiToken;
use Gerenuk\Nudge\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Nudge\Models\ApiToken;
use Gerenuk\Nudge\Models\Membership;
use Gerenuk\Nudge\Models\Role;
use Gerenuk\Nudge\Models\Workspace;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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

function addWorkspaceMember(User $user, Workspace $workspace, string $roleName): void
{
    Membership::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role_id' => Role::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('name', $roleName)->value('id'),
    ]);
}

test('a workspace owner is told why their account cannot be deleted, and stays signed in', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspace::class)->handle($user, 'Acme');
    addWorkspaceMember(User::factory()->create(), $workspace, 'Owner');

    $this->actingAs($user->refresh())
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('account');

    $this->assertAuthenticated();
    expect($user->fresh())->not->toBeNull();
});

test('a member who owns nothing is removed from their workspaces and their tokens are revoked', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspace::class)->handle($owner, 'Acme');
    $member = User::factory()->create();
    addWorkspaceMember($member, $workspace, 'Editor');
    app(CreateApiToken::class)->handle($workspace, $member, 'x', ['contacts.view']);

    $this->actingAs($member->refresh())
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect(User::find($member->id))->toBeNull()
        ->and(Membership::where('user_id', $member->id)->count())->toBe(0)
        ->and(ApiToken::withoutGlobalScopes()->where('created_by', $member->id)->count())->toBe(0);
});

test('a second owner can delete their account when another owner remains', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspace::class)->handle($owner, 'Acme');
    $second = User::factory()->create();
    addWorkspaceMember($second, $workspace, 'Owner');

    $this->actingAs($second->refresh())
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    expect(User::find($second->id))->toBeNull();
});

test('a user with a remember token is really deleted', function () {
    $user = User::factory()->create();
    $user->forceFill(['remember_token' => Str::random(60)])->save();

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);

    expect(User::find($user->id))->toBeNull();
});

test('changing the email is rate limited', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $attempt) {
        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com', 'current_password' => 'wrong'])
            ->assertRedirect()
            ->assertSessionHasErrors('current_password');
    }

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com', 'current_password' => 'wrong'])
        ->assertTooManyRequests();

    expect($user->fresh()->email)->not->toBe('new@example.com');
});

test('changing only the case of the email is not a change', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ann@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => ' Ann@Example.COM '])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->email)->toBe('ann@example.com')
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('a new email is stored lowercased', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ann@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'New.Ann@Example.com', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->email)->toBe('new.ann@example.com')
        ->and($user->fresh()->email_verified_at)->toBeNull();
});
