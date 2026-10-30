# Plan 025: Rotating APP_KEY keeps old unsubscribe links working, and nobody can claim a fresh install before its owner

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat a86033c..HEAD -- packages/core/src/Sending/TrackingUrls.php app/Support/Registration.php app/Actions/Fortify/CreateNewUser.php app/Providers/FortifyServiceProvider.php config/registration.php .env.example tests/Feature/Auth/RegistrationTest.php`
> If plans 021 or 022 landed first, `.env.example` and `FortifyServiceProvider.php` will differ in the parts they touched; merge around those changes. Any other mismatch is a STOP.

## Status

- **Priority**: P2. Before deploying anywhere public.
- **Effort**: S–M
- **Risk**: MED. This changes how the very first account is created. A fresh-install guide is part of the plan.
- **Depends on**: plans/021-production-hardening.md, for `DEPLOYING.md`. If 021 hasn't landed, put the documentation in this plan's report and STOP before Step 3.
- **Category**: security
- **Planned at**: commit `a86033c`, 2026-10-30

## Why this matters

1. **Rotating `APP_KEY` breaks every unsubscribe link already sent.** `TrackingUrls` signs unsubscribe, click and open links with `'marketing-tracking|'.config('app.key')` and checks them against the current key only. Laravel's documented rotation (`APP_PREVIOUS_KEYS`) keeps encrypted data working, but every unsubscribe link and one-click `List-Unsubscribe` in emails already delivered would return 404. That breaks RFC 8058 and Gmail's and Yahoo's bulk-sender rules, and it means operators are afraid to rotate a leaked key.
2. **A fresh install can be hijacked.** Plan 017 lets the very first user register in every mode, so a new install can be set up. Between `php artisan migrate` and the operator signing up, anyone who finds the URL can register first. In invite or closed mode the operator is then locked out of their own install.

## Current state

**`packages/core/src/Sending/TrackingUrls.php`:**

```php
public function verify(string $payload, string $signature): bool
{
    return hash_equals($this->sign($payload), $signature);
}

private function sign(string $payload): string
{
    return substr(hash_hmac('sha256', $payload, 'marketing-tracking|'.config('app.key')), 0, 24);
}
```

`click()`, `open()` and `unsubscribe()` build URLs with `$this->sign("click:..."/"open:..."/"unsubscribe:...")`.

**`config/app.php:102-106`:**

```php
'previous_keys' => [
    ...array_filter(
        explode(',', (string) env('APP_PREVIOUS_KEYS', '')),
    ),
],
```

**`app/Support/Registration.php`** (plan 017):

- `mode()` returns `open`, `invite` or `closed`;
- `isFirstUser()` returns `! User::query()->exists()`;
- `isAvailable()` returns `isFirstUser() || mode() !== 'closed'`;
- `allows(string $email)` returns `isFirstUser() || mode() === 'open' || (mode() === 'invite' && Invitation::isPendingFor($email))`;
- `refusal()` returns a translated message.

**`app/Actions/Fortify/CreateNewUser.php`** appends a closure rule to `email` that fails with `$registration->refusal()` when `! $registration->allows($value)`.

**`app/Providers/FortifyServiceProvider.php` `registerView`** returns 404 unless `isAvailable()`, and passes `passwordRules` and `byInvitation` to `auth/register`.

**`tests/Feature/Auth/RegistrationTest.php`** has 8 tests, including `test('new users can register')` and `test('the first user can register even when sign-up is closed')`. Both register into an empty users table with no token, so this plan changes them.

**Commands.** `app/Console/Commands/DemoData.php` shows the attribute style: `#[Signature('app:demo-data {--contacts=500 : ...}')]` and `#[Description('...')]` on a class extending `Command`, with a typed `handle(...)` returning `int`. `CreateNewUser::create(array $input)` creates a user. Use `Hash` and `User::create` directly in the command, or call `CreateNewUser` with the first-user check satisfied.

## Commands you will need

| Purpose          | Command                                                 | Expected                                       |
| ---------------- | ------------------------------------------------------- | ---------------------------------------------- |
| Host tests       | `php artisan test --compact`                            | all pass                                       |
| Core tests       | `cd packages/core && vendor/bin/pest --compact`         | all pass                                       |
| PHPStan / Pint   | as usual                                                | 0 errors / exit 0                              |
| Frontend         | `npm run types:check && npm run check && npm run build` | exit 0                                         |
| Make the command | `php artisan make:command CreateOwner --no-interaction` | creates `app/Console/Commands/CreateOwner.php` |

## Scope

**In scope:**

- `packages/core/src/Sending/TrackingUrls.php`
- `packages/core/tests/Feature/TrackingTest.php` (add tests)
- `app/Support/Registration.php`
- `app/Actions/Fortify/CreateNewUser.php`
- `app/Providers/FortifyServiceProvider.php` (`registerView` props)
- `config/registration.php`
- `.env.example`
- `app/Console/Commands/CreateOwner.php` (new)
- `resources/js/pages/auth/register.tsx` (a setup-token field)
- `tests/Feature/Auth/RegistrationTest.php`
- `tests/Feature/CreateOwnerCommandTest.php` (new)
- `DEPLOYING.md` (the first-run and key-rotation sections)

**Out of scope:**

- Changing the signature format or length of tracking links.
- Re-signing old links.

## Git workflow

- Branch: `advisor/025-key-rotation-and-first-user`.
- Commits:
    - `fix(security): accept tracking links signed with previous app keys`
    - `fix(security): require a setup token or artisan command for the first account`
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Verify tracking links against previous keys

