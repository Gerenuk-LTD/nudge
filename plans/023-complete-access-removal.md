# Plan 023: Removing or reducing someone's access really removes it, and members can't create stray workspaces

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat a86033c..HEAD -- packages/core/src/Actions/Workspaces packages/core/src/Http/Controllers/RoleController.php packages/core/src/Http/Controllers/MemberController.php packages/core/src/Http/Controllers/CampaignController.php packages/core/src/Http/Controllers/SendingSettingsController.php packages/core/src/Http/Controllers/WorkspaceController.php packages/core/routes/web.php`
> If plans 019 or 022 landed first, `CampaignController.php` and `routes/web.php` will differ in the places those plans touched; that's fine. Any other mismatch with the excerpts is a STOP.

## Status

- **Priority**: P1
- **Effort**: S–M
- **Risk**: LOW–MED
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `a86033c`, 2026-10-30
- **Product decision**: only account owners can create new workspaces. This is the advisor's recommended default, and the maintainer can override it. The alternative is an install-level setting.

## Why this matters

Plans 006 and 011 revoke a member's API tokens when they're removed or demoted. This audit found five more ways access survives:

1. **Pending invitations survive removal.** An Admin about to be removed can invite a second address they control. After removal they accept within 7 days and are back with Admin rights.
2. **Editing a role doesn't narrow its members' tokens.** Dropping `contacts.manage` from the Editor role leaves every Editor's existing API token able to write contacts.
3. **Broadcast-only actions accept automation campaigns.**
    - `destroy`: anyone with `campaigns.manage` can delete an automation's step campaign by ID, cascading away its recipient history. That history feeds reporting and the new engagement segments.
    - `cancel`, `unschedule` and `pickWinner` (`campaigns.send`) can disrupt a live automation's emails.
4. **Revoking an invitation skips role bounds.** A non-owner with `members.manage` can revoke Owner-role invitations.
5. **Turning off campaign approval needs only `providers.manage`, not an approval permission, and leaves no record.**

Separately, **any member can create a workspace**, even a Viewer or a just-removed member. The route has no permission check. They become its Owner, with providers, sending and API tokens, and the workspace is filed under the _inviter's_ account, because it's taken from `current_workspace_id`. That undercuts invite-only sign-up and misattributes ownership.

## Current state

All paths are relative to `packages/core/`.

**`src/Actions/Workspaces/RemoveMember.php`** (after plan 006):

```php
public function handle(Membership $membership): void
{
    if ($membership->role->is_owner) {
        EnsureWorkspaceKeepsAnOwner::check($membership);
    }

    DB::transaction(function () use ($membership): void {
        // Tokens act for the person who made them, so they go when that person leaves.
        ApiToken::withoutGlobalScopes()
            ->where('workspace_id', $membership->workspace_id)
            ->where('created_by', $membership->user_id)
            ->delete();

        $membership->delete();
    });
}
```

**`src/Actions/Workspaces/ChangeMemberRole.php`**: inside a transaction, it updates `role_id`. Then, if the new role isn't Owner, it narrows that user's tokens:

```php
->each(fn (ApiToken $token) => $token->update(['permissions' => array_values(array_intersect($token->permissions, $role->permissions))]));
```

**`src/Actions/Workspaces/RemoveUserFromWorkspaces.php`** (plan 011): in a transaction, it deletes `ApiToken::withoutGlobalScopes()->where('created_by', $userId)` and that user's memberships.

**`src/Models/Invitation.php`:**

- it's workspace-scoped;
- its fillable fields include `workspace_id`, `role_id`, `invited_by`, `email`, `token_hash` and `expires_at`;
- `role()` is a `belongsTo` with `withoutGlobalScopes()`.

**`src/Models/Role.php`** has `isWithin(Role $actor): bool` (plan 006).

**`src/Http/Controllers/RoleController.php` `update()`:**

```php
abort_if($role->is_owner, 403, __('The owner role always has every permission.'));

$actor = $this->actingRole($request);

abort_unless($role->isWithin($actor), 403);

$role->update($this->validated($request, $actor, $role));
```

**`src/Http/Controllers/MemberController.php`:**

```php
public function revokeInvitation(Invitation $invitation): RedirectResponse
{
    $invitation->delete();

    return to_route('marketing.members.index');
}
```

`ensureCanAssign(Request $request, Role $role)` aborts with 403 unless the role `isWithin` the actor's role.

**`src/Http/Controllers/CampaignController.php`:**

- `destroy(Campaign $campaign)` aborts unless the status is Draft or Cancelled, then deletes. It has no kind check.
- `cancel`, `unschedule` and `pickWinner` only check status.
- `duplicate` already has `abort_if($campaign->kind === CampaignKind::Automation, 404);`. That's the pattern to follow.

**`src/Http/Controllers/SendingSettingsController.php` `update()`:**

```php
$this->currentWorkspace->getOrFail()->update($request->validate([
    'postal_address' => ['required', 'string', 'max:500'],
    'requires_campaign_approval' => ['required', 'boolean'],
]));
```

It sits behind `can:providers.manage` (`routes/web.php` around lines 151-155). The `Permission` enum has `CampaignsApprove = 'campaigns.approve'`. Users have `hasWorkspacePermission(Workspace, Permission)`.

**`src/Http/Controllers/WorkspaceController.php` `store()`:**

```php
$validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

