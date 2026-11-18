<?php

use Gerenuk\Nudge\Actions\Workspaces\CreateApiToken;
use Gerenuk\Nudge\Actions\Workspaces\EnsureWorkspaceKeepsAnOwner;
use Gerenuk\Nudge\Actions\Workspaces\RemoveUserFromWorkspaces;
use Gerenuk\Nudge\Enums\Permission;
use Gerenuk\Nudge\Models\ApiToken;
use Gerenuk\Nudge\Models\Invitation;
use Gerenuk\Nudge\Models\Membership;
use Gerenuk\Nudge\Models\Role;
use Gerenuk\Nudge\Notifications\WorkspaceInvitation;
use Gerenuk\Nudge\Tests\Fixtures\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

function roleNamed(string $name): Role
{
    return Role::where('name', $name)->firstOrFail();
}

test('viewers can list contacts but not create them', function () {
    [, $workspace] = $this->createOwner();
    $viewer = $this->addMember($workspace, 'Viewer');

    $this->actingAs($viewer)->get(route('nudge.contacts.index'))->assertOk();
    $this->actingAs($viewer)->post(route('nudge.contacts.store'), [
        'email' => 'new@example.com', 'status' => 'subscribed',
    ])->assertForbidden();
});

test('editors cannot manage members or API tokens', function () {
    [, $workspace] = $this->createOwner();
    $editor = $this->addMember($workspace, 'Editor');

    $this->actingAs($editor)->get(route('nudge.members.index'))->assertForbidden();
    $this->actingAs($editor)->get(route('nudge.api-tokens.index'))->assertForbidden();
});

test('custom roles grant exactly their permissions', function () {
    [$owner, $workspace] = $this->createOwner();

    $this->actingAs($owner)->post(route('nudge.roles.store'), [
        'name' => 'Importer',
        'permissions' => ['contacts.view', 'contacts.import'],
    ])->assertRedirect();

    $importer = $this->addMember($workspace, 'Importer');

    $this->actingAs($importer)->get(route('nudge.imports.index'))->assertOk();
    $this->actingAs($importer)->get(route('nudge.segments.create'))->assertForbidden();
});

test('the owner role cannot be edited or deleted', function () {
    [$owner] = $this->createOwner();
    $ownerRole = roleNamed('Owner');

    $this->actingAs($owner)->put(route('nudge.roles.update', $ownerRole), ['name' => 'Boss', 'permissions' => []])->assertForbidden();
    $this->actingAs($owner)->delete(route('nudge.roles.destroy', $ownerRole))->assertForbidden();
});

test('a role in use cannot be deleted', function () {
    [$owner, $workspace] = $this->createOwner();
    memberWithCustomRole($owner, $workspace, 'Importer', ['contacts.view']);

    $this->actingAs($owner)->delete(route('nudge.roles.destroy', roleNamed('Importer')))->assertSessionHasErrors('role');
});

test('inviting someone emails them a link', function () {
    Notification::fake();
    [$owner] = $this->createOwner();

    $this->actingAs($owner)->post(route('nudge.members.invite'), [
        'email' => 'New.Person@Example.com',
        'role_id' => roleNamed('Editor')->id,
    ])->assertRedirect();

    expect(Invitation::first()->email)->toBe('new.person@example.com');

    Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInvitation::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'new.person@example.com');
});

test('an invited user accepts and joins with the invited role', function () {
    Notification::fake();
    [$owner, $workspace] = $this->createOwner();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);

    $this->actingAs($owner)->post(route('nudge.members.invite'), ['email' => 'invitee@example.com', 'role_id' => roleNamed('Viewer')->id]);

    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInvitation::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    $this->actingAs($invitee)->post(route('nudge.invitations.accept', $token))->assertRedirect(route('nudge.home'));

    expect($invitee->membershipFor($workspace)->role->name)->toBe('Viewer')
        ->and(Invitation::withoutGlobalScopes()->count())->toBe(0);
});

