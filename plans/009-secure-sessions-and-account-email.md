# Plan 009: Signing out other devices really signs them out, and a stolen session can't take over the account

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Precondition**: this plan builds on settings and security work that was uncommitted when it was written: the sessions list, "sign out other devices" and the password-confirmation hook. Check first with `grep -n "function destroyOtherSessions" app/Http/Controllers/Settings/SecurityController.php`. If there's no match, STOP: the author's work hasn't been committed yet.
>
> **Drift check**: compare the "Current state" excerpts with the live files. On a mismatch, STOP.

## Status

- **Priority**: P1
- **Effort**: S–M
- **Risk**: MED. `AuthenticateSession` adds a hash comparison to every request. Existing sessions just gain a stored hash on their next request rather than being signed out, but staging should confirm that.
- **Depends on**: the author committing their settings and security work (see Precondition)
- **Category**: security
- **Planned at**: commit `f7bb714` plus the author's uncommitted working tree, 2026-10-30

## Why this matters

Four gaps let someone with a stolen session cookie keep or take over the account:

1. **"Sign out other devices" doesn't work against remember-me.** It deletes rows from the `sessions` table. But an attacker who logged in with "Remember me" is logged straight back in by their remember cookie, because Laravel's `AuthenticateSession` middleware is not enabled and `Auth::logoutOtherDevices()` is never called. If the session driver isn't `database`, the action does nothing at all, but still reports "Signed out of your other sessions".
2. **Changing the password ends no other sessions.**
3. **The account email can be changed with no password.** No verification email is sent, and the old address isn't told. Someone with a hijacked session can change the email, request a password reset to it, and own the account.
4. **Password checks aren't rate limited.** `POST /user/confirm-password` (Fortify) and `DELETE settings/profile` check the password without throttling, so a stolen session can be used to guess the password.

The sessions list also shows expired sessions as if they were still signed in.

## Current state

**`bootstrap/app.php`** middleware:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

    $middleware->web(append: [
        HandleAppearance::class,
        HandleInertiaRequests::class,
        AddLinkHeadersForPreloadedAssets::class,
    ]);
})
```

Grepping `app bootstrap routes config packages/core/src` for `auth.session|AuthenticateSession|logoutOtherDevices` finds nothing.

**How Laravel's `Illuminate\Session\Middleware\AuthenticateSession` works** (verified in vendor):

- It stores a hash of the user's password hash in the session, and logs the user out when that no longer matches the current password hash.
- For "remember me" logins, it checks the hash stored in the remember cookie in the same way.
- After the request, it re-stores the current hash, so the request that changes the password keeps its own session.
- `Auth::logoutOtherDevices($password)` rehashes the user's password, which invalidates every other session and remember cookie under that middleware, and fires `Illuminate\Auth\Events\OtherDeviceLogout`.

**`app/Http/Controllers/Settings/SecurityController.php`:**

```php
public function destroyOtherSessions(Request $request): RedirectResponse
{
    $request->validate(['password' => ['required', 'current_password']]);

    if (config('session.driver') === 'database') {
        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('id', '!=', $request->session()->getId())
            ->delete();
    }

    Inertia::flash('toast', ['type' => 'success', 'message' => __('Signed out of your other sessions.')]);

    return back();
}
```

There's also a private method that lists sessions (around lines 83-104). It selects rows by `user_id` with no `last_activity` filter. And there's `update(PasswordUpdateRequest $request)`, which only does `$request->user()->update(['password' => $request->password]);` and flashes "Password updated.".

**`routes/settings.php`:**

```php
Route::middleware(['auth', 'marketing.workspace:optional'])->group(function () {
    Route::redirect('settings', '/settings/profile');
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified', 'marketing.workspace:optional'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    ...
    Route::put('settings/password', [SecurityController::class, 'update'])->middleware('throttle:6,1')->name('user-password.update');
    Route::delete('settings/sessions', [SecurityController::class, 'destroyOtherSessions'])->middleware('throttle:6,1')->name('sessions.destroy-others');
    ...
});
```

**`app/Http/Controllers/Settings/ProfileController.php` `update`:**

```php
public function update(ProfileUpdateRequest $request): RedirectResponse
{
    $request->user()->fill($request->validated());

    if ($request->user()->isDirty('email')) {
        $request->user()->email_verified_at = null;
    }

    $request->user()->save();

    Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

    return to_route('profile.edit');
}
```

`app/Http/Requests/Settings/ProfileUpdateRequest.php` returns `$this->profileRules($this->user()->id)`, from `app/Concerns/ProfileValidationRules.php`. The `User` model implements `MustVerifyEmail`, so `sendEmailVerificationNotification()` is available.

**Fortify's confirm-password route** (`vendor/laravel/fortify/routes/routes.php`):

```php
Route::post(RoutePath::for('password.confirm', '/user/confirm-password'), [ConfirmablePasswordController::class, 'store'])
    ->middleware([config('fortify.auth_middleware', 'auth').':'.config('fortify.guard')])
    ->name('password.confirm.store');
