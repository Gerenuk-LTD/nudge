<?php

use Gerenuk\Marketing\Models\Invitation;
use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Role;
use Gerenuk\Marketing\Notifications\WorkspaceInvitation;
use Gerenuk\Marketing\Tests\Fixtures\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

function roleNamed(string $name): Role
{
    return Role::where('name', $name)->firstOrFail();
}

test('viewers can list contacts but not create them', function () {
    [, $workspace] = $this->createOwner();
    $viewer = $this->addMember($workspace, 'Viewer');

    $this->actingAs($viewer)->get(route('marketing.contacts.index'))->assertOk();
    $this->actingAs($viewer)->post(route('marketing.contacts.store'), [
        'email' => 'new@example.com', 'status' => 'subscribed',
    ])->assertForbidden();
});

test('editors cannot manage members or API tokens', function () {
    [, $workspace] = $this->createOwner();
    $editor = $this->addMember($workspace, 'Editor');

    $this->actingAs($editor)->get(route('marketing.members.index'))->assertForbidden();
    $this->actingAs($editor)->get(route('marketing.api-tokens.index'))->assertForbidden();
});

test('custom roles grant exactly their permissions', function () {
    [$owner, $workspace] = $this->createOwner();

    $this->actingAs($owner)->post(route('marketing.roles.store'), [
        'name' => 'Importer',
        'permissions' => ['contacts.view', 'contacts.import'],
    ])->assertRedirect();

    $importer = $this->addMember($workspace, 'Importer');

    $this->actingAs($importer)->get(route('marketing.imports.index'))->assertOk();
    $this->actingAs($importer)->get(route('marketing.segments.create'))->assertForbidden();
});

test('the owner role cannot be edited or deleted', function () {
    [$owner] = $this->createOwner();
    $ownerRole = roleNamed('Owner');

    $this->actingAs($owner)->put(route('marketing.roles.update', $ownerRole), ['name' => 'Boss', 'permissions' => []])->assertForbidden();
    $this->actingAs($owner)->delete(route('marketing.roles.destroy', $ownerRole))->assertForbidden();
});

test('a role in use cannot be deleted', function () {
    [$owner, $workspace] = $this->createOwner();
    $this->addMember($workspace, 'Editor');

    $this->actingAs($owner)->delete(route('marketing.roles.destroy', roleNamed('Editor')))->assertSessionHasErrors('role');
});

test('inviting someone emails them a link', function () {
    Notification::fake();
    [$owner] = $this->createOwner();

    $this->actingAs($owner)->post(route('marketing.members.invite'), [
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

    $this->actingAs($owner)->post(route('marketing.members.invite'), ['email' => 'invitee@example.com', 'role_id' => roleNamed('Viewer')->id]);

    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInvitation::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    $this->actingAs($invitee)->post(route('marketing.invitations.accept', $token))->assertRedirect(route('marketing.contacts.index'));

    expect($invitee->membershipFor($workspace)->role->name)->toBe('Viewer')
        ->and(Invitation::withoutGlobalScopes()->count())->toBe(0);
});

test('an invitation cannot be accepted by a different email address', function () {
    Notification::fake();
    [$owner, $workspace] = $this->createOwner();
    $stranger = User::factory()->create(['email' => 'stranger@example.com']);

    $this->actingAs($owner)->post(route('marketing.members.invite'), ['email' => 'invitee@example.com', 'role_id' => roleNamed('Viewer')->id]);

    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInvitation::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    $this->actingAs($stranger)->post(route('marketing.invitations.accept', $token))->assertSessionHasErrors('invitation');

    expect($stranger->belongsToWorkspace($workspace))->toBeFalse();
});

test('admins cannot hand out the owner role', function () {
    Notification::fake();
    [, $workspace] = $this->createOwner();
    $admin = $this->addMember($workspace, 'Admin');
    $editor = $this->addMember($workspace, 'Editor');
    $editorMembership = Membership::where('user_id', $editor->id)->first();

    $this->actingAs($admin)->post(route('marketing.members.invite'), ['email' => 'x@example.com', 'role_id' => roleNamed('Owner')->id])->assertForbidden();
    $this->actingAs($admin)->put(route('marketing.members.update', $editorMembership), ['role_id' => roleNamed('Owner')->id])->assertForbidden();
});

test('the last owner cannot be removed or demoted', function () {
    [$owner, $workspace] = $this->createOwner();
    $membership = Membership::where('user_id', $owner->id)->first();

    $this->actingAs($owner)->put(route('marketing.members.update', $membership), ['role_id' => roleNamed('Admin')->id])->assertSessionHasErrors('member');
    $this->actingAs($owner)->delete(route('marketing.members.destroy', $membership))->assertSessionHasErrors('member');

    expect($owner->fresh()->membershipFor($workspace)->role->is_owner)->toBeTrue();
});

test('memberships from another workspace cannot be changed', function () {
    [$owner] = $this->createOwner('Acme');
    [$otherOwner] = $this->createOwner('Globex');
    $foreignMembership = Membership::where('user_id', $otherOwner->id)->first();

    $this->actingAs($owner)->delete(route('marketing.members.destroy', $foreignMembership))->assertNotFound();
});