test('an invitation cannot be accepted by a different email address', function () {
    Notification::fake();
    [$owner, $workspace] = $this->createOwner();
    $stranger = User::factory()->create(['email' => 'stranger@example.com']);

    $this->actingAs($owner)->post(route('nudge.members.invite'), ['email' => 'invitee@example.com', 'role_id' => roleNamed('Viewer')->id]);

    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInvitation::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    $this->actingAs($stranger)->post(route('nudge.invitations.accept', $token))->assertSessionHasErrors('invitation');

    expect($stranger->belongsToWorkspace($workspace))->toBeFalse();
});

test('admins cannot hand out the owner role', function () {
    Notification::fake();
    [, $workspace] = $this->createOwner();
    $admin = $this->addMember($workspace, 'Admin');
    $editor = $this->addMember($workspace, 'Editor');
    $editorMembership = Membership::where('user_id', $editor->id)->first();

    $this->actingAs($admin)->post(route('nudge.members.invite'), ['email' => 'x@example.com', 'role_id' => roleNamed('Owner')->id])->assertForbidden();
    $this->actingAs($admin)->put(route('nudge.members.update', $editorMembership), ['role_id' => roleNamed('Owner')->id])->assertForbidden();
});

test('the last owner cannot be removed or demoted', function () {
    [$owner, $workspace] = $this->createOwner();
    $membership = Membership::where('user_id', $owner->id)->first();

    $this->actingAs($owner)->put(route('nudge.members.update', $membership), ['role_id' => roleNamed('Admin')->id])->assertSessionHasErrors('member');
    $this->actingAs($owner)->delete(route('nudge.members.destroy', $membership))->assertSessionHasErrors('member');

    expect($owner->fresh()->membershipFor($workspace)->role->is_owner)->toBeTrue();
});

test('the last-owner check runs inside the change\'s transaction', function () {
    [$owner] = $this->createOwner();
    $membership = Membership::where('user_id', $owner->id)->first();

    // RefreshDatabase wraps every test in a transaction, so step out of it to simulate a caller without one.
    DB::rollBack();

    try {
        expect(fn () => EnsureWorkspaceKeepsAnOwner::check($membership))->toThrow(LogicException::class);
    } finally {
        DB::beginTransaction();
    }
});

test('two owners can each be demoted once, but not both', function () {
    [$first, $workspace] = $this->createOwner();
    $second = $this->addMember($workspace, 'Owner');
    $firstMembership = Membership::where('user_id', $first->id)->first();
    $secondMembership = Membership::where('user_id', $second->id)->first();

    $this->actingAs($first)->put(route('nudge.members.update', $firstMembership), ['role_id' => roleNamed('Admin')->id])->assertSessionHasNoErrors();
    $this->actingAs($second)->put(route('nudge.members.update', $secondMembership), ['role_id' => roleNamed('Admin')->id])->assertSessionHasErrors('member');

    expect($second->fresh()->membershipFor($workspace)->role->is_owner)->toBeTrue();
});

test('memberships from another workspace cannot be changed', function () {
    [$owner] = $this->createOwner('Acme');
    [$otherOwner] = $this->createOwner('Globex');
    $foreignMembership = Membership::where('user_id', $otherOwner->id)->first();

    $this->actingAs($owner)->delete(route('nudge.members.destroy', $foreignMembership))->assertNotFound();
});

test('the members page marks which member is you', function () {
    [$owner, $workspace] = $this->createOwner();
    $editor = $this->addMember($workspace, 'Editor');

    $this->actingAs($owner)->get(route('nudge.members.index'))
        ->assertInertia(fn ($page) => $page
            ->where('members.0.is_you', true)
            ->where('members.1.is_you', false)
            ->where('members.1.email', $editor->email));
});