```

It has no throttle, and Fortify's `limiters` config only covers login, two-factor and passkeys.

**Tests.** `tests/Feature/Settings/SecurityTest.php` has `test('other sessions can be signed out after confirming the password', ...)`. It uses `config(['session.driver' => 'database'])`, inserts rows into `sessions`, and asserts only the current user's other rows are deleted. `tests/Feature/Settings/ProfileUpdateTest.php` has the profile tests. The host uses Pest. The users factory password is `'password'`.

## Commands you will need

Run these from the repo root.

| Purpose             | Command                                                                 | Expected                                                 |
| ------------------- | ----------------------------------------------------------------------- | -------------------------------------------------------- |
| Settings tests      | `php artisan test --compact tests/Feature/Settings`                     | all pass                                                 |
| Host suite          | `php artisan test --compact`                                            | all pass                                                 |
| Core suite          | `cd packages/core && vendor/bin/pest --compact`                         | all pass                                                 |
| PHPStan             | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`            | 0 errors in touched files                                |
| Pint                | `vendor/bin/pint --dirty --format agent`, then `vendor/bin/pint --test` | exit 0                                                   |
| Types and lint      | `npm run types:check && npm run check`                                  | exit 0                                                   |
| Make a notification | `php artisan make:notification EmailAddressChanged --no-interaction`    | creates `app/Notifications/EmailAddressChanged.php`      |
| Make a middleware   | `php artisan make:middleware ThrottlePasswordChecks --no-interaction`   | creates `app/Http/Middleware/ThrottlePasswordChecks.php` |

## Scope

**In scope:**

- `bootstrap/app.php`
- `routes/settings.php` (throttles only)
- `app/Http/Controllers/Settings/SecurityController.php`
- `app/Http/Controllers/Settings/ProfileController.php` (`update` only)
- `app/Http/Requests/Settings/ProfileUpdateRequest.php`
- `app/Notifications/EmailAddressChanged.php` (new)
- `app/Http/Middleware/ThrottlePasswordChecks.php` (new)
- `resources/js/pages/settings/profile.tsx` (the password field)
- `tests/Feature/Settings/SecurityTest.php`
- `tests/Feature/Settings/ProfileUpdateTest.php`

**Out of scope:**

- `vendor/`, `config/fortify.php` and `packages/core`.
- Account deletion (plan 011).
- 2FA and passkey flows, which are already enforced server-side.
- Keeping the old email active until the new one is verified. This is deferred.

## Git workflow

- Branch: `advisor/009-session-security`.
- Commit messages should start with `fix(security): ...`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Turn on `AuthenticateSession`

In `bootstrap/app.php`, inside `withMiddleware`, add `\Illuminate\Session\Middleware\AuthenticateSession::class` to the `web` append list, before `HandleInertiaRequests::class`.

**Verify**: `php artisan test --compact` → all pass. If many tests start failing with unexpected logouts, STOP.

### Step 2: Make "sign out other devices" and password changes end other sessions

In `destroyOtherSessions`, after validation, call `Auth::logoutOtherDevices($request->string('password')->toString());` (import `Illuminate\Support\Facades\Auth`). Keep the database row deletion as cleanup, but it no longer decides whether to show the success message: the message is now true on every driver.

In `update` (password change), the middleware already ends other sessions because the password hash changed. Add a one-line comment saying so. Then, when `config('session.driver') === 'database'`, delete the user's other session rows the same way `destroyOtherSessions` does. To avoid duplicating that code, extract a private `forgetOtherSessionRows(Request $request): void` and call it from both methods.

**Verify**: `php artisan test --compact tests/Feature/Settings/SecurityTest.php` → all pass

### Step 3: Hide expired sessions from the list

In the private method that lists sessions, add:

```php
->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
```

**Verify**: `php artisan test --compact tests/Feature/Settings/SecurityTest.php` → all pass

### Step 4: Require the password to change the email, and tell both addresses

In `ProfileUpdateRequest::rules()`, merge in:

```php
'current_password' => [Rule::requiredIf(fn () => $this->emailIsChanging()), 'nullable', 'current_password'],
```

Add this private helper:

```php
private function emailIsChanging(): bool
{
    return mb_strtolower(trim((string) $this->input('email'))) !== mb_strtolower($this->user()->email);
}
```

Make sure `current_password` is not saved to the user. The controller fills from `validated()`, so pass `$request->safe()->except('current_password')` to `fill()`.

In `ProfileController::update`:

- capture `$previousEmail = $request->user()->getOriginal('email');` before filling;
- after saving, if the email changed, call `$request->user()->sendEmailVerificationNotification();`;
- also call `Notification::route('mail', $previousEmail)->notify(new EmailAddressChanged($request->user()->email));`.

