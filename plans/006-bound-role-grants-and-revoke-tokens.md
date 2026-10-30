# Plan 006: People can only grant permissions they hold, and API tokens die with their creator's access

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 08eff55..HEAD -- packages/core/src/Http/Controllers/RoleController.php packages/core/src/Http/Controllers/MemberController.php packages/core/src/Actions/Workspaces packages/core/src/Models/Role.php packages/core/tests/Feature/MembersAndRolesTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: plans/001-make-ci-a-real-gate.md
- **Category**: security
- **Planned at**: commit `08eff55`, 2026-10-29

## Why this matters

Workspaces have roles. Custom roles can be given `roles.manage` or `members.manage` without everything else. Today a member with `roles.manage` can add **any** permission to any non-owner role, including their own. A member with `members.manage` can give anyone, including themselves, the built-in "Admin" role, which has every permission. So a narrow custom role becomes full admin in two clicks. The only existing guard protects the Owner role.

API tokens already follow the right rule: a token can only carry permissions its creator holds (`ApiTokenController::grantablePermissions`). But when a member is removed or demoted, the tokens they created keep working with their old permissions until expiry (up to 10 years, or never). A former employee keeps API access to contact data.

## Current state

**Project shape.** The product is `packages/core` (namespace `Gerenuk\Marketing`, Laravel 13).

**Permissions.** These are `packages/core/src/Enums/Permission.php` cases (`contacts.view`, `contacts.manage`, …, `members.manage`, `roles.manage`, `api_tokens.manage`, `workspace.manage`); `Permission::values()` lists them all. The default roles are created in `packages/core/src/Actions/Workspaces/CreateWorkspace.php`:

- Owner: everything, `is_owner = true`
- Admin: everything, not owner
- Editor
- Viewer

`packages/core/src/Models/Role.php`:

```php
/**
 * A named bundle of permissions within a workspace.
 * ...
 * @property list<string> $permissions
 * @property bool $is_owner
 */
...
public function grants(Permission $permission): bool
{
    return $this->is_owner || in_array($permission->value, $this->permissions, true);
}
```

**The acting user's role.** `$this->user($request)` (from the base `Controller`) returns the signed-in `MarketingUser`. `->membershipFor($workspace)?->role` gives their `Role` in a workspace (`packages/core/src/Models/Concerns/HasWorkspaces.php:51-61`). The current workspace comes from the injected `CurrentWorkspace` (`$this->currentWorkspace->getOrFail()`).

`packages/core/src/Http/Controllers/RoleController.php`:

- `store()` calls `Role::create($this->validated($request))`.
- `update(Request $request, Role $role)` aborts 403 if `$role->is_owner`, then calls `$role->update($this->validated($request, $role))`.
- The private `validated()` (lines ~68-81) validates `name` and `permissions.*` against `Rule::enum(Permission::class)` and returns `['name' => ..., 'permissions' => array_values(array_unique(...))]`.

`packages/core/src/Http/Controllers/MemberController.php`:

- `invite()` calls `$this->ensureCanAssign($request, $role)`.
- `update()` calls `ensureCanAssign` for the new role and the member's current role.
- `destroy()` calls it for the member's current role.

The guard itself:

```php
/**
 * Only owners can give out, take away or change the owner role.
 */
private function ensureCanAssign(Request $request, Role $role): void
{
    if ($role->is_owner) {
        abort_unless($this->user($request)->membershipFor($this->currentWorkspace->getOrFail())?->role->is_owner, 403);
    }
}
```

`packages/core/src/Actions/Workspaces/RemoveMember.php`:

```php
public function handle(Membership $membership): void
{
    if ($membership->role->is_owner) {
        EnsureWorkspaceKeepsAnOwner::check($membership);
    }

    $membership->delete();
}
```

`packages/core/src/Actions/Workspaces/ChangeMemberRole.php`:

```php
public function handle(Membership $membership, Role $role): void
{
    if ($membership->role->is_owner && ! $role->is_owner) {
        EnsureWorkspaceKeepsAnOwner::check($membership);
    }

    $membership->update(['role_id' => $role->id]);
}
```