test('the workspace settings page leaves the app name shared by every page alone', function () {
    [$owner] = $this->createOwner('Acme Nudge');

    $this->actingAs($owner)->get(route('nudge.workspace.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('workspaceName', 'Acme Nudge')
            ->missing('name'));
});

function memberWithCustomRole(object $owner, object $workspace, string $name, array $permissions): User
{
    test()->actingAs($owner)->post(route('nudge.roles.store'), [
        'name' => $name,
        'permissions' => $permissions,
    ])->assertRedirect();

    return test()->addMember($workspace, $name);
}

test('a role manager cannot grant permissions they lack', function () {
    [$owner, $workspace] = $this->createOwner();
    $manager = memberWithCustomRole($owner, $workspace, 'Role Manager', ['roles.manage', 'contacts.view']);

    $this->actingAs($manager)->post(route('nudge.roles.store'), [
        'name' => 'Sneaky',
        'permissions' => ['contacts.view', 'providers.manage'],
    ])->assertSessionHasErrors('permissions');

    $this->actingAs($manager)->put(route('nudge.roles.update', roleNamed('Role Manager')), [
        'name' => 'Role Manager',
        'permissions' => ['roles.manage', 'contacts.view', 'workspace.manage'],
    ])->assertSessionHasErrors('permissions');

    expect(Role::where('name', 'Sneaky')->exists())->toBeFalse()
        ->and(roleNamed('Role Manager')->permissions)->toBe(['roles.manage', 'contacts.view']);
});

test('a role manager cannot edit a role more powerful than their own', function () {
    [$owner, $workspace] = $this->createOwner();
    $manager = memberWithCustomRole($owner, $workspace, 'Role Manager', ['roles.manage', 'contacts.view']);

    $this->actingAs($manager)->put(route('nudge.roles.update', roleNamed('Admin')), [
        'name' => 'Admin',
        'permissions' => ['contacts.view'],
    ])->assertForbidden();
});

test('a member manager cannot hand out a role more powerful than their own', function () {
    Notification::fake();
    [$owner, $workspace] = $this->createOwner();
    $member = memberWithCustomRole($owner, $workspace, 'People', ['members.manage', 'contacts.view']);
    $membership = Membership::where('user_id', $member->id)->first();

    $this->actingAs($member)->put(route('nudge.members.update', $membership), ['role_id' => roleNamed('Admin')->id])->assertForbidden();
    $this->actingAs($member)->post(route('nudge.members.invite'), ['email' => 'x@example.com', 'role_id' => roleNamed('Admin')->id])->assertForbidden();

    expect($membership->fresh()->role->name)->toBe('People');
});

test('removing a member revokes the API tokens they created', function () {
    [$owner, $workspace] = $this->createOwner();
    $admin = $this->addMember($workspace, 'Admin');
    app(CreateApiToken::class)->handle($workspace, $admin, 'x', ['contacts.view']);
    $membership = Membership::where('user_id', $admin->id)->first();

    $this->actingAs($owner)->delete(route('nudge.members.destroy', $membership))->assertRedirect();

    expect(ApiToken::withoutGlobalScopes()->where('created_by', $admin->id)->count())->toBe(0);
});

test('demoting a member narrows their API tokens', function () {
    [$owner, $workspace] = $this->createOwner();
    $admin = $this->addMember($workspace, 'Admin');
    app(CreateApiToken::class)->handle($workspace, $admin, 'x', ['contacts.view', 'contacts.manage', 'providers.manage']);
    $membership = Membership::where('user_id', $admin->id)->first();

    $this->actingAs($owner)->put(route('nudge.members.update', $membership), ['role_id' => roleNamed('Viewer')->id])->assertRedirect();

    expect(ApiToken::withoutGlobalScopes()->where('created_by', $admin->id)->first()->permissions)->toBe(['contacts.view']);
});

test('narrowing a role revokes its members\' invitations for roles above it', function () {
    Notification::fake();
    [$owner, $workspace] = $this->createOwner();
    $lead = memberWithCustomRole($owner, $workspace, 'Lead', ['members.manage', 'campaigns.manage']);
    memberWithCustomRole($owner, $workspace, 'Sender', ['campaigns.manage']);
    memberWithCustomRole($owner, $workspace, 'Helper', ['members.manage']);

    $this->actingAs($lead)->post(route('nudge.members.invite'), ['email' => 'sender@example.com', 'role_id' => roleNamed('Sender')->id])->assertRedirect();
    $this->actingAs($lead)->post(route('nudge.members.invite'), ['email' => 'helper@example.com', 'role_id' => roleNamed('Helper')->id])->assertRedirect();
    expect(Invitation::count())->toBe(2);

    $this->actingAs($owner)->put(route('nudge.roles.update', roleNamed('Lead')), ['name' => 'Lead', 'permissions' => ['members.manage']])
        ->assertRedirect();

    expect(Invitation::withoutGlobalScopes()->pluck('email')->all())->toBe(['helper@example.com']);
});

test('the last owner of a workspace cannot be removed by deleting their user', function () {
    [$original, $workspace] = $this->createOwner();
    $user = $this->addMember($workspace, 'Owner');
    Membership::where('user_id', $original->id)->delete();

    expect(fn () => app(RemoveUserFromWorkspaces::class)->handle($user))->toThrow(ValidationException::class);

    try {
        app(RemoveUserFromWorkspaces::class)->handle($user);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('account');
    }

    expect(Membership::where('user_id', $user->id)->exists())->toBeTrue();
});

test('removing a member revokes the invitations they sent', function () {
    Notification::fake();
    [$owner, $workspace] = $this->createOwner();
    $admin = $this->addMember($workspace, 'Admin');

    $this->actingAs($admin)->post(route('nudge.members.invite'), ['email' => 'x@example.com', 'role_id' => roleNamed('Admin')->id])->assertRedirect();
    expect(Invitation::count())->toBe(1);

    $this->actingAs($owner)->delete(route('nudge.members.destroy', Membership::where('user_id', $admin->id)->first()))->assertRedirect();

    expect(Invitation::withoutGlobalScopes()->count())->toBe(0);
});

test('demoting a member revokes invitations above their new role', function () {
    Notification::fake();
    [$owner, $workspace] = $this->createOwner();
    $admin = $this->addMember($workspace, 'Admin');

    $this->actingAs($admin)->post(route('nudge.members.invite'), ['email' => 'admin@example.com', 'role_id' => roleNamed('Admin')->id]);
    $this->actingAs($admin)->post(route('nudge.members.invite'), ['email' => 'viewer@example.com', 'role_id' => roleNamed('Viewer')->id]);
    expect(Invitation::count())->toBe(2);

    $this->actingAs($owner)->put(route('nudge.members.update', Membership::where('user_id', $admin->id)->first()), ['role_id' => roleNamed('Editor')->id])
        ->assertRedirect();

    expect(Invitation::withoutGlobalScopes()->pluck('email')->all())->toBe(['viewer@example.com']);
});

test('revoking an owner invitation needs the owner', function () {
    Notification::fake();
    [$owner, $workspace] = $this->createOwner();
    $manager = memberWithCustomRole($owner, $workspace, 'People', ['members.manage', 'contacts.view']);

    $this->actingAs($owner)->post(route('nudge.members.invite'), ['email' => 'co-owner@example.com', 'role_id' => roleNamed('Owner')->id]);
    $invitation = Invitation::firstOrFail();

    $this->actingAs($manager)->delete(route('nudge.members.invitations.destroy', $invitation))->assertForbidden();

    expect($invitation->fresh())->not->toBeNull();
});

test('removing permissions from a role narrows its members\' tokens', function () {
    [$owner, $workspace] = $this->createOwner();
    $editor = $this->addMember($workspace, 'Editor');
    app(CreateApiToken::class)->handle($workspace, $editor, 'sync', ['contacts.view', 'contacts.manage']);
    $permissions = array_values(array_diff(roleNamed('Editor')->permissions, ['contacts.manage']));

    $this->actingAs($owner)->put(route('nudge.roles.update', roleNamed('Editor')), ['name' => 'Editor', 'permissions' => $permissions])
        ->assertRedirect();

    expect(ApiToken::withoutGlobalScopes()->where('created_by', $editor->id)->first()->permissions)->toBe(['contacts.view']);
});

test('a new workspace\'s built-in roles have keys and descriptions', function () {
    $this->createOwner();

    $roles = Role::all()->keyBy('key');

    expect($roles->keys()->all())->toEqualCanonicalizing(['owner', 'admin', 'editor', 'viewer'])
        ->and($roles->every(fn (Role $role) => $role->description !== null && $role->description !== ''))->toBeTrue()
        ->and($roles['owner']->is_owner)->toBeTrue()
        ->and($roles['editor']->isBuiltIn())->toBeTrue();
});

test('built-in roles keep their name', function () {
    [$owner] = $this->createOwner();
    $editor = roleNamed('Editor');
    $permissions = array_values(array_diff($editor->permissions, ['contacts.import']));

    $this->actingAs($owner)->put(route('nudge.roles.update', $editor), ['name' => 'Writers', 'permissions' => $editor->permissions])
        ->assertSessionHasErrors('name');

    expect($editor->fresh()->name)->toBe('Editor');

    $this->actingAs($owner)->put(route('nudge.roles.update', $editor), ['name' => 'Editor', 'permissions' => $permissions])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($editor->fresh()->permissions)->not->toContain('contacts.import');
});

test('built-in roles can\'t be deleted', function () {
    [$owner] = $this->createOwner();
    $viewer = roleNamed('Viewer');

    $this->actingAs($owner)->delete(route('nudge.roles.destroy', $viewer))->assertForbidden();

    expect(Role::whereKey($viewer->id)->exists())->toBeTrue();
});

test('custom roles save a description', function () {
    [$owner] = $this->createOwner();

    $this->actingAs($owner)->post(route('nudge.roles.store'), [
        'name' => 'Importer',
        'description' => 'Helps with imports',
        'permissions' => ['contacts.view', 'contacts.import'],
    ])->assertRedirect();

    $role = roleNamed('Importer');

    expect($role->description)->toBe('Helps with imports')
        ->and($role->key)->toBeNull();

    $this->actingAs($owner)->put(route('nudge.roles.update', $role), [
        'name' => 'Importer',
        'description' => 'Runs the CSV imports',
        'permissions' => ['contacts.view', 'contacts.import'],
    ])->assertRedirect();

    expect($role->fresh()->description)->toBe('Runs the CSV imports');
});

test('a built-in role\'s description can\'t be changed', function () {
    [$owner] = $this->createOwner();
    $editor = roleNamed('Editor');
    $description = $editor->description;

    $this->actingAs($owner)->put(route('nudge.roles.update', $editor), [
        'name' => 'Editor',
        'description' => 'Changed',
        'permissions' => $editor->permissions,
    ])->assertRedirect();

    expect($editor->fresh()->description)->toBe($description);
});

test('the roles page lists built-ins first and describes each permission', function () {
    [$owner] = $this->createOwner();
    $this->actingAs($owner)->post(route('nudge.roles.store'), ['name' => 'Aardvark', 'permissions' => ['contacts.view']]);

    $props = $this->actingAs($owner)->get(route('nudge.roles.index'))->assertOk()->inertiaProps();

    expect(array_column($props['roles'], 'key'))->toBe(['owner', 'admin', 'editor', 'viewer', null])
        ->and(collect($props['roles'])->last()['name'])->toBe('Aardvark')
        ->and(collect($props['roles'])->last()['is_builtin'])->toBeFalse()
        ->and($props['permissions'])->toHaveCount(15)
        ->and(collect($props['permissions'])->every(fn (array $permission) => $permission['label'] !== '' && $permission['description'] !== ''))->toBeTrue();
});

test('the roles page passes what the actor can grant', function () {
    [$owner, $workspace] = $this->createOwner();
    $admin = $this->addMember($workspace, 'Admin');
    $adminRole = roleNamed('Admin');
    $adminRole->update(['permissions' => array_values(array_diff($adminRole->permissions, ['campaigns.approve']))]);

    $adminGrantable = $this->actingAs($admin)->get(route('nudge.roles.index'))->inertiaProps('grantable');
    $ownerGrantable = $this->actingAs($owner)->get(route('nudge.roles.index'))->inertiaProps('grantable');

    expect($adminGrantable)->not->toContain('campaigns.approve')->toContain('contacts.view')
        ->and($ownerGrantable)->toHaveCount(15);
});

test('every permission has a label and a description', function () {
    foreach (Permission::cases() as $permission) {
        expect($permission->label())->not->toBe('')
            ->and($permission->description())->not->toBe('');
    }
});
