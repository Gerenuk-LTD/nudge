# Plan 017: Self-hosted installs control who can register — open, invite-only (default) or closed

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 0ac75d7..HEAD -- app/Actions/Fortify/CreateNewUser.php app/Providers/FortifyServiceProvider.php app/Http/Middleware/HandleInertiaRequests.php resources/js/pages/auth/register.tsx resources/js/pages/auth/login.tsx resources/js/pages/welcome.tsx resources/js/types/global.d.ts packages/core/src/Models/Invitation.php .env.example`
> Expected: no output. On a mismatch with the excerpts, STOP.

## Status

- **Priority**: P1. This is a product requirement for self-hosting.
- **Effort**: S–M
- **Risk**: MED. It changes who can sign up. The first user can always register, so a fresh install can't lock its owner out.
- **Depends on**: none
- **Category**: security / product
- **Planned at**: commit `0ac75d7`, 2026-10-30
- **Product decision (the maintainer, 2026-10-30)**: people deploy this on their own servers and don't want strangers signing up. The default becomes invite-only.

## Why this matters

Fortify registration is fully open today: anyone who can reach the server can create an account and their own workspace. On a self-hosted install, that means strangers can use someone else's sending infrastructure.

Registration can't simply be switched off, because of how invitations work. An invited person who has no account has to register (with the invited email address), verify that email, and then accept the invitation. The fix is therefore a mode, set by environment variable:

| `REGISTRATION_MODE` | Who can register                                                                                            |
| ------------------- | ----------------------------------------------------------------------------------------------------------- |
| `invite` (default)  | Only an email address that has a pending, unexpired invitation                                              |
| `closed`            | Nobody. Owners must add people some other way, for example by inviting addresses that already have accounts |
| `open`              | Anyone, for a hosted SaaS                                                                                   |

In every mode, while the users table is empty, the very first user can register, so a fresh install can be set up. Squatting on an invited email address is harmless: routes require a verified email, so an impostor can't verify, and the real person can recover the account with a password reset.

## Current state

**`config/fortify.php`** enables `Features::registration()`. Keep it enabled; the mode controls who may use it.

**`app/Actions/Fortify/CreateNewUser.php`**:

```php
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create(['name' => $input['name'], 'email' => $input['email'], 'password' => $input['password']]);
    }
}
```

**`app/Providers/FortifyServiceProvider.php`** (around line 70):

```php
Fortify::registerView(fn () => Inertia::render('auth/register', [
    'passwordRules' => Password::defaults()->toPasswordRulesString(),
]));
```

**`app/Http/Middleware/HandleInertiaRequests.php`** `share()` returns `name`, `auth.user` and `sidebarOpen`. `resources/js/types/global.d.ts` types these shared props; `sidebarOpen: boolean;` is at line 15.

**Frontend links to register:**

- `resources/js/pages/auth/login.tsx` around line 106: `Don't have an account? <TextLink href={register()}>Sign up</TextLink>`.
- `resources/js/pages/welcome.tsx` around line 30: a "Register" `<Link href={register()}>` shown to guests.

`resources/js/pages/auth/register.tsx` takes `Props = { passwordRules: string }`.

**Core invitations.** `packages/core/src/Models/Invitation.php` is workspace-scoped (`BelongsToWorkspace`). It has `email` (stored normalised), `expires_at` (a datetime cast), `isExpired()`, and the static `findByToken()`, which uses `withoutGlobalScopes()`. `Gerenuk\Marketing\Models\Contact::normalizeEmail()` normalises addresses. The core must stay reusable, so add the cross-workspace check as a static method on `Invitation`; the host calls it.

**Tests.** `tests/Feature/Auth/RegistrationTest.php` has:

- `beforeEach` that calls `$this->skipUnlessFortifyHas(Features::registration())`;
- `test('registration screen can be rendered')`;
- `test('new users can register')`, which posts name, email, password and password_confirmation to `route('register.store')` and expects `assertAuthenticated()` plus a redirect to the dashboard.

In both tests the users table is empty, so they still pass under the first-user rule.

## Commands you will need

| Purpose     | Command                                                                | Expected |
| ----------- | ---------------------------------------------------------------------- | -------- |
| Auth tests  | `php artisan test --compact tests/Feature/Auth`                        | all pass |
| Host tests  | `php artisan test --compact`                                           | all pass |
| Core tests  | `cd packages/core && vendor/bin/pest --compact`                        | all pass |
| PHPStan     | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`           | 0 errors |
| Pint        | `vendor/bin/pint --dirty --format agent` then `vendor/bin/pint --test` | exit 0   |
| Frontend    | `npm run types:check && npm run check && npm run build`                | exit 0   |
| Make config | create `config/registration.php` by hand (no artisan command needed)   |          |

## Scope

**In scope:**

- `config/registration.php` (new)
- `app/Support/Registration.php` (new)
- `app/Actions/Fortify/CreateNewUser.php`
- `app/Providers/FortifyServiceProvider.php` (`registerView` only)
- `app/Http/Middleware/HandleInertiaRequests.php`
- `resources/js/types/global.d.ts`
- `resources/js/pages/auth/register.tsx`, `login.tsx`, `welcome.tsx`
- `packages/core/src/Models/Invitation.php` (one static method)
- `.env.example`
- `tests/Feature/Auth/RegistrationTest.php`

**Out of scope:**

- Turning off Fortify's registration feature.
- Core invitation controllers and pages. An "accept and create your account" flow on the invitation page is a follow-up.
- Admin UI for changing the mode. It is env-only for now.

## Git workflow

- Branch: `advisor/017-registration-control`.
- Commit: `feat: control who can register with REGISTRATION_MODE, invite-only by default`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Core helper for pending invitations

Add to `Invitation`:

```php
/**
 * Whether any workspace has an unexpired invitation for this email address. Hosts use it to allow sign-up by invitation.
 */
