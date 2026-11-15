# Plan 050: Passkeys and 2FA can only be added to accounts with a verified email

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in "STOP conditions" happens, stop and report. Don't
> improvise. When done, update this plan's row in `plans/README.md`, unless a
> reviewer dispatched you and told you they maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 6982215..HEAD -- bootstrap/app.php app/Http/Middleware app/Console/Commands/SetPassword.php tests/Feature/SetPasswordCommandTest.php DEPLOYING.md`
> If any of these changed, compare them with the "Current state" excerpts.
> On a mismatch, STOP. Plan 047 adds `trustHosts()`/`trustProxies()` lines
> to `bootstrap/app.php`; that's expected.

## Status

- **Priority**: P1
- **Effort**: S–M
- **Risk**: LOW. Verified users see no change. Unverified users already can't open the Security settings page.
- **Depends on**: none
- **Category**: security (host auth)
- **Planned at**: commit `6982215`, 2026-11-15

## Why this matters

In invite mode, anyone can register with an email address that has a pending invitation. They don't need the invitation link (`app/Support/Registration.php:57`). They can't verify that address, but Fortify's passkey and 2FA routes need only `auth`, not `verified`. So an attacker can:

1. register as the invitee's email and add a **passkey** while unverified;
2. wait. The real invitee finds the email taken, resets the password, verifies and accepts the invitation;
3. sign in with the passkey. Passkey login goes straight in, and a password reset doesn't remove passkeys (`vendor/laravel/fortify/src/Actions/CompletePasswordReset.php` only rotates the remember token). The attacker now holds the invitee's role.

With 2FA, the attacker's TOTP secret lets them lock the real owner out.

Requiring a verified email for adding passkeys and 2FA closes the hole: the attacker can never verify someone else's inbox. The plan also lets operators clear a user's passkeys and 2FA from the command line, for recovery.

## Current state

**Fortify routes** (`vendor/laravel/fortify/routes/routes.php`, read-only vendor code). The names this plan guards:

- 2FA, middleware `auth` + `password.confirm` (lines ~146-176): `two-factor.enable`, `two-factor.confirm`, `two-factor.regenerate-recovery-codes`. Also `two-factor.qr-code`, `two-factor.secret-key`, `two-factor.recovery-codes` (GET).
- Passkeys (lines ~179-220): `passkey.registration-options` (GET `/user/passkeys/options`) and `passkey.store` (POST `/user/passkeys`), behind `auth` + `password.confirm` + throttle. The login and confirm routes (`passkey.login-options`, `passkey.login`, `passkey.confirm-options`, `passkey.confirm`) must stay unguarded.

The settings pages are already behind `verified` (`routes/settings.php:14`: `Route::middleware(['auth', 'verified', 'marketing.workspace:optional'])`), so an unverified user can't open the Security page, but can post to these routes directly.

**`App\Models\User`** implements `MustVerifyEmail` and `PasskeyUser`, and uses `PasskeyAuthenticatable` (from `laravel/passkeys`, giving `$user->passkeys()`, a `HasMany` on the `passkeys` table) and `TwoFactorAuthenticatable` (columns `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`).

**Pattern for a route-name middleware**: `app/Http/Middleware/ThrottlePasswordChecks.php`, appended to the `web` group in `bootstrap/app.php`. It does `if ($request->routeIs('password.confirm.store')) { ... }`. Routes are matched before middleware runs, so `routeIs` works inside group middleware.

```php
$middleware->web(append: [
    HandleAppearance::class,
    SecurityHeaders::class,
    AuthenticateSession::class,
    RedirectWhenRegistrationClosed::class,
    ThrottlePasswordChecks::class,
    HandleInertiaRequests::class,
    AddLinkHeadersForPreloadedAssets::class,
]);
```

**`app/Console/Commands/SetPassword.php`**: signature `app:set-password {email : ...} {--password= : ...}`. It finds the user by email, prompts for the password when `--password` is missing, resets it through `ResetUserPassword`, and prints `Password updated for [email].` Its tests are `tests/Feature/SetPasswordCommandTest.php` (`$this->artisan('app:set-password', [...])->assertSuccessful()`).

**Tests**: `tests/Feature/Auth/EmailVerificationTest.php` uses `User::factory()->unverified()->create()`. `tests/Feature/Settings/SecurityTest.php` sets up passkeys in Fortify with `Features::passkeys([...])` at line ~18. Use `$this->skipUnlessFortifyHas(Features::passkeys())` and `Features::twoFactorAuthentication()` as those files do.

## Commands you will need

From the repo root (Git Bash: `php.bat`).

| Purpose    | Command                                                                               | Expected                 |
| ---------- | ------------------------------------------------------------------------------------- | ------------------------ |
| Host tests | `php artisan test --compact`                                                          | all pass                 |
| One file   | `php artisan test --compact tests/Feature/Auth/SignInMethodsNeedVerificationTest.php` | all pass                 |
| Routes     | `php artisan route:list --name=passkey`                                               | lists the passkey routes |
| PHPStan    | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`                          | 0 errors                 |
| Pint       | `vendor/bin/pint --test`                                                              | passes                   |

