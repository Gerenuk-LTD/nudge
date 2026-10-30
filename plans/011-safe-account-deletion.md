# Plan 011: Deleting an account either works cleanly or explains why it can't, instead of a 500

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat f7bb714..HEAD -- app/Http/Controllers/Settings/ProfileController.php resources/js/components/delete-user.tsx tests/Feature/Settings/ProfileUpdateTest.php packages/core/src/Actions/Workspaces packages/core/database/migrations/2026_10_28_000001_create_tenancy_tables.php`
> Expected: no output. If plan 009 has landed, `ProfileController::update` will differ, which is fine because this plan only touches `destroy`. Any other mismatch with the excerpts is a STOP.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: bug + security
- **Planned at**: commit `f7bb714`, 2026-10-30
- **Product decision (made by the maintainer, 2026-10-30)**: block deletion while the user owns workspaces. Don't delete workspaces automatically.

## Why this matters

Every user who creates a workspace becomes the owner of an `accounts` row. `accounts.owner_id` uses `restrictOnDelete`. So for essentially every real customer, "Delete account" throws a foreign-key error and returns a 500. Worse, `ProfileController::destroy` logs the user out _before_ the failing delete, so they're signed out and the account still exists.

Users who don't own an account can be deleted, but their `memberships` rows are removed by a database cascade. That skips the core's rules:

- **Last-owner check:** a workspace can end up with no owner.
- **API token revocation:** tokens they created keep working, because `api_tokens.created_by` is set null, not deleted.

After this plan:

- a user who owns an account, or is a workspace's last owner, gets a clear validation message and stays signed in;
- anyone else is removed from their workspaces through the core's rules (tokens revoked) and then deleted, in one transaction, before logout.

## Current state

**Schema** (`packages/core/database/migrations/2026_10_28_000001_create_tenancy_tables.php`):

- `accounts.owner_id`: `foreignId('owner_id')->constrained('users')->restrictOnDelete()` (line 17)
- `memberships.user_id`: `constrained()->cascadeOnDelete()` (line 42)
- `api_tokens.created_by`: `nullOnDelete()` (around line 70)

**Core models.**

- `Gerenuk\Marketing\Models\Account` has `#[Fillable(['name', 'owner_id'])]` and no workspace scope.
- `Membership` has no workspace scope. Its `role` relation has `is_owner`.
- `ApiToken` is workspace-scoped (`BelongsToWorkspace`), so use `ApiToken::withoutGlobalScopes()`.

`packages/core/src/Actions/Workspaces/EnsureWorkspaceKeepsAnOwner.php`:

```php
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
```

`packages/core/src/Actions/Workspaces/RemoveMember.php` is the pattern to copy: a class with `handle()`, a transaction, and token deletion by `created_by`.

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

`Gerenuk\Marketing\Contracts\MarketingUser` is the host user contract. It has `getAuthIdentifier()`, and `HasWorkspaces` provides `memberships()`.

**Host.** `app/Http/Controllers/Settings/ProfileController.php` `destroy`:

```php
public function destroy(ProfileDeleteRequest $request): RedirectResponse
{
    $user = $request->user();

    Auth::logout();

    $user->delete();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
}
```

`resources/js/components/delete-user.tsx` renders only `<InputError message={errors.password} />` inside its `<Form>` render prop (`{({ resetAndClearErrors, processing, errors }) => (...)`).

`tests/Feature/Settings/ProfileUpdateTest.php` has `test('user can delete their account')`, which deletes a user with no workspace and expects `assertRedirect(route('home'))` and `assertGuest()`. The same file uses `app(CreateWorkspace::class)->handle($user, 'Acme')` to give a user a workspace.

**Core boundary.** Core must not import host code. The host may call core actions.

## Commands you will need

Run these from the repo root.

| Purpose        | Command                                                                   | Expected                  |
| -------------- | ------------------------------------------------------------------------- | ------------------------- |
| Profile tests  | `php artisan test --compact tests/Feature/Settings/ProfileUpdateTest.php` | all pass                  |
| Host suite     | `php artisan test --compact`                                              | all pass                  |
| Core suite     | `cd packages/core && vendor/bin/pest --compact`                           | all pass                  |
| PHPStan        | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`              | 0 errors in touched files |
| Pint           | `vendor/bin/pint --dirty --format agent`, then `vendor/bin/pint --test`   | exit 0                    |
| Types and lint | `npm run types:check && npm run check`                                    | exit 0                    |

## Scope

**In scope:**

- `packages/core/src/Actions/Workspaces/RemoveUserFromWorkspaces.php` (new)
- `packages/core/tests/Feature/MembersAndRolesTest.php` (add tests)
- `app/Http/Controllers/Settings/ProfileController.php` (`destroy` only)
- `resources/js/components/delete-user.tsx` (show `errors.account`)
- `tests/Feature/Settings/ProfileUpdateTest.php` (add tests)

**Out of scope:**

- Migrations. Don't change any foreign key.
- Adding workspace deletion or account transfer. That's a separate feature; see Maintenance notes.
- `ProfileController::update`, which plan 009 owns.

## Git workflow

- Branch: `advisor/011-account-deletion`.
- Commit: `fix: block deleting workspace owners' accounts and remove other users through workspace rules`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Add the core action

Create `RemoveUserFromWorkspaces`:

```php
/**
 * Takes a user out of every workspace before their user record is deleted, following the same rules as removing a
 * member: workspaces keep an owner, and the user's API tokens stop working. Account owners must be refused, because
 * deleting them would leave their workspaces without anyone responsible for them.
 */
class RemoveUserFromWorkspaces
{
    /**
     * @throws ValidationException when the user owns an account or is the last owner of a workspace
     */
    public function handle(MarketingUser $user): void
    {
        $userId = $user->getAuthIdentifier();

        if (Account::query()->where('owner_id', $userId)->exists()) {
            throw ValidationException::withMessages(['account' => __('You own workspaces, so your account can’t be deleted yet. Contact support to close them first.')]);
        }

        $memberships = Membership::query()->with('role')->where('user_id', $userId)->get();

        foreach ($memberships as $membership) {
            if ($membership->role->is_owner) {
                try {
                    EnsureWorkspaceKeepsAnOwner::check($membership);
                } catch (ValidationException) {
                    throw ValidationException::withMessages(['account' => __('You’re the only owner of a workspace. Make someone else an owner first.')]);
                }
            }
        }

        DB::transaction(function () use ($userId): void {
            ApiToken::withoutGlobalScopes()->where('created_by', $userId)->delete();
            Membership::query()->where('user_id', $userId)->delete();
        });
    }
}
```

**Verify**: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` → no errors in the new file

### Step 2: Use it in the host before logging out

Rewrite `destroy`:

```php
public function destroy(ProfileDeleteRequest $request, RemoveUserFromWorkspaces $removeFromWorkspaces): RedirectResponse
{
    $user = $request->user();

    // Refuses with a validation error (and keeps the user signed in) when they own workspaces.
    DB::transaction(function () use ($user, $removeFromWorkspaces): void {
        $removeFromWorkspaces->handle($user);
        $user->delete();
    });

    Auth::logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
}
```

Import `DB` and the action. Update the PHPDoc to say what happens when the user owns workspaces.

In `delete-user.tsx`, add `<InputError message={errors.account} />` next to the existing password error. If TypeScript complains that `errors.account` isn't a known key, read the `errors` type the `<Form>` render prop provides and follow how other host forms show server-only keys; don't use `any`.

**Verify**: `php artisan test --compact tests/Feature/Settings/ProfileUpdateTest.php` → all pass (the existing deletion test is for a user with no workspace, so it still passes)
**Verify**: `npm run types:check && npm run check` → exit 0

## Test plan

Add these to `tests/Feature/Settings/ProfileUpdateTest.php`:

1. `test('a workspace owner is told why their account cannot be deleted, and stays signed in')`. Run `app(CreateWorkspace::class)->handle($user, 'Acme')`, then DELETE with password `'password'`. Expect `assertSessionHasErrors('account')`, `$this->assertAuthenticated()`, and `$user->fresh()` not null.
2. `test('a member who owns nothing is removed from their workspaces and their tokens are revoked')`.
    - Set up the owner with `CreateWorkspace`, then add `$member` with the Editor role: `Membership::create([...role_id => Role::withoutGlobalScopes()->where('workspace_id', $ws->id)->where('name', 'Editor')->value('id')])`.
    - Create a token for the member with `ApiToken::withoutGlobalScopes()->create([...'created_by' => $member->id...])`, or use `CreateApiToken`.
    - The member deletes their account. Expect a redirect home, `assertGuest()`, the user is gone, no memberships remain for them, and no tokens with `created_by = $member->id` remain.
3. `test('a second owner can delete their account when another owner remains')`. Give the workspace two owner-role members, where the second doesn't own the `accounts` row. The second deletes their account successfully.

Add to `packages/core/tests/Feature/MembersAndRolesTest.php`:

4. `test('the last owner of a workspace cannot be removed by deleting their user')`. Run `[$original, $workspace] = $this->createOwner();` and then `$user = $this->addMember($workspace, 'Owner');`. Delete `$original`'s `Membership` row, so `$user` is the only owner while the `accounts` row still belongs to `$original`. Call `app(RemoveUserFromWorkspaces::class)->handle($user)` and expect a `ValidationException` with key `account`. Use the file's `createOwner()`, `addMember()` and `roleNamed()` helpers.

**Revert check**: comment out the `Account` ownership check, confirm test 1 fails (with a 500 or a `QueryException`), then restore it. Report the result.

## Done criteria

- [ ] `test -f packages/core/src/Actions/Workspaces/RemoveUserFromWorkspaces.php` succeeds.
- [ ] In `ProfileController::destroy`, `Auth::logout()` comes after the delete (`grep -n "Auth::logout\|->delete()" app/Http/Controllers/Settings/ProfileController.php` shows the delete line first).
- [ ] The host and core suites pass, with 4 new tests.
- [ ] Types, lint, Pint and PHPStan are clean on the touched files.
- [ ] Only in-scope files are changed.

## STOP conditions

- An excerpt doesn't match.
- `Membership` or `Account` turns out to have a global scope that hides rows from these queries. Report it rather than adding `withoutGlobalScopes()` everywhere.
- Making test 1 pass seems to need a migration change.

## Maintenance notes

- **Workspace owners can't delete their accounts themselves until there's a way to close a workspace or transfer an account.** That's a real gap for GDPR erasure requests, and support will have to handle them by hand. The follow-up feature is "delete workspace" (owner only, with a typed-name confirmation, cascading its data) and/or "transfer account ownership".
- Any future path that deletes users, such as an admin tool, must call `RemoveUserFromWorkspaces` first.