`packages/core/src/Models/ApiToken.php` is workspace-scoped (`BelongsToWorkspace`). Its fields include `created_by` (the creator's user id, from `CreateApiToken`, `'created_by' => $creator->getAuthIdentifier()`) and `permissions` (`list<string>`, array cast). Query across scopes with `ApiToken::withoutGlobalScopes()->where('workspace_id', ...)`.

**Tests.** `packages/core/tests/Feature/MembersAndRolesTest.php`:

- has a `roleNamed(string $name): Role` helper;
- uses `$this->createOwner()` → `[$owner, $workspace]` and `$this->addMember($workspace, 'Admin'|'Editor'|'Viewer'|<custom role name>)` → `User`;
- posts to `route('marketing.roles.store')` / `roles.update` / `members.update` / `members.destroy` / `members.invite`;
- exemplar: `test('admins cannot hand out the owner role', ...)` at lines ~117-126.

`packages/core/tests/Feature/ApiTest.php` has `apiToken($workspace, $user, $permissions)`, which returns a plain token and is usable with `$this->withToken($token)->getJson(route('marketing.api.me'))`.

## Commands you will need

| Purpose       | Command                                                                               | Expected on success |
| ------------- | ------------------------------------------------------------------------------------- | ------------------- |
| Members tests | `cd packages/core && vendor/bin/pest --compact tests/Feature/MembersAndRolesTest.php` | all pass            |
| Core suite    | `cd packages/core && vendor/bin/pest --compact`                                       | all pass            |
| PHPStan       | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`                          | `[OK] No errors`    |
| Pint          | `vendor/bin/pint --dirty --format agent`                                              | exit 0              |

## Scope

**In scope**:

- `packages/core/src/Models/Role.php` (add one method)
- `packages/core/src/Http/Controllers/RoleController.php`
- `packages/core/src/Http/Controllers/MemberController.php` (`ensureCanAssign` only)
- `packages/core/src/Actions/Workspaces/RemoveMember.php`
- `packages/core/src/Actions/Workspaces/ChangeMemberRole.php`
- `packages/core/tests/Feature/MembersAndRolesTest.php` (add tests)

**Out of scope** (do NOT touch):

- `ApiTokenController` (already correct) and `AuthenticateApiToken` / `EnsureTokenCan` middleware. Tokens are fixed at the source (removal and demotion), not checked per request.
- Default role definitions in `CreateWorkspace`.
- Invitations already sent (they carry a role; `invite()` is covered by the new guard going forward).
- Frontend role and member pages. The server returns 403 / validation errors and existing pages already show those.

## Git workflow

- Branch: `advisor/006-bounded-grants` from `dev`.
- Commit message example: `fix(security): bound role grants by the actor's permissions and revoke tokens on removal`
- End commit messages with: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- Do NOT push or open a PR unless instructed.

## Steps

### Step 1: Add `Role::isWithin()`

In `Role`:

```php
/**
 * Whether someone holding $actor could hand out this role: owners can hand out anything, everyone else
 * only roles whose permissions they hold themselves, and never the owner role.
 */
public function isWithin(Role $actor): bool
{
    if ($actor->is_owner) {
        return true;
    }

    return ! $this->is_owner && array_diff($this->permissions, $actor->permissions) === [];
}
```

**Verify**: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress packages/core/src/Models/Role.php` → no errors

### Step 2: Bound `MemberController::ensureCanAssign`

Replace the body of `ensureCanAssign` with:

```php
$actor = $this->user($request)->membershipFor($this->currentWorkspace->getOrFail())?->role;

abort_unless($actor !== null && $role->isWithin($actor), 403);
```

Update its PHPDoc to: "People can only give out, take away or change roles whose permissions they hold themselves; only owners can touch the owner role."

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/MembersAndRolesTest.php` → all pass (the existing "admins cannot hand out the owner role" test must still pass)

### Step 3: Bound `RoleController`

1. Inject `Request` into `store`/`update` (already present) and compute `$actor` the same way as Step 2. Abort 403 if it is null.
2. In `update()`, after the existing owner abort, add `abort_unless($role->isWithin($actor), 403);`. You cannot edit a role more powerful than your own.
3. In `validated()` (or straight after calling it, in both `store` and `update`), when `! $actor->is_owner`, compute `array_diff($permissions, $actor->permissions)`. If it isn't empty, throw `ValidationException::withMessages(['permissions' => __('You can only give out permissions you have yourself.')])`. `ValidationException` is already imported in this file.
4. In `destroy()`, also `abort_unless($role->isWithin($actor), 403)`. `destroy` currently takes only `Role $role`; add `Request $request` as the first parameter.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/MembersAndRolesTest.php` → all pass

### Step 4: Revoke or narrow the member's API tokens

In `RemoveMember::handle`, after the owner check and before `$membership->delete()`:

```php
// Tokens act for the person who made them, so they go when that person leaves.
ApiToken::withoutGlobalScopes()
    ->where('workspace_id', $membership->workspace_id)
    ->where('created_by', $membership->user_id)
    ->delete();
```

In `ChangeMemberRole::handle`, after updating the membership, when the new role is not the owner role, narrow each of that user's tokens in the workspace to the new role's permissions:

```php
if (! $role->is_owner) {
    ApiToken::withoutGlobalScopes()
        ->where('workspace_id', $membership->workspace_id)
        ->where('created_by', $membership->user_id)
        ->get()
        ->each(fn (ApiToken $token) => $token->update(['permissions' => array_values(array_intersect($token->permissions, $role->permissions))]));
}
```

Wrap both actions' bodies in `DB::transaction(...)` only if sibling actions in `packages/core/src/Actions/Workspaces/` do so for multi-write operations; otherwise leave them unwrapped.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/MembersAndRolesTest.php tests/Feature/ApiTest.php` → all pass

### Step 5: Tests

Add the tests below.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/MembersAndRolesTest.php` → all pass, including the 5 new tests

## Test plan

Add to `packages/core/tests/Feature/MembersAndRolesTest.php`. To set up a custom role, have the owner POST to `roles.store`, as the existing test `custom roles grant exactly their permissions` does, then use `$this->addMember($workspace, '<role name>')`.

1. `a role manager cannot grant permissions they lack`. Create a custom role "Role Manager" with `['roles.manage', 'contacts.view']`. As that member, POST `roles.store` with `permissions => ['contacts.view', 'providers.manage']`. Expect `assertSessionHasErrors('permissions')`. Then PUT `roles.update` on their **own** role adding `'workspace.manage'`, and expect `assertSessionHasErrors('permissions')`.
2. `a role manager cannot edit a role more powerful than their own`. Same member, PUT `roles.update` on `roleNamed('Admin')`. Expect 403.
3. `a member manager cannot hand out a role more powerful than their own`. Custom role "People" with `['members.manage', 'contacts.view']`. As that member, PUT `members.update` on their own membership with `role_id => roleNamed('Admin')->id`. Expect 403 and their role unchanged. Also POST `members.invite` with the Admin role and expect 403.
4. `removing a member revokes the API tokens they created`. Add an Admin member and create a token for them with `app(CreateApiToken::class)->handle($workspace, $admin, 'x', ['contacts.view'])`. The owner DELETEs `members.destroy` for that membership. Expect `ApiToken::withoutGlobalScopes()->where('created_by', $admin->id)->count() === 0`.
5. `demoting a member narrows their API tokens`. Add an Admin member and create a token with `['contacts.view', 'contacts.manage', 'providers.manage']`. The owner PUTs `members.update` with `roleNamed('Viewer')->id`. Expect the token's permissions are now exactly `['contacts.view']`. Viewer has `contacts.view`, `campaigns.view` and `automations.view`; check `CreateWorkspace::defaultRoles()` and adjust the expectation to the true intersection.

## Done criteria

- [ ] `grep -n "function isWithin" packages/core/src/Models/Role.php` → match
- [ ] `grep -n "created_by" packages/core/src/Actions/Workspaces/RemoveMember.php packages/core/src/Actions/Workspaces/ChangeMemberRole.php` → a match in each
- [ ] `cd packages/core && vendor/bin/pest --compact` passes, including 5 new tests
- [ ] PHPStan clean; `vendor/bin/pint --test` exits 0
- [ ] `git status` shows only in-scope files changed

## STOP conditions

- An existing test relies on an Admin creating or editing roles with permissions it lacks. Admin has every permission, so this shouldn't happen; if it does, report it.
- `ApiToken.created_by` turns out not to hold the user id (check `CreateApiToken`). Report it.
- The members or roles React pages send `permissions` in a shape that now fails validation for owners (owner flows must be unchanged). Run the full core suite; any failure in `PagesTest.php` → report.

## Maintenance notes

- Any new permission-granting surface (bulk role import, an API for roles) must use `Role::isWithin` or the same subset check. Reviewers should look for this.
- Tokens are narrowed only on role _change_. If a role's own permissions are edited, tokens created by its members keep their old permissions. Closing that needs a check per request in `EnsureTokenCan` (does the creator still hold this permission?), which costs a query per API call. Deferred, but worth doing if roles are edited often.
- Self-demotion is allowed; the existing last-owner guard still applies.