Generate `EmailAddressChanged` with `make:notification`. It should have a `mail` channel only, take the new email in its constructor, and say: subject "Your email address was changed"; line "The email address on your account was changed to :email."; line "If you didn't do this, reset your password and contact support straight away."

In `resources/js/pages/settings/profile.tsx`, when the email input differs from the saved email, show a "Current password" field named `current_password`, with an `InputError` for it. Follow how the page renders its other fields and errors.

**Verify**: `php artisan test --compact tests/Feature/Settings/ProfileUpdateTest.php` → all pass, after updating any existing test that changes the email so it sends `current_password` (`'password'`)
**Verify**: `npm run types:check && npm run check` → exit 0

### Step 5: Throttle password checks

Create the `ThrottlePasswordChecks` middleware. When `$request->routeIs('password.confirm.store')`, delegate to `app(\Illuminate\Routing\Middleware\ThrottleRequests::class)->handle($request, $next, 6, 1, 'password-confirm')`; otherwise call `$next($request)`. Append it to the web group in `bootstrap/app.php`. Fortify's routes use the web group, so it runs for that route.

In `routes/settings.php`, add `->middleware('throttle:6,1')` to `profile.destroy`.

**Verify**: `php artisan test --compact` → all pass

## Test plan

Add these to `tests/Feature/Settings/SecurityTest.php`:

1. `test('signing out other devices invalidates every other session, including remembered ones')`.
    - Capture `$old = $user->password`.
    - Call the endpoint with the right password.
    - Expect `$user->fresh()->password !== $old` and `Hash::check('password', $user->fresh()->password)`.
    - Expect `Event::assertDispatched(OtherDeviceLogout::class)`. Call `Event::fake([OtherDeviceLogout::class])` first.
2. `test('a session from before a password change is signed out')`.
    - Run `$this->actingAs($user)->withSession(['password_hash_web' => 'stale'])->get(route('security.edit'))`.
    - Expect a redirect to the login page and `$this->assertGuest()`.
    - Check the session key name `password_hash_web` against `AuthenticateSession`, which builds it as `'password_hash_'.$defaultDriver`; the default driver is `web`.
3. `test('expired sessions are not listed')`.
    - Insert one row with `last_activity` older than `session.lifetime` minutes, and one recent row.
    - Expect the page's sessions prop to include only the recent one. Follow the existing "lists where the user is signed in" test for how to read the prop.
4. `test('password confirmation is rate limited')`.
    - Post a wrong password to `route('password.confirm.store')` 6 times, then expect the 7th to `assertStatus(429)`.

Add these to `tests/Feature/Settings/ProfileUpdateTest.php`:

5. `test('changing the email needs the current password')`.
    - A PATCH with a new email and no `current_password` gives `assertSessionHasErrors('current_password')`, and the email is unchanged.
    - With a wrong password, the same error.
6. `test('changing the email verifies the new address and tells the old one')`.
    - Call `Notification::fake()`, then PATCH with a new email and `current_password: 'password'`.
    - Expect `Notification::assertSentTo($user, VerifyEmail::class)`.
    - Expect `Notification::assertSentOnDemand(EmailAddressChanged::class)`, routed to the old email.
7. `test('changing only the name needs no password')`. Expect no errors.

**Revert checks**:

- Remove the `AuthenticateSession` line, confirm test 2 fails, then restore it.
- Remove the `current_password` rule, confirm test 5 fails, then restore it.

Report both results.

## Done criteria

- [ ] `grep -n "AuthenticateSession" bootstrap/app.php` returns a match.
- [ ] `grep -n "logoutOtherDevices" app/Http/Controllers/Settings/SecurityController.php` returns a match.
- [ ] `grep -n "current_password" app/Http/Requests/Settings/ProfileUpdateRequest.php` returns a match.
- [ ] The host and core suites pass, including 7 new tests.
- [ ] Types, lint, Pint and PHPStan are clean on the touched files.
- [ ] Only in-scope files are changed.

## STOP conditions

- The precondition fails, or an excerpt doesn't match.
- Turning on `AuthenticateSession` breaks more than the few tests that intentionally fake sessions. That would point to a session setup problem worth reporting.
- The web middleware group doesn't wrap Fortify's routes, so `ThrottlePasswordChecks` never runs for `password.confirm.store` and test 4 fails. Report it; don't edit vendor routes.

## Maintenance notes

- **Deploy impact:** with `AuthenticateSession`, sessions created before the deploy have no stored hash. They get one on their next request, so users should not be signed out, but test this in staging.
- Any future "change password" path must not bypass the middleware.
- Deferred:
    - keeping the old email active until the new one is verified;
    - an "undo email change" link in the notification.