$user = $this->user($request);

// A new workspace joins the account of the one the user is in, so billing stays together.
$account = Workspace::find($user->currentWorkspaceId())?->account;

$workspace = $createWorkspace->handle($user, $validated['name'], $account);
```

`routes/web.php` (around lines 26-27) registers `workspaces/create` and `POST workspaces` outside `marketing.workspace` with no permission check, because a brand-new user with no workspace must be able to create their first one. `Account` has `owner_id`. `CreateWorkspace::handle(MarketingUser $owner, string $name, ?Account $account = null)` creates a new account when it gets null.

**The workspace creation page.** `resources/js/pages/marketing/workspaces/create.tsx`, and the workspace switcher component (`components/workspace-switcher.tsx`), probably has a "New workspace" entry. Check whether it should hide for non-owners (see Step 5).

**Tests.** `tests/Feature/MembersAndRolesTest.php` has the `roleNamed()`, `createOwner()`, `addMember()` and `memberWithCustomRole()` helpers, and invites through `route('marketing.members.invite')`. `tests/Feature/WorkspaceTest.php` covers workspace creation; read it. `tests/Feature/AutomationTest.php` has `liveAutomation(...)`, which creates automation step campaigns.

## Commands you will need

| Purpose                | Command                                                 | Expected          |
| ---------------------- | ------------------------------------------------------- | ----------------- |
| Core tests             | `cd packages/core && vendor/bin/pest --compact`         | all pass          |
| Host tests             | `php artisan test --compact`                            | all pass          |
| PHPStan / Pint         | as usual                                                | 0 errors / exit 0 |
| Frontend (Step 5 only) | `npm run types:check && npm run check && npm run build` | exit 0            |

## Scope

**In scope:**

- `src/Actions/Workspaces/RemoveMember.php`, `ChangeMemberRole.php` and `RemoveUserFromWorkspaces.php`
- `src/Http/Controllers/RoleController.php` (`update`)
- `src/Http/Controllers/MemberController.php` (`revokeInvitation`)
- `src/Http/Controllers/CampaignController.php` (kind checks in `destroy`, `cancel`, `unschedule` and `pickWinner`)
- `src/Http/Controllers/SendingSettingsController.php`
- `src/Http/Controllers/WorkspaceController.php`
- `resources/js/pages/marketing/workspace/sending.tsx` (disable the approval toggle without permission)
- `resources/js/components/workspace-switcher.tsx` and `pages/marketing/workspaces/create.tsx` (hide or explain for non-owners)
- tests

**Out of scope:**

- An approval workflow for automations. That's the open product question.
- Locking the last-owner race. It's low priority.
- Billing or account transfer.

## Git workflow

- Branch: `advisor/023-access-removal`.
- Make one commit per step, prefixed `fix(security): ...`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Invitations go with their inviter's access

- **In `RemoveMember`'s transaction**, also run `Invitation::withoutGlobalScopes()->where('workspace_id', $membership->workspace_id)->where('invited_by', $membership->user_id)->delete();`.
- **In `RemoveUserFromWorkspaces`'s transaction**, delete `Invitation::withoutGlobalScopes()->where('invited_by', $userId)`.
- **In `ChangeMemberRole`**, when the new role isn't Owner, delete this user's pending invitations in this workspace whose role is no longer within the new role. Load them with `->with('role')`, filter with `! $invitation->role->isWithin($role)`, and delete the ones that fail.
- **In `MemberController::revokeInvitation`**, add `Request $request` and call `$this->ensureCanAssign($request, $invitation->role);` before deleting.

**Verify**: MembersAndRolesTest passes.

### Step 2: Editing a role narrows its members' tokens

In `RoleController::update`, wrap the update in a `DB::transaction`. After updating, find the user IDs of every member holding this role (`$role->memberships()->pluck('user_id')`). Narrow each of their tokens in this workspace to `array_values(array_intersect($token->permissions, $role->permissions))`, the same way `ChangeMemberRole` does. If the narrowing loop would otherwise be duplicated, extract it into a small static helper, for example `ApiToken::narrowForUser(int $workspaceId, int $userId, array $permissions)`, and use it from both places.

**Verify**: MembersAndRolesTest passes.

### Step 3: Broadcast-only actions refuse automation campaigns

Add `abort_if($campaign->kind === CampaignKind::Automation, 404);` as the first line of `destroy`, `cancel`, `unschedule` and `pickWinner`.

**Verify**: SendingTest, AbTestTest and AutomationTest pass.

### Step 4: Approval can only be switched off by someone who can approve

In `SendingSettingsController::update`, if `requires_campaign_approval` is changing and the user lacks `Permission::CampaignsApprove` in this workspace, throw `ValidationException::withMessages(['requires_campaign_approval' => __('Only people who can approve campaigns can change this.')])`.

When it does change, record it. The `events` table needs a contact, so don't use it here. Instead, flash a toast naming the change, and write `Log::notice('Campaign approval setting changed', ['workspace' => ..., 'user' => ..., 'to' => ...])` with **no personal data beyond the user ID**.

In `edit()`, pass `canChangeApproval`. In `sending.tsx`, disable the approval toggle when it's false and show a one-line hint.

**Verify**: core suite passes; `npm run types:check && npm run check` → exit 0.

### Step 5: Only account owners create additional workspaces

In `WorkspaceController::store`:

- if the user has **no** workspaces yet (`! $user->memberships()->exists()`), keep today's behaviour: their first workspace, on a new account;
- otherwise, look up the account the user **owns** (`Account::query()->where('owner_id', $user->getAuthIdentifier())->first()`):
    - if there is none, abort with 403 and the message "Only account owners can create workspaces.";
    - if there is one, create the workspace on that account, never on `current_workspace_id`'s account.

Apply the same check in `create()`, so non-owners get the 403 page and not a form they can't submit. Update the comment above the account lookup.

In the frontend, find where "New workspace" or "Create workspace" is offered, probably `workspace-switcher.tsx`, and hide it unless the user can create one. Pass a shared or page prop if needed. Prefer a prop from the existing workspace share in `HandleInertiaRequests`, or core's `SetCurrentWorkspace` share. Read how the switcher gets its data, and follow it.

**Verify**: core and host suites pass; frontend checks pass.

## Test plan

Put these in `MembersAndRolesTest.php` unless noted.

1. `test('removing a member revokes the invitations they sent')`: an Admin invites `x@example.com`, the owner removes the Admin, and the invitation is gone.
2. `test('demoting a member revokes invitations above their new role')`: an Admin invites with the Admin role, the owner demotes them to Editor, and the invitation is gone. An invitation with the Viewer role from the same Admin survives.
3. `test('revoking an owner invitation needs the owner')`: a custom member manager gets 403 revoking an Owner-role invitation.
4. `test('removing permissions from a role narrows its members\' tokens')`: an Editor holds a token with `contacts.view` and `contacts.manage`. The owner removes `contacts.manage` from the Editor role, and the token now has only `contacts.view`.
5. In `AutomationTest.php`, `test('automation emails cannot be deleted or cancelled as campaigns')`: for a live automation's step campaign, `delete(route('marketing.campaigns.destroy', $stepCampaign))` gives 404, and so does `post(...cancel...)`.
6. In `SendingTest.php`, `test('only approvers can switch campaign approval')`:
    - a custom role with `providers.manage` but without `campaigns.approve` toggling it gives an error, and the value is unchanged;
    - the Owner can toggle it.
7. In `WorkspaceTest.php`:
    - `test('a member who doesn\'t own an account cannot create a workspace')`: an Editor gets 403;
    - `test('a new user creates their first workspace on a new account')` (likely exists already; keep it passing);
    - `test('an account owner\'s new workspace joins their own account')`: the owner, while viewing a workspace on someone else's account, creates a workspace, and it's on their own account.

**Revert checks**, reporting each result:

- remove Step 1's invitation delete and confirm test 1 fails;
- remove Step 2's narrowing and confirm test 4 fails;
- remove Step 5's check and confirm test 7a fails.

## Done criteria

- [ ] `grep -n "invited_by" packages/core/src/Actions/Workspaces/RemoveMember.php packages/core/src/Actions/Workspaces/RemoveUserFromWorkspaces.php` → matches in both
- [ ] `grep -c "CampaignKind::Automation" packages/core/src/Http/Controllers/CampaignController.php` → at least 6 (`duplicate`, `update`/`approve`, plus the 4 new checks)
- [ ] Core and host suites pass with the new tests; PHPStan reports 0 errors; Pint is clean; frontend checks pass
- [ ] Only in-scope files changed

## STOP conditions

- An excerpt doesn't match.
- Existing tests rely on a member creating a second workspace. Report which ones; that's the product change this plan makes.
- The workspace switcher's "new workspace" entry is in the host app (`resources/js`), not core. That's still fine to change; STOP only if it's shared in a way that would need a new host-to-core contract prop.

## Maintenance notes

- Any new path that grants access, such as share links or SSO, must also be revoked by `RemoveMember`, `ChangeMemberRole` and `RemoveUserFromWorkspaces`.
- If billing is added, account ownership becomes load-bearing. Workspace creation should then check the account's plan as well.