## Scope

**In scope:**

- `app/Http/Middleware/RequireVerifiedEmailForSignInMethods.php` (new)
- `bootstrap/app.php` (one line in the `web` append list)
- `app/Console/Commands/SetPassword.php`
- `tests/Feature/Auth/SignInMethodsNeedVerificationTest.php` (new)
- `tests/Feature/SetPasswordCommandTest.php`
- `DEPLOYING.md` (one short note; see Step 4)

**Out of scope:**

- Vendor files, and Fortify's config.
- Binding invite-mode sign-up to the invitation token. It's a larger flow change (the register page would need the token). With this plan the attack no longer works, because the attacker can't verify. Recorded as a follow-up.
- Deleting existing passkeys or 2FA automatically. See Step 4.
- Disabling or deleting existing passkeys/2FA (`two-factor.disable`, `passkey.destroy`). Removing a sign-in method is always allowed.

## Git workflow

- Branch off `dev`: `advisor/050-verified-email-for-sign-in-methods`.
- Commits like `fix: passkeys and 2FA need a verified email`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Check the route names

Run `php artisan route:list --name=passkey` and `php artisan route:list --name=two-factor`. Confirm the names in "Current state" exist. If any is missing or renamed, STOP.

### Step 2: The middleware

Create `app/Http/Middleware/RequireVerifiedEmailForSignInMethods.php`, following `ThrottlePasswordChecks`'s style:

```php
/**
 * Passkeys and two-factor authentication can only be added once the account's email is verified. Otherwise someone
 * who signs up with another person's address (before that person does) could add a passkey that outlives the real
 * owner's password reset.
 */
class RequireVerifiedEmailForSignInMethods
{
    /** Routes that add or reveal a sign-in method. Removing one is always allowed. */
    private const ROUTES = [
        'passkey.registration-options',
        'passkey.store',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
    ];

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->routeIs(...self::ROUTES) && $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            abort(403, __('Verify your email address before adding a passkey or two-factor authentication.'));
        }

        return $next($request);
    }
}
```

`MustVerifyEmail` is `Illuminate\Contracts\Auth\MustVerifyEmail`.

Add `RequireVerifiedEmailForSignInMethods::class` to the `web` append list in `bootstrap/app.php`, right after `ThrottlePasswordChecks::class`, with its `use` line.

**Verify**: `php artisan test --compact` → all pass. If a Security settings test fails, check whether its user is unverified; the factory's default is verified, so a failure there means STOP.

### Step 3: Clearing sign-in methods from the command line

Extend `app:set-password` with `{--clear-sign-in-methods : Also remove the user's passkeys and two-factor authentication}`. When given, after the password is reset successfully:

```php
$user->passkeys()->delete();
$user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
```

and print `Passkeys and two-factor authentication removed for [email].` Use the same `$this->components->info(...)` style. Without the option, behave exactly as today.

