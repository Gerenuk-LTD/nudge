# Plan 052: In invite and closed mode, users without a workspace can't start their own account

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to the repo root unless
> they say otherwise. If anything in "STOP conditions" happens, stop and
> report. Don't improvise. When done, update this plan's row in
> `plans/README.md`, unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 6982215..HEAD -- packages/core/src/Http/Controllers/WorkspaceController.php packages/core/src/MarketingServiceProvider.php packages/core/src/Contracts packages/core/resources/js/pages/marketing/workspaces/create.tsx app/Providers/AppServiceProvider.php app/Support/Registration.php`
> If any of these changed, compare them with the "Current state" excerpts.
> On a mismatch, STOP. Plan 047 adds URL defaults to
> `AppServiceProvider::configureDefaults()`; that's expected.

## Status

- **Priority**: P2. Matters on shared installs; P1 if strangers can reach the install.
- **Effort**: S–M
- **Risk**: LOW–MED. It changes what workspace-less users see in invite and closed mode.
- **Depends on**: none
- **Category**: security / authorization
- **Planned at**: commit `6982215`, 2026-11-15

## Why this matters

`REGISTRATION_MODE=invite` (the default) or `closed` means the operator decides who uses the install. But any signed-in user with **no membership** can create a workspace, which starts a brand-new account with its own sending setup. That covers:

- revoked invitees, who registered while their invitation was pending and lost it before accepting;
- members who were removed from every workspace.

Each can then add a provider and send email from the operator's install. Registration mode lives in the host app (`app/Support/Registration.php`); the workspace rule lives in core, which doesn't know about registration. This plan adds a small contract so core can ask the host.

## Current state

**Core, `packages/core/src/Http/Controllers/WorkspaceController.php`**:

```php
public function create(Request $request): Response
{
    $this->accountForNewWorkspace($this->user($request));

    return Inertia::render('marketing/workspaces/create');
}
...
/**
 * The account a new workspace joins: none for a user's first workspace, which starts a new account, and otherwise
 * the account they own, so billing stays together. Members who own no account can't create workspaces, or anyone
 * invited anywhere could start their own sending setup.
 */
private function accountForNewWorkspace(MarketingUser $user): ?Account
{
    $userId = $user->getAuthIdentifier();

    if (! Membership::query()->where('user_id', $userId)->exists()) {
        return null;
    }

    return Account::query()->where('owner_id', $userId)->first()
        ?? abort(403, __('Only account owners can create workspaces.'));
}
```

`store()` calls `accountForNewWorkspace()` too, then `$createWorkspace->handle($user, $validated['name'], $account)`.

**Core, `src/Http/Middleware/SetCurrentWorkspace.php:29-30`**: users with no workspace are redirected to `marketing.workspaces.create` (except on `marketing.workspace:optional` routes).

**Core contracts**: `src/Contracts/` holds only `MarketingUser.php`, an interface the host's user model implements. Match its docblock style:

```php
/**
 * What the core needs from the host application's user model.
 * Implement it by using the HasWorkspaces trait.
 */
interface MarketingUser extends Authenticatable
```

**Core, `src/MarketingServiceProvider.php::register()`**: merges config, then `$this->app->scoped(CurrentWorkspace::class)` and `$this->app->scoped(ProviderTransports::class)`. Add the default binding here.

**Host, `app/Support/Registration.php`**: `mode()` returns `'open' | 'invite' | 'closed'` (falls back to `invite`). `isFirstUser()` is "no users yet".

**Host, `app/Providers/AppServiceProvider.php`**: `register()` is empty (`//`).

**Frontend, `packages/core/resources/js/pages/marketing/workspaces/create.tsx`**: a `Form` with a name field and "Create workspace" button, no props.

**First-run flow to keep working:** `php artisan app:create-owner` (or the web sign-up with `SETUP_TOKEN`) creates the first user, who has no membership and then creates the first workspace. So "no account exists yet" must always be allowed.

**Invite flow to keep working:** an invitee registers, verifies, and accepts through the invitation link (`marketing/invitations/*` pages). Until they accept, they have no membership and are sent to the create page.