In `TrackingUrls`:

- make `sign(string $payload, ?string $key = null)` use `$key ?? config('app.key')`;
- make `verify()` try the current key, then each `config('app.previous_keys')` entry, returning true on the first `hash_equals` match.

New links are always signed with the current key. Add a sentence to the class docblock: "Links signed with a previous APP_KEY keep working after key rotation, as long as that key is listed in APP_PREVIOUS_KEYS."

**Verify**: core suite passes.

### Step 2: Protect the first account

- **`config/registration.php`:** add `'setup_token' => env('SETUP_TOKEN')`, with a comment: "Required to create the first account through the website. Leave empty to create it with `php artisan app:create-owner` instead."
- **`Registration`:**
    - add `firstUserAllowed(?string $token): bool`. It's true only when `isFirstUser()` and a configured `setup_token` is non-empty and `hash_equals($configured, (string) $token)`;
    - change `allows(string $email, ?string $setupToken = null)` so that when `isFirstUser()`, it returns `firstUserAllowed($setupToken)` and the mode doesn't apply. Otherwise it applies the existing mode rules;
    - update `refusal()` so that when `isFirstUser()`, it says: "Setting up this installation needs the setup token from its configuration, or create the owner with `php artisan app:create-owner`."
- **`CreateNewUser`:** pass `$input['setup_token'] ?? null` into `allows(...)`. Don't save it.
- **`registerView`:** also pass `needsSetupToken` as `$registration->isFirstUser()`.
- **`register.tsx`:** when `needsSetupToken`, show a "Setup token" field named `setup_token`, with a hint explaining where to find it, and an `InputError` for `email`, which is where the refusal appears. Follow the page's field style.
- **`.env.example`:** add `# SETUP_TOKEN=` under `REGISTRATION_MODE`, with a comment: "Set to a long random value to create the first account in the browser, then remove it."

**Verify**: `php artisan test --compact tests/Feature/Auth/RegistrationTest.php`. The two first-user tests will now fail, which is expected; update them in Step 4.

### Step 3: `php artisan app:create-owner`

Create the command:

```
#[Signature('app:create-owner {email} {--name=Owner}')]
#[Description('Create the first account for a new installation')]
```

- It refuses with an error and exit code 1 if any user already exists.
- It asks for the password twice, using `$this->secret()`.
- It validates the password with `Password::defaults()` rules and the email with `email:filter`.
- It creates the user, marked as **email verified**, because the operator ran it on the server.
- It prints "Created <email>. Sign in at <APP_URL>/login."

Then document both first-run options in `DEPLOYING.md`, in a "First run" section, and add a "Rotating APP_KEY" section:

- move the old key into `APP_PREVIOUS_KEYS`;
- run `key:generate`;
- old unsubscribe links keep working while the old key stays listed.

Remove the "do not rotate" warning that plan 021 added.

**Verify**: host suite passes.

### Step 4: Tests

**Core, in `TrackingTest.php`:**

1. `test('links signed with a previous app key still work after rotation')`:
    - build an unsubscribe URL;
    - set `config(['app.previous_keys' => [old key], 'app.key' => 'base64:'.base64_encode(random_bytes(32))])`;
    - `get` the URL gives 200, not 404.
2. `test('links signed with an unknown key are rejected')`: same as test 1, but with no previous keys. Expect 404.

**Host, in `RegistrationTest.php`** (update the two existing first-user tests to send a configured token):

3. `test('the first account needs the setup token')`:
    - with `config(['registration.setup_token' => 'tok'])` and an empty table, registering without a token gives an email error and a guest;
    - with `setup_token: 'tok'` it is authenticated.
4. `test('without a configured setup token the first account cannot be created on the website')`: no token configured gives an error.
5. `test('the setup token is ignored once an owner exists')`: one user plus a token in `invite` mode with a stranger's email still gives the invite refusal.

**Host, in `CreateOwnerCommandTest.php`:**

6. `test('the owner can be created from the command line')`: `artisan('app:create-owner', ['email' => 'o@example.com'])` with `expectsQuestion` for both passwords gives exit code 0, and the user exists with `email_verified_at` set.
7. `test('create-owner refuses when an account already exists')`: exit code 1.

**Revert check:** make `verify()` use only the current key again, confirm test 1 fails, then restore it.

## Done criteria

- [ ] `grep -n "previous_keys" packages/core/src/Sending/TrackingUrls.php` → match
- [ ] `grep -n "setup_token" config/registration.php app/Support/Registration.php` → matches
- [ ] `test -f app/Console/Commands/CreateOwner.php`
- [ ] `DEPLOYING.md` has "First run" and "Rotating APP_KEY" sections
- [ ] Host and core suites pass with the new tests; PHPStan reports 0 errors; Pint is clean; frontend checks pass
- [ ] Only in-scope files changed

## STOP conditions

- An excerpt doesn't match beyond the expected changes from plans 021 and 022.
- `DEPLOYING.md` doesn't exist because plan 021 hasn't landed. Finish Steps 1, 2 and 4, then report.
- Another part of the app or its tests creates the first user through registration (for example `DemoData`, or the docs). Report it.

## Maintenance notes

- **Release note:** a fresh install needs `SETUP_TOKEN` or `php artisan app:create-owner` to create its first account. Existing installs aren't affected.
- When rotating `APP_KEY`, keep the old key in `APP_PREVIOUS_KEYS` for at least as long as old emails might be acted on. A year is sensible for unsubscribe links.
