<?php

use Gerenuk\Nudge\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Nudge\Contracts\AccountCreationPolicy;
use Gerenuk\Nudge\Contracts\NudgeUser;
use Gerenuk\Nudge\Events\WorkspaceCreated;
use Gerenuk\Nudge\Models\Account;
use Gerenuk\Nudge\Models\Membership;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Models\Role;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Sending\ProviderType;
use Gerenuk\Nudge\Tests\Fixtures\User;
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
        ->get(route('nudge.contacts.index'))
        ->assertRedirect(route('nudge.workspaces.create'));
});

test('a user can create a workspace and lands in it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('nudge.workspaces.store'), ['name' => 'Acme'])
        ->assertRedirect(route('nudge.home'));

    expect($user->fresh()->currentWorkspace->name)->toBe('Acme');
});

test('a second workspace joins the same account', function () {
    [$owner, $acme] = $this->createOwner('Acme');

    $this->actingAs($owner)->post(route('nudge.workspaces.store'), ['name' => 'Globex']);

    $globex = $owner->fresh()->currentWorkspace;

    expect($globex->name)->toBe('Globex')
        ->and($globex->account_id)->toBe($acme->account_id);
});

test('users can switch between their workspaces but not into others', function () {
    [$owner, $acme] = $this->createOwner('Acme');
    [, $globex] = $this->createOwner('Globex');

    $this->actingAs($owner)
        ->put(route('nudge.current-workspace.update'), ['workspace_id' => $globex->id])
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

test('pages flag the most pressing reason campaigns cannot go out', function () {
    [$owner, $workspace] = $this->createOwner();
    $sendingIssue = fn () => $this->actingAs($owner)->get(route('nudge.contacts.index'))->inertiaProps('workspace.sendingIssue');

    $withoutProvider = $sendingIssue();
    Provider::create(['name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 1, 'credentials' => ['host' => 'h', 'port' => '25', 'encryption' => 'starttls']]);
    $withoutAddress = $sendingIssue();
    $workspace->update(['postal_address' => "1 High Street\nLondon"]);
    $healthy = $sendingIssue();
    $workspace->update(['sending_paused_at' => now()]);
    $paused = $sendingIssue();

    expect([$withoutProvider, $withoutAddress, $healthy, $paused])->toBe(['no_provider', 'no_postal_address', null, 'paused']);
});

test('pages share the signed-in user\'s role in the current workspace', function () {
    [$owner] = $this->createOwner();

    $response = $this->actingAs($owner)->get(route('nudge.contacts.index'));

    expect($response->inertiaProps('workspace.role'))->toBe('Owner');
});

test('a member who doesn\'t own an account cannot create a workspace', function () {
    [, $acme] = $this->createOwner('Acme');
    $editor = $this->addMember($acme, 'Editor');

    $this->actingAs($editor)->get(route('nudge.workspaces.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('allowed', false));
    $this->actingAs($editor)->post(route('nudge.workspaces.store'), ['name' => 'Side project'])->assertForbidden();
    $this->actingAs($editor)->get(route('nudge.home'))
        ->assertInertia(fn ($page) => $page->where('workspace.canCreateWorkspace', false));

    expect($editor->fresh()->workspaces()->count())->toBe(1);
});

test('an account owner\'s new workspace joins their own account', function () {
    [$owner, $own] = $this->createOwner('Own');
    [, $other] = $this->createOwner('Other');
    Membership::create([
        'workspace_id' => $other->id,
        'user_id' => $owner->id,
        'role_id' => Role::withoutGlobalScopes()->where('workspace_id', $other->id)->where('name', 'Editor')->firstOrFail()->id,
    ]);
    $owner->switchWorkspace($other);

    $this->actingAs($owner)->get(route('nudge.home'))
        ->assertInertia(fn ($page) => $page->where('workspace.current.id', $other->id)->where('workspace.canCreateWorkspace', true));
    $this->actingAs($owner)->post(route('nudge.workspaces.store'), ['name' => 'Third'])->assertRedirect();

    $third = $owner->fresh()->currentWorkspace;

    expect($third->name)->toBe('Third')
        ->and($third->account_id)->toBe($own->account_id)
        ->and($third->account_id)->not->toBe($other->account_id);
});

/**
 * Binds a policy that refuses every user a new account.
 */
function refuseAccountCreation(): void
{
    app()->instance(AccountCreationPolicy::class, new class implements AccountCreationPolicy
    {
        public function allows(NudgeUser $user): bool
        {
            return false;
        }
    });
}

test('a user the account policy refuses sees the page without the form', function () {
    refuseAccountCreation();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('nudge.workspaces.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('allowed', false)->has('message'));
});

test('a user the account policy refuses cannot create a workspace', function () {
    refuseAccountCreation();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('nudge.workspaces.store'), ['name' => 'Acme'])->assertForbidden();

    expect(Workspace::withoutGlobalScopes()->count())->toBe(0);
});

test('an account owner without a membership can still create a workspace when the policy refuses', function () {
    refuseAccountCreation();
    $user = User::factory()->create();
    $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

    $this->actingAs($user)->post(route('nudge.workspaces.store'), ['name' => 'Acme'])->assertRedirect(route('nudge.home'));

    expect($user->fresh()->currentWorkspace->account_id)->toBe($account->id);
});