**Tests**: core `tests/Feature/WorkspaceTest.php` (e.g. line 35 "a user can create a workspace and lands in it", line 105 "a member who doesn't own an account cannot create a workspace"). Host `tests/Feature/Auth/RegistrationTest.php` sets the mode with `config(['registration.mode' => 'invite'])`.

## Commands

From the repo root (Git Bash: `php.bat`/`composer.bat`).

| Purpose         | Command                                                       | Expected                                      |
| --------------- | ------------------------------------------------------------- | --------------------------------------------- |
| Host tests      | `php artisan test --compact`                                  | all pass                                      |
| Core tests      | `cd packages/core && vendor/bin/pest`                         | all pass, except the 7 Windows-only SES tests |
| PHPStan         | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`  | 0 errors                                      |
| Pint            | `vendor/bin/pint --test`                                      | passes                                        |
| Frontend        | `npm run check` and `npm run types:check`                     | pass                                          |

## Scope

**In scope:**

- `packages/core/src/Contracts/AccountCreationPolicy.php` (new)
- `packages/core/src/Support/AllowAccountCreation.php` (new, the default)
- `packages/core/src/MarketingServiceProvider.php` (one binding in `register()`)
- `packages/core/src/Http/Controllers/WorkspaceController.php` (`create()`, `store()`, `accountForNewWorkspace()`)
- `packages/core/resources/js/pages/marketing/workspaces/create.tsx`
- `packages/core/tests/Feature/WorkspaceTest.php`
- `app/Support/RegistrationAccountPolicy.php` (new)
- `app/Providers/AppServiceProvider.php` (`register()` only)
- `tests/Feature/AccountCreationTest.php` (new, host)

**Out of scope:**

- Registration itself (`Registration::allows`), and invitation acceptance.
- Making `REGISTRATION_MODE=open` imply `MARKETING_ALLOW_PRIVATE_SMTP_HOSTS=false`. A related option, not part of this plan.
- Existing accounts started by workspace-less users. Not changed retroactively.

## Git workflow

- Branch off `dev`: `advisor/052-new-accounts-follow-registration-mode`.
- Commits like `fix: new accounts follow the registration mode`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: The contract and its default (core)

`packages/core/src/Contracts/AccountCreationPolicy.php`:

```php
/**
 * Whether a user with no workspace may start a new account (their own sending setup). The host decides, because it
 * owns sign-up. The default allows it.
 */
interface AccountCreationPolicy
{
    public function allows(MarketingUser $user): bool;
}
```

`packages/core/src/Support/AllowAccountCreation.php` implements it and returns `true`. Check that `src/Support/` exists (it does: `src/Support/ContactFilter.php`); match its namespace.

In `MarketingServiceProvider::register()`, add `$this->app->bindIf(AccountCreationPolicy::class, AllowAccountCreation::class);`. `bindIf` lets the host's binding win whichever provider registers first.

**Verify**: core tests → all pass.

### Step 2: Core asks the policy

Change `accountForNewWorkspace()` so the no-membership branch is:

```php
if (! Membership::query()->where('user_id', $userId)->exists()) {
    // Someone who already owns an account keeps using it; anyone else needs the host's say-so to start one.
    return Account::query()->where('owner_id', $userId)->first()
        ?? (app(AccountCreationPolicy::class)->allows($user) ? null : abort(403, __('You don’t have access to a workspace yet. Ask a workspace owner to invite you.')));
}
```

Read it as three cases: owns an account → that account; policy allows → `null` (new account); otherwise 403.

Then make `create()` render instead of 403-ing, so workspace-less users land on a helpful page rather than an error. Wrap the call:

```php
public function create(Request $request): Response
{
    try {
        $this->accountForNewWorkspace($this->user($request));
        $allowed = true;
    } catch (HttpException $exception) {
        if ($exception->getStatusCode() !== 403) {
            throw $exception;
        }
        $allowed = false;
        $message = $exception->getMessage();
    }

    return Inertia::render('marketing/workspaces/create', ['allowed' => $allowed, 'message' => $message ?? null]);
}
```

`HttpException` is `Symfony\Component\HttpKernel\Exception\HttpException`. `store()` keeps the 403.

**Check the existing test at `WorkspaceTest.php:105`** ("a member who doesn't own an account cannot create a workspace"). If it asserts `create` returns 403, update **only that assertion** to expect 200 with `allowed` false, and keep its `store` 403 assertion. Note the change in your report.

**Verify**: core tests → all pass.

### Step 3: The page

In `create.tsx`, accept `{ allowed, message }: { allowed: boolean; message: string | null }`. When `!allowed`, render instead of the form: the message, then "If you were invited, open the link in your invitation email." and a sign-out link (find the existing logout pattern: `grep -rn "logout" resources/js/components | head -3` from the repo root, and reuse it). Keep the `Head` title. Keep the form unchanged when `allowed`.

**Verify** (root): `npm run check` and `npm run types:check` → pass.

### Step 4: The host's policy

`app/Support/RegistrationAccountPolicy.php`:

```php
/**
 * In open mode anyone signed in may start an account. In invite and closed mode only the installation's first
 * account may be started this way; everyone else joins through an invitation.
 */
