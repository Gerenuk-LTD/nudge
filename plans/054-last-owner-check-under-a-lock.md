# Plan 054: Two owners can't demote or remove each other at the same moment

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say otherwise. If anything in "STOP conditions" happens, stop
> and report. Don't improvise. When done, update this plan's row in
> `plans/README.md`, unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 6982215..HEAD -- packages/core/src/Actions/Workspaces/EnsureWorkspaceKeepsAnOwner.php packages/core/src/Actions/Workspaces/ChangeMemberRole.php packages/core/src/Actions/Workspaces/RemoveMember.php packages/core/src/Actions/Workspaces/RemoveUserFromWorkspaces.php`
> If any of these changed, compare them with the "Current state" excerpts.
> On a mismatch, STOP.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug / security (integrity)
- **Planned at**: commit `6982215`, 2026-11-15

## Why this matters

A workspace must keep at least one owner. The check is a plain `exists()` query run **before** the change's transaction, with no lock. If the only two owners demote each other (or remove each other, or delete their accounts) at the same moment, both checks see "another owner exists", both changes commit, and the workspace is left with no owner. Nobody can then manage roles, billing or approval, and only someone with database access can fix it.

The fix: lock the workspace row, then check and change inside the same transaction, so the second request waits and then sees the first one's result.

## Current state

**`src/Actions/Workspaces/EnsureWorkspaceKeepsAnOwner.php`** (whole class):

```php
class EnsureWorkspaceKeepsAnOwner
{
    /**
     * Fail when the given owner membership is the workspace's last owner.
     */
    public static function check(Membership $ownerMembership): void
    {
        $otherOwners = Membership::query()
            ->where('workspace_id', $ownerMembership->workspace_id)
            ->whereKeyNot($ownerMembership->id)
            ->whereHas('role', fn ($query) => $query->where('is_owner', true))
            ->exists();

        if (! $otherOwners) {
            throw ValidationException::withMessages(['member' => __('A workspace must keep at least one owner.')]);
        }
    }
}
```

**`src/Actions/Workspaces/ChangeMemberRole.php::handle()`**:

```php
if ($membership->role->is_owner && ! $role->is_owner) {
    EnsureWorkspaceKeepsAnOwner::check($membership);
}

DB::transaction(function () use ($membership, $role): void {
    $membership->update(['role_id' => $role->id]);
    ...
});
```

**`src/Actions/Workspaces/RemoveMember.php::handle()`**: same shape. `check()` runs before `DB::transaction(...)`, which deletes tokens, invitations and the membership.

**`src/Actions/Workspaces/RemoveUserFromWorkspaces.php::handle()`**: refuses account owners, then loops over the user's memberships, calling `check()` for owner memberships (catching its `ValidationException` and rethrowing with an `'account'` message), and **afterwards** runs a separate transaction deleting the user's tokens, invitations and memberships.

**Workspace model**: `Gerenuk\Marketing\Models\Workspace`. Check whether it has global scopes (`grep -n "ScopedBy\|addGlobalScope\|booted" src/Models/Workspace.php`).

**Tests**: `tests/Feature/MembersAndRolesTest.php` covers these actions (grep for "owner"). SQLite, which the tests use, ignores `lockForUpdate()`, so the race itself can't be reproduced in a test. The tests prove the check now runs inside the transaction instead.

## Commands

From `packages/core` (PowerShell: `php`/`composer`; Git Bash: `php.bat`/`composer.bat`), unless they say "root".

| Purpose        | Command                                                 | Expected                                      |
| -------------- | ------------------------------------------------------- | --------------------------------------------- |
| Core tests     | `vendor/bin/pest`                                       | all pass, except the 7 Windows-only SES tests |
| One file       | `vendor/bin/pest tests/Feature/MembersAndRolesTest.php` | all pass                                      |
| PHPStan (root) | `vendor/bin/phpstan analyse --memory-limit=1G`          | 0 errors                                      |
| Pint (root)    | `vendor/bin/pint --test packages/core`                  | passes                                        |

## Scope

**In scope:** the four files above, and `tests/Feature/MembersAndRolesTest.php`.

**Out of scope:** controllers (they call these actions and stay unchanged), role editing (`RoleController` can't change the owner role), account deletion in the host.

## Git workflow

- Branch off `dev`: `advisor/054-last-owner-lock`.
- One commit: `fix(core): check for the last owner under a lock`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Lock and check inside a transaction

Change `check()` to:

```php
/**
 * Fail when the given owner membership is the workspace's last owner. Must run inside the transaction that makes
 * the change: it locks the workspace row, so two owners changing each other at once can't both pass.
 */
public static function check(Membership $ownerMembership): void
{
    if (DB::transactionLevel() === 0) {
        throw new LogicException('EnsureWorkspaceKeepsAnOwner::check() must run inside a transaction.');
    }

    Workspace::query()->whereKey($ownerMembership->workspace_id)->lockForUpdate()->first();

    ...the existing exists() query and exception, unchanged...
}
```

Use `Workspace::withoutGlobalScopes()` instead of `Workspace::query()` if the model has global scopes. `LogicException` is PHP's built-in one.

### Step 2: Move the callers inside their transactions

- `ChangeMemberRole`: move the `if (...) { EnsureWorkspaceKeepsAnOwner::check($membership); }` block to the top of the `DB::transaction` closure.
- `RemoveMember`: the same.
- `RemoveUserFromWorkspaces`: keep the account-owner refusal where it is. Move the membership loading, the loop with its try/catch, and the deletes into **one** `DB::transaction`, loop first, then the deletes. The thrown `ValidationException` rolls the transaction back, which is what we want.

**Verify**: `vendor/bin/pest tests/Feature/MembersAndRolesTest.php` → all pass. Then `vendor/bin/pest` → all pass. If anything calls `check()` outside a transaction, the `LogicException` shows it: find it with `grep -rn "EnsureWorkspaceKeepsAnOwner" src`, and if it's a caller other than these three, STOP.

### Step 3: Tests

In `MembersAndRolesTest.php` add:

1. **"the last-owner check runs inside the change's transaction"**: call `EnsureWorkspaceKeepsAnOwner::check($ownerMembership)` directly, outside a transaction → throws `LogicException`.
2. **"two owners can each be demoted once, but not both"**: two owners. Demote the first through the route the existing tests use → succeeds. Demote the second → refused with the "at least one owner" error, and the second keeps the owner role. (This may duplicate an existing test; if one already covers exactly this, skip it and say which.)

**Verify**: `vendor/bin/pest` → all pass; PHPStan → 0 errors; Pint → passes.

## Done criteria

- [ ] `check()` locks the workspace row and throws outside a transaction
- [ ] All three callers call it inside the same transaction as their change
- [ ] The new tests pass, and the existing member tests pass unchanged
- [ ] Pest, PHPStan and Pint pass
- [ ] `plans/README.md` row updated

## STOP conditions

- The drift check shows changes the excerpts don't cover.
- `check()` has a caller other than the three named here.
- An existing test fails after Step 2.

## Maintenance notes

- The lock only works on MySQL/Postgres. CI's MySQL job runs the same tests but can't prove the race either; this relies on reading the code.
- Anything new that removes an owner (for example an "owner leaves workspace" button) must call `check()` inside its own transaction; the `LogicException` will tell it so.
