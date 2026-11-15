# Plan 047: Links in emails and the API spec always use APP_URL, never the request's Host

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in "STOP conditions" happens, stop and report. Don't
> improvise. When done, update this plan's row in `plans/README.md`, unless a
> reviewer dispatched you and told you they maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 6982215..HEAD -- bootstrap/app.php app/Providers/AppServiceProvider.php packages/core/src/Http/Controllers/ApiDocsController.php DEPLOYING.md .env.example tests/Feature/Auth/PasswordResetTest.php packages/core/tests/Feature/ApiDocsTest.php`
> If any of these changed, compare them with the "Current state" excerpts.
> On a mismatch, STOP. (Plan 046 changes only `composer.lock`.)

## Status

- **Priority**: P1. On some servers this is an account takeover.
- **Effort**: S
- **Risk**: LOW. Local dev keeps working as long as `APP_URL` matches the address you browse to (it does on Herd).
- **Depends on**: plan 046 (framework update) should land first; this plan doesn't need its code.
- **Category**: security
- **Planned at**: commit `6982215`, 2026-11-15

## Why this matters

Laravel builds absolute URLs from the incoming request's `Host` header. Fortify's password-reset email is sent during the request, not queued, and its link comes from `url(...)` (`vendor/laravel/framework/src/Illuminate/Auth/Notifications/ResetPassword.php:96`). On a server whose default virtual host accepts any `Host`, an attacker can request a reset for the victim's email with `Host: evil.example`. The victim gets a real reset email whose link, token included, points at the attacker's server. One click and the account is taken over.

The served API spec has the same problem: `ApiDocsController::spec()` puts `url(...)` into `servers[0].url` and sends `Cache-Control: public, max-age=3600`, so a shared cache can keep a poisoned base URL for an hour.

Invitation emails are queued (they use `APP_URL`), and tracking links use `app.url`, so they're already safe.

## Current state

**`bootstrap/app.php`** (host app), the whole middleware section:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

    $middleware->web(append: [
        HandleAppearance::class,
        SecurityHeaders::class,
        AuthenticateSession::class,
        RedirectWhenRegistrationClosed::class,
        ThrottlePasswordChecks::class,
        HandleInertiaRequests::class,
        AddLinkHeadersForPreloadedAssets::class,
    ]);
})
```

There's no `trustHosts()` and no `trustProxies()`. Nothing in `app/`, `packages/core/src/`, `bootstrap/` or `config/` calls `URL::forceRootUrl` or `URL::forceScheme`.

**`app/Providers/AppServiceProvider.php`**: `boot()` calls `$this->configureDefaults()`, which sets `Date::use(CarbonImmutable::class)`, `DB::prohibitDestructiveCommands(...)` and `Password::defaults(...)`. Add new defaults next to these.

**`packages/core/src/Http/Controllers/ApiDocsController.php:25`**:

```php
$spec['servers'] = [['url' => url((string) config('marketing.api_prefix'))]];

return response()->json($spec)->header('Cache-Control', 'public, max-age=3600');
```

**`DEPLOYING.md:57-70`**, "HTTPS and reverse proxies", tells operators to edit `bootstrap/app.php` and add `$middleware->trustProxies(at: ['10.0.0.0/8']);`.

**`.env.example:6`**: `APP_URL=http://localhost:8000`.

**Tests**:

- `tests/Feature/Auth/PasswordResetTest.php` uses `Notification::fake()`, posts to `route('password.email')` and checks with `Notification::assertSentTo($user, ResetPassword::class, function ($notification) { ... })`. Copy that shape.
- `packages/core/tests/Feature/ApiDocsTest.php:26` "the spec names this install's own base URL, even when the prefix is changed" is the spec test to copy.

**A Laravel detail you need:** `TrustHosts` does nothing while running unit tests or in the `local` environment (`shouldSpecifyTrustedHosts()`). So tests can't prove `trustHosts()`. They prove the forced root URL, which is the fix that matters. `trustHosts()` is defence in depth for production.

## Commands you will need

From the repo root (Git Bash: `php.bat`/`composer.bat`).