**Verify**: `php artisan test --compact tests/Feature/SetPasswordCommandTest.php` → all pass.

### Step 4: Docs and existing accounts

Add to `DEPLOYING.md`, in the section that mentions `app:set-password` if there is one (`grep -n "set-password" DEPLOYING.md`), otherwise next to "First run":

- `php artisan app:set-password someone@example.com --clear-sign-in-methods` resets a locked-out user's password and removes their passkeys and two-factor authentication.

Don't add an automatic cleanup. Before this release, an unverified account could add passkeys; an operator who wants to check can list accounts that have them while unverified. Put this one-liner in your report (not in the docs) for the maintainer:

```sh
php artisan tinker --execute="dump(App\Models\User::whereNull('email_verified_at')->where(fn (\$q) => \$q->whereHas('passkeys')->orWhereNotNull('two_factor_secret'))->pluck('email', 'id'))"
```

(It prints emails, so it's for the operator's terminal only.) Note that someone who changed their email and hasn't re-verified yet also shows up, legitimately.

**Verify**: `grep -n "clear-sign-in-methods" DEPLOYING.md` → 1 match.

### Step 5: Tests

Create `tests/Feature/Auth/SignInMethodsNeedVerificationTest.php`. Skip with `skipUnlessFortifyHas` for each feature, as the existing auth tests do. Password confirmation is required on these routes, so start the session confirmed: `$this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])`.

1. **An unverified user can't get passkey registration options**: GET `route('passkey.registration-options')` → 403.
2. **…or store a passkey**: POST `route('passkey.store')` with any body → 403 (the middleware refuses before validation).
3. **…or enable 2FA**: POST `route('two-factor.enable')` → 403, and `two_factor_secret` stays null.
4. **A verified user can enable 2FA**: POST `route('two-factor.enable')` → not 403 (a redirect or 200), and `two_factor_secret` is set.
5. **A verified user can get passkey options**: GET `route('passkey.registration-options')` → 200.
6. **An unverified user can still remove 2FA**: give the user a `two_factor_secret` directly, DELETE `route('two-factor.disable')` → not 403.

In `tests/Feature/SetPasswordCommandTest.php`, add:

7. **`--clear-sign-in-methods` removes passkeys and 2FA.** Give the user a 2FA secret (`forceFill`) and a passkey row (`$user->passkeys()->create([...])` with `name`, a unique `credential_id` and a `credential` array; check `vendor/laravel/passkeys/src/Passkey.php` `$fillable`). Run the command with the option. The passkey count is 0 and `two_factor_secret` is null.
8. **Without the option, they're kept.**

**Revert checks** (restore after each, commit nothing from them):

- Remove the middleware from `bootstrap/app.php`: tests 1, 2 and 3 fail.

**Verify**: host tests, PHPStan and Pint pass.

## Done criteria

- [ ] Unverified users get 403 on the eight routes in `ROUTES`; verified users are unaffected
- [ ] Removing a passkey or 2FA still works for everyone
- [ ] `app:set-password --clear-sign-in-methods` removes passkeys and 2FA
- [ ] All 8 tests pass and the revert check fails as described
- [ ] Host tests, PHPStan and Pint pass
- [ ] `plans/README.md` row updated

## STOP conditions

- A route name in `ROUTES` doesn't exist (Step 1).
- `passkey.store` returns something other than 403 for an unverified user with the middleware in place. That would mean the route doesn't pass through the `web` group, and the guard has to go elsewhere.
- The passkey login routes start refusing anyone.

## Maintenance notes

- **Release note:** passkeys and two-factor authentication can only be added after the email is verified. Someone who changes their email keeps their existing passkeys and 2FA, but can't add new ones until they verify the new address.
- **Follow-up (not planned):** in invite mode, require the invitation link's token at sign-up, not just a matching email. This plan makes the known attack fail without it.
- If Fortify adds new routes that add a sign-in method (for example security keys under another name), add them to `ROUTES`.