public static function isPendingFor(string $email): bool
{
    return static::withoutGlobalScopes()
        ->where('email', Contact::normalizeEmail($email))
        ->where('expires_at', '>', now())
        ->exists();
}
```

**Verify**: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` → 0 errors

### Step 2: The mode and the rule

Create `config/registration.php`:

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registration Mode
    |--------------------------------------------------------------------------
    |
    | Who can create an account. "invite" (the default) allows only email
    | addresses with a pending workspace invitation, "closed" allows nobody,
    | and "open" allows anyone. Whatever the mode, the first user of a new
    | installation can always register, so it can be set up.
    |
    */

    'mode' => env('REGISTRATION_MODE', 'invite'),

];
```

Create `app/Support/Registration.php`, a final class with these methods:

- `mode(): string`: the config value, falling back to `'invite'` when it isn't one of `open`, `invite` or `closed`;
- `isFirstUser(): bool`: `! User::query()->exists()`;
- `isAvailable(): bool`: `isFirstUser() || mode() !== 'closed'`. This decides whether the sign-up page and links are shown;
- `allows(string $email): bool`: `isFirstUser()`, or `open`, or (`invite` and `Invitation::isPendingFor($email)`);
- `refusal(): string`: the message for the current mode. For `invite`: "Sign-up is by invitation. Use the email address your invitation was sent to." For `closed`: "Sign-up is closed on this installation."

Give each method a one-line PHPDoc.

In `CreateNewUser::create`, add this rule on `email`, after the profile rules (merge it into the `email` array):

```php
function (string $attribute, mixed $value, \Closure $fail) use ($registration): void {
    if (! $registration->allows((string) $value)) {
        $fail($registration->refusal());
    }
},
```

Resolve `$registration` with `app(Registration::class)`.

**Verify**: `php artisan test --compact tests/Feature/Auth/RegistrationTest.php` → both existing tests pass (the users table is empty)

### Step 3: Hide or explain sign-up in the UI

- **`FortifyServiceProvider` `registerView`:** `abort_unless($registration->isAvailable(), 404);`. Add the props `'byInvitation' => ! $registration->isFirstUser() && $registration->mode() === 'invite'`.
- **`HandleInertiaRequests::share`:** add `'canRegister' => app(Registration::class)->isAvailable()`. That query is cheap, but only run it for guests: `$request->user() === null ? app(Registration::class)->isAvailable() : false`.
- **`global.d.ts`:** add `canRegister: boolean;` next to `sidebarOpen`.
- **`login.tsx` and `welcome.tsx`:** render the sign-up link only when `usePage().props.canRegister`.
- **`register.tsx`:** accept `byInvitation: boolean`. When it's true, show a short line under the heading: "Sign-up is by invitation. Use the email address your invitation was sent to." Follow the page's existing text styles.

In `.env.example`, add near the `APP_` settings:

```
# Who can sign up: invite (default, invited emails only), closed, or open.
REGISTRATION_MODE=invite
```

**Verify**: `npm run types:check && npm run check && npm run build` → exit 0

## Test plan

Add these to `tests/Feature/Auth/RegistrationTest.php`. Use a helper that posts valid registration data for a given email.

1. `test('the first user can register even when sign-up is closed')`. Use `config(['registration.mode' => 'closed'])` with an empty users table. Expect authenticated.
2. `test('invite-only sign-up refuses addresses without an invitation')`. Create one user first, so this registration isn't the first; set the mode to `invite`; register `stranger@example.com`. Expect `assertSessionHasErrors('email')`, `assertGuest()`, and no user with that email.
3. `test('invite-only sign-up accepts an invited address')`. Create a workspace owner with `app(CreateWorkspace::class)->handle($owner, 'Acme')`. Create an invitation for `invited@example.com` with `Invitation::withoutGlobalScopes()->create([...])`; read the core model and `InviteMember` action for the required fields, which include role, token_hash and expires_at in the future. Register `Invited@Example.com` and expect authenticated.
4. `test('an expired invitation does not allow sign-up')`. Same as 3, but with `expires_at` in the past. Expect an email error.
5. `test('closed sign-up refuses everyone after the first user, and hides the page')`. Create a user and set the mode to `closed`. `get(route('register'))` should give `assertNotFound()`. Posting registration should give an email error. `get(route('login'))` should have `canRegister` false; use `assertInertia(fn ($page) => $page->where('canRegister', false))`.
6. `test('open sign-up allows anyone')`. Create a user, set the mode to `open`, register a stranger, and expect authenticated.

Revert check: remove the `CreateNewUser` rule, confirm test 2 fails, then restore it. Report the result.

## Done criteria

- [ ] `grep -n "REGISTRATION_MODE" config/registration.php .env.example` shows 2 matches
- [ ] `grep -n "function isPendingFor" packages/core/src/Models/Invitation.php` matches
- [ ] Host suite passes with 6 new tests; core suite passes; PHPStan reports 0 errors; Pint is clean
- [ ] Frontend types, lint and build exit 0
- [ ] Only in-scope files changed

## STOP conditions

- An excerpt doesn't match.
- `abort_unless` inside `registerView` doesn't produce a 404. Fortify might cache the view callback differently. Report it, and don't move the check into a route.
- Existing host tests (outside `Auth`) create users through registration in a way that now fails.

## Maintenance notes

- **Release note for existing installs:** after upgrading, sign-up becomes invite-only unless `REGISTRATION_MODE=open` is set. Anyone running a public SaaS must set it.
- **Follow-up:** an invitation page that offers "Create your account" with the email filled in, then returns the person to the invitation after they verify their email.
- Any new route to account creation, such as social login, must call `Registration::allows()`.