| Purpose    | Command                                                       | Expected                                      |
| ---------- | ------------------------------------------------------------- | --------------------------------------------- |
| Host tests | `php artisan test --compact`                                  | all pass                                      |
| One file   | `php artisan test --compact tests/Feature/Auth/PasswordResetTest.php` | all pass                              |
| Core tests | `cd packages/core && vendor/bin/pest --compact`               | all pass except the 7 Windows-only SES tests  |
| PHPStan    | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`  | 0 errors                                      |
| Pint       | `vendor/bin/pint --test`                                      | passes                                        |

## Scope

**In scope:**

- `bootstrap/app.php`
- `app/Providers/AppServiceProvider.php`
- `config/app.php`: one new `trusted_proxies` key only
- `.env.example`: one new commented `TRUSTED_PROXIES=` line
- `DEPLOYING.md`: the "HTTPS and reverse proxies" section
- `packages/core/src/Http/Controllers/ApiDocsController.php`
- `tests/Feature/Auth/PasswordResetTest.php`
- `packages/core/tests/Feature/ApiDocsTest.php`

**Out of scope:**

- `TrackingUrls`, invitations, email verification links. They already use `app.url` or are queued.
- Signed-URL validation (`URL::hasValidSignature`). Forcing the root URL changes what signed URLs are checked against, which is intended; don't change signing code.
- Fortify's own config.

## Git workflow

- Branch off `dev`: `advisor/047-pin-the-app-host`.
- Commits like `fix: links in emails always use APP_URL`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Force the root URL from `APP_URL`

In `AppServiceProvider::configureDefaults()`, add:

```php
// Absolute links (password resets, the API spec) must never take their host from the request.
$appUrl = (string) config('app.url');

if ($appUrl !== '') {
    URL::forceRootUrl($appUrl);

    if (str_starts_with($appUrl, 'https://')) {
        URL::forceScheme('https');
    }
}
```

Import `Illuminate\Support\Facades\URL`.

**Verify**: `php artisan test --compact` → all pass. Tests run with `APP_URL=http://localhost` (check `phpunit.xml`), the same host test requests use, so nothing should change. If a test fails because it expects a different host, STOP.

### Step 2: Trust only the `APP_URL` host, and proxies from the environment

In `bootstrap/app.php`, at the top of the `withMiddleware` closure:

```php
// Only APP_URL's host is accepted (Laravel skips this check locally and in tests).
$middleware->trustHosts();

// Proxies are configured with TRUSTED_PROXIES (comma-separated IPs or CIDR ranges, or * behind a load balancer
// whose address changes), so operators don't edit this file.
$proxies = config('app.trusted_proxies');

if (is_string($proxies) && $proxies !== '') {
    $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
}
```

In `config/app.php`, add near `'url'`:

```php
'trusted_proxies' => env('TRUSTED_PROXIES'),
```

`config()` is available inside the `withMiddleware` closure because it runs when the HTTP kernel boots, after config loads. Check that by running Step 2's verify. If `config()` returns null there even with the env var set, use `env('TRUSTED_PROXIES')` directly and say so in your report.

In `.env.example`, under `APP_URL`, add:

```
# Behind a reverse proxy or load balancer: its IPs or CIDR ranges, comma-separated (or * if they change).
# TRUSTED_PROXIES=
```

**Verify**: `php artisan test --compact` → all pass; then the new test in Step 5.2.

### Step 3: Core's spec uses `app.url`

In `ApiDocsController::spec()`, replace the `servers` line with:

```php
// From APP_URL, never the request's Host: the response is publicly cacheable.
$spec['servers'] = [['url' => rtrim((string) config('app.url'), '/').'/'.trim((string) config('marketing.api_prefix'), '/')]];
```

Core can't rely on the host app forcing the root URL, so it builds the URL itself.

**Verify**: `cd packages/core && vendor/bin/pest tests/Feature/ApiDocsTest.php` → all pass. If the existing test at line 26 fails because it expected `url()`'s form (for example a trailing slash), fix the **new** line's formatting to match it, not the test.

### Step 4: Docs