final class RegistrationAccountPolicy implements AccountCreationPolicy
{
    public function __construct(private readonly Registration $registration) {}

    public function allows(MarketingUser $user): bool
    {
        return $this->registration->mode() === 'open' || ! Account::query()->exists();
    }
}
```

`Account` is `Gerenuk\Marketing\Models\Account`. Check whether it has a global workspace scope (`grep -n "addGlobalScope\|ScopedBy" packages/core/src/Models/Account.php`). If it does, use `Account::withoutGlobalScopes()->exists()`.

Bind it in `AppServiceProvider::register()`: `$this->app->bind(AccountCreationPolicy::class, RegistrationAccountPolicy::class);`.

**Verify**: host tests → all pass.

### Step 5: Tests

Core, in `WorkspaceTest.php` (bind a fake policy with `$this->app->instance(AccountCreationPolicy::class, new class implements AccountCreationPolicy { public function allows(MarketingUser $user): bool { return false; } })`):

1. **A refused user sees the page without the form**: GET `workspaces.create` → 200, `allowed` false.
2. **…and can't store**: POST `workspaces.store` → 403, and no workspace is created.
3. **An account owner with no membership still can.** Hard to reach in practice; skip if setting it up needs more than creating an `Account` row with `owner_id` (check the model's fillable fields).

Host, `tests/Feature/AccountCreationTest.php`:

4. **Invite mode, a user with no membership, and an existing account** (create an owner and workspace first, e.g. through `app:create-owner` or by posting to `marketing.workspaces.store` as a first user) → a second workspace-less user's POST `marketing.workspaces.store` gets 403.
5. **Closed mode** → same.
6. **Open mode** → the store succeeds.
7. **The very first account** in invite mode → the store succeeds.

**Revert checks** (restore after each, commit nothing from them):

- Make `RegistrationAccountPolicy::allows` return true: tests 4 and 5 fail.
- Remove the policy call in core: tests 1 and 2 fail.

**Verify**: host tests, core tests, PHPStan, Pint, `npm run check` and `types:check` pass.

## Done criteria

- [ ] In invite and closed mode, a workspace-less user can't start an account once one exists; the create page explains why
- [ ] Open mode and the first account behave as before
- [ ] Core without a host binding behaves as before (`AllowAccountCreation`)
- [ ] New tests pass and the revert checks fail as described
- [ ] Host tests, core tests, PHPStan, Pint, `npm run check` and `types:check` pass
- [ ] `plans/README.md` row updated

## STOP conditions

- The drift check shows changes the excerpts don't cover.
- Any existing invitation test fails (an invitee must still be able to register and accept).
- Core's tests turn out to boot the host's `AppServiceProvider` (then core tests would see the host policy; check `packages/core/tests/TestCase.php`'s providers).

## Maintenance notes

- **Release note:** in invite and closed mode, only the installation's first account can be started from the "Create a workspace" page. Others join through invitations; existing account owners can still add workspaces.
- A hosted, sign-up-for-yourself product would run in open mode, so nothing changes there.
- Other hosts embedding core get the old behaviour unless they bind `AccountCreationPolicy`. Mention it in core's docs when a core README is written (still a backlog item).
