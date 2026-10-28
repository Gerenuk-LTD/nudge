<?php

use Gerenuk\Marketing\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Marketing\Events\WorkspaceCreated;
use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Role;
use Gerenuk\Marketing\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;

test('creating a workspace gives it default roles and makes the creator owner', function () {
    Event::fake([WorkspaceCreated::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspace::class)->handle($user, 'Acme');

    $this->useWorkspace($workspace);

    expect(Role::orderBy('id')->pluck('name')->all())->toBe(['Owner', 'Admin', 'Editor', 'Viewer'])
        ->and($user->fresh()->current_workspace_id)->toBe($workspace->id)
        ->and($user->membershipFor($workspace)->role->is_owner)->toBeTrue();

    Event::assertDispatched(WorkspaceCreated::class, fn ($event) => $event->workspace->is($workspace));
});

test('a user without a workspace is sent to create one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('marketing.contacts.index'))
        ->assertRedirect(route('marketing.workspaces.create'));
});

test('a user can create a workspace and lands in it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('marketing.workspaces.store'), ['name' => 'Acme'])
        ->assertRedirect(route('marketing.contacts.index'));

    expect($user->fresh()->currentWorkspace->name)->toBe('Acme');
});

test('a second workspace joins the same account', function () {
    [$owner, $acme] = $this->createOwner('Acme');

    $this->actingAs($owner)->post(route('marketing.workspaces.store'), ['name' => 'Globex']);

    $globex = $owner->fresh()->currentWorkspace;

    expect($globex->name)->toBe('Globex')
        ->and($globex->account_id)->toBe($acme->account_id);
});

test('users can switch between their workspaces but not into others', function () {
    [$owner, $acme] = $this->createOwner('Acme');
    [, $globex] = $this->createOwner('Globex');

    $this->actingAs($owner)
        ->put(route('marketing.current-workspace.update'), ['workspace_id' => $globex->id])
        ->assertForbidden();

    expect($owner->fresh()->current_workspace_id)->toBe($acme->id);
});

test('a removed member falls back to another workspace they belong to', function () {
    [$owner, $acme] = $this->createOwner('Acme');
    [, $globex] = $this->createOwner('Globex');
    $member = $this->addMember($acme, 'Editor');
    Membership::create([
        'workspace_id' => $globex->id,
        'user_id' => $member->id,
        'role_id' => Role::withoutGlobalScopes()->where('workspace_id', $globex->id)->where('name', 'Viewer')->value('id'),
    ]);

    $member->memberships()->where('workspace_id', $acme->id)->delete();

    expect($member->fresh()->resolveCurrentWorkspace()->is($globex))->toBeTrue();
});