In `DEPLOYING.md`, replace the code block under "HTTPS and reverse proxies" with an explanation of `TRUSTED_PROXIES`:

- Set `APP_URL` to the exact public `https://` address. Links in emails and the API spec always use it, and requests for any other host are refused in production.
- Behind a proxy or load balancer, set `TRUSTED_PROXIES` to its addresses (`10.0.0.0/8`, or a comma-separated list), or `*` when they change and the app isn't reachable except through the proxy.
- Keep the existing paragraph about what breaks without trusted proxies.

Match the file's tone: short sentences, second person, no marketing words.

**Verify**: `grep -n "bootstrap/app.php" DEPLOYING.md` → no match in that section.

### Step 5: Tests

1. In `tests/Feature/Auth/PasswordResetTest.php`, add **"reset links use APP_URL even when the request names another host"**:

    ```php
    test('reset links use APP_URL even when the request names another host', function () {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('http://evil.example/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            expect($url)->toStartWith(rtrim(config('app.url'), '/').'/')->not->toContain('evil.example');

            return true;
        });
    });
    ```

    Check the forgot-password path first: `php artisan route:list --name=password.email`. Use the path it prints. If the post doesn't send a notification at all (the route isn't matched on another host), report it. That would mean the route is host-bound, which is fine, but the test then needs `withServerVariables(['HTTP_HOST' => 'evil.example'])` on the normal route instead. Try that before reporting.

2. The proxy wiring has no automated test: `bootstrap/app.php` runs once per test app, before a test can change config. Check it by hand and put the output in your report:
    - `TRUSTED_PROXIES=10.0.0.0/8 php artisan route:list --path=up` → boots and lists `/up`, no error.
    - `TRUSTED_PROXIES="10.0.0.0/8, 192.168.1.1" php artisan route:list --path=up` → same.

3. In `packages/core/tests/Feature/ApiDocsTest.php`, add **"the spec ignores the request's host"**: `config(['app.url' => 'https://mail.example.com'])`, then `$this->getJson('http://evil.example/'.config('marketing.api_prefix').'/openapi.json')` and assert `servers.0.url` is `https://mail.example.com/api/v1` (with whatever prefix the test config uses).

**Revert checks** (restore after each, commit nothing from them):

- Remove `URL::forceRootUrl(...)`: test 1 fails.
- Put `url(...)` back in `ApiDocsController`: test 3 fails.

**Verify**: host tests, core tests, PHPStan and Pint pass.

## Test plan

- Host: the spoofed-Host reset test (Step 5.1).
- Core: the spoofed-Host spec test (Step 5.3).
- Proxy wiring: a manual boot check (Step 5.2).

## Done criteria

- [ ] `grep -n "forceRootUrl" app/Providers/AppServiceProvider.php` → 1 match
- [ ] `grep -n "trustHosts\|trusted_proxies" bootstrap/app.php` → matches
- [ ] `grep -n "url(" packages/core/src/Http/Controllers/ApiDocsController.php` → no match for `url((string) config('marketing.api_prefix'))`
- [ ] The two new tests pass, and fail with their fix reverted
- [ ] `DEPLOYING.md` documents `TRUSTED_PROXIES` and no longer asks for edits to `bootstrap/app.php`
- [ ] Host tests, core tests (apart from the 7 SES failures), PHPStan and Pint pass
- [ ] `plans/README.md` row updated

## STOP conditions

- Forcing the root URL breaks an existing test (Step 1).
- Signed URLs (email verification, unsubscribe) stop validating in any existing test. That would mean the forced root differs from the test request host.
- `$middleware->trustHosts()` doesn't exist in the installed Laravel version.

## Maintenance notes

- **Release note:** `APP_URL` must be the exact public address. Requests for other hosts get a 400 in production, and every absolute link uses `APP_URL`. Installs behind a proxy should set `TRUSTED_PROXIES` instead of editing `bootstrap/app.php`; remove any old edit when upgrading.
- An install served on two domains would need `trustHosts(at: [...])` with both. Not supported today; say so if someone asks.
- Reviewer focus: the forced root runs in every environment, including tests. That's intended, so test hosts and `APP_URL` must agree.
