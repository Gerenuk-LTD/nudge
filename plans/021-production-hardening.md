# Plan 021: Every self-hosted install is production-safe by default, with a deployment guide

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat a86033c..HEAD -- .env.example config/inertia.php config/session.php config/auth.php bootstrap/app.php resources/views/app.blade.php`
> Expected: no output. On a mismatch with the excerpts, STOP.

## Status

- **Priority**: P0. This must land before anyone deploys.
- **Effort**: S–M
- **Risk**: LOW for production. Local development changes slightly: developers set `APP_ENV=local` and `APP_DEBUG=true` in their own `.env`.
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `a86033c`, 2026-10-30

## Why this matters

People deploy this app by copying `.env.example`. That file ships `APP_ENV=local`, which has these effects:

- **Inertia DevTools is enabled with no login check.** `vendor/inertiajs/inertia-laravel/src/DevTools/DevTools.php` enables DevTools when `config('inertia.devtools.enabled')` is null and the environment is `local`. Its `Http/Authorize.php` middleware returns `true` for every request in `local`. `php artisan route:list` shows `GET _inertia/devtools/entries` and `GET _inertia/devtools/entries/{id}`. These replay recorded Inertia props and request bodies (contacts, member emails, newly created API tokens, provider form submissions) to **anyone**. Redaction only matches a few exact key names.
- **Weak passwords are accepted.** `Password::defaults` only enforces the strong rules in production (`app/Providers/AppServiceProvider.php:40-48`).
- **Destructive database commands aren't blocked.** `DB::prohibitDestructiveCommands` is off outside production.
- **Laravel Boost's dev routes register** if dev dependencies are installed.

The other defaults are unsafe too:

- **`APP_DEBUG=true`** shows full error pages, with SQL bindings and request data, to anonymous visitors.
- **`LOG_LEVEL=debug`** and **`LOG_STACK=single`** keep one log file that grows forever.
- **No Secure flag on the session cookie** by default.
- **Remember-me lasts 400 days.**
- **Inertia history isn't encrypted.** Back-button pages can show data after logout.
- **No security headers** at all: no frame protection, nosniff, HSTS or referrer policy.
- **There is no deployment documentation.**

## Current state

**`.env.example`** (top):

```
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

# Who can sign up: invite (default, invited emails only), closed, or open.
REGISTRATION_MODE=invite
...
LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug
```

Further down are `SESSION_DRIVER=database`, `SESSION_LIFETIME=120`, `SESSION_ENCRYPT=false` and `MAIL_MAILER=log`.

**`config/inertia.php`** exists and has an `'ssr'` key, but no `'devtools'` or `'history'` keys. The vendor default config (`vendor/inertiajs/inertia-laravel/config/inertia.php`) defines both. Read it for the exact key shape, which includes `devtools.enabled` and `history.encrypt`.

**`config/session.php:172`**: `'secure' => env('SESSION_SECURE_COOKIE'),`

**`config/auth.php:40-45`**:

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
],
```

`Illuminate\Auth\AuthManager` (line 147) applies `$config['remember']`, in minutes, when it's present.

**`bootstrap/app.php`**:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

    $middleware->web(append: [
        HandleAppearance::class,
        AuthenticateSession::class,
        ThrottlePasswordChecks::class,
        HandleInertiaRequests::class,
        AddLinkHeadersForPreloadedAssets::class,
    ]);
})
```

`resources/views/app.blade.php` has an inline `<script>` for the theme, and the email previews use `<iframe srcDoc sandbox="">`. A CSP is out of scope for this plan because the inline script needs a nonce. Frame protection must still allow the app's own previews: `SAMEORIGIN` does.

**Conventions.** Host middleware lives in `app/Http/Middleware`. See `ThrottlePasswordChecks.php` for style: one-sentence PHPDoc, typed `handle`. Create new middleware with `php artisan make:middleware --no-interaction`.

## Commands you will need

| Purpose              | Command                                                                                                                              | Expected                             |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------ |
| Host tests           | `php artisan test --compact`                                                                                                         | all pass                             |
| Core tests           | `cd packages/core && vendor/bin/pest --compact`                                                                                      | all pass                             |
| PHPStan / Pint       | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`; `vendor/bin/pint --dirty --format agent` then `vendor/bin/pint --test` | 0 errors / exit 0                    |
| Frontend             | `npm run check && npm run build && CI=true npm run test`                                                                             | exit 0                               |
| DevTools routes gone | `APP_ENV=production php artisan route:list --path=_inertia`                                                                          | no routes, or the command shows none |

## Scope

**In scope:**

- `.env.example`
- `config/inertia.php`
- `config/session.php` (the `secure` default only)
- `config/auth.php` (`remember` on the web guard)
- `app/Http/Middleware/SecurityHeaders.php` (new)
- `bootstrap/app.php` (register it)
- `DEPLOYING.md` (new, at the repo root)
- `tests/Feature/SecurityHeadersTest.php` (new)
- one test proving DevTools stays off unless explicitly enabled

**Out of scope:**

- A Content-Security-Policy. It needs nonces for the inline theme script and Vite; it's a follow-up.
- Trusted-proxy code. Document it only, because proxy addresses vary per install.
- Plans 022–025.

## Git workflow

- Branch: `advisor/021-production-hardening`.
- Commits:
    - `fix(security): ship production-safe defaults and turn off inertia devtools unless asked`
    - `feat(security): add baseline security headers`
    - `docs: add a deployment guide`
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Safe defaults

- In `.env.example`, set:
    - `APP_ENV=production`, `APP_DEBUG=false`, `LOG_STACK=daily`, `LOG_LEVEL=warning`;
    - add `SESSION_SECURE_COOKIE=true` and change to `SESSION_ENCRYPT=true` in the session block.

    Above `APP_ENV`, add the comment: `# For local development set APP_ENV=local and APP_DEBUG=true in your own .env. Never deploy with local or debug on.`. Leave `MAIL_MAILER=log`, but add the comment `# Set a real mailer in production; "log" writes password-reset and invitation links to the log file.`

- In `config/inertia.php`, add `devtools` and `history`. Laravel's `mergeConfigFrom` (used in `vendor/inertiajs/inertia-laravel/src/ServiceProvider.php:35`) merges **top-level keys only**, so an app-level `devtools` key replaces the vendor's entire block. Therefore:
    - **Copy the whole `'devtools' => [...]` block** from `vendor/inertiajs/inertia-laravel/config/inertia.php`, around line 170: `enabled`, `except`, `storage` and any gate key. Change only `enabled` to `(bool) env('INERTIA_DEVTOOLS_ENABLED', false)`.
    - **Copy the vendor `'history'` block the same way,** changing `encrypt` to `(bool) env('INERTIA_ENCRYPT_HISTORY', true)`.
- In `config/session.php`, change `'secure' => env('SESSION_SECURE_COOKIE'),` to `'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'),`. Use `env()` here, not `app()`, because config can't call into the app.
- In `config/auth.php`, add `'remember' => (int) env('AUTH_REMEMBER_MINUTES', 43200),` to the `web` guard (30 days).

**Verify**: `php artisan test --compact` → all pass. If tests now fail because history encryption or secure cookies are on, set `INERTIA_ENCRYPT_HISTORY=false` / `SESSION_SECURE_COOKIE=false` in `phpunit.xml` `<php>` only if needed, and report it.
**Verify**: `APP_ENV=production php artisan route:list --path=_inertia` shows no DevTools routes. If routes still register in production even when DevTools is disabled, report that, but it's acceptable if `Authorize` denies them; check it with a test.

### Step 2: Security headers

Create `app/Http/Middleware/SecurityHeaders.php`. It adds these headers to every web response:

- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- `Strict-Transport-Security: max-age=31536000; includeSubDomains`, only when `$request->isSecure()`

Don't overwrite a header a response already set. Register it in `bootstrap/app.php`'s `web(append: [...])` list, right after `HandleAppearance::class`.

**Verify**: `php artisan test --compact` → all pass

### Step 3: Deployment guide

Write `DEPLOYING.md`, concise and practical, with headed sections for:

1. **Requirements:** PHP 8.4, MySQL 8.4, Redis, Node for building assets, a queue worker and the scheduler.
2. **Install:**
    - `composer install --no-dev --optimize-autoloader`;
    - `npm ci && npm run build`;
    - `cp .env.example .env` then `php artisan key:generate`;
    - `php artisan migrate --force`;
    - `php artisan config:cache route:cache view:cache`.
3. **Environment values that must be set:**
    - the `APP_*` variables;
    - DB, Redis and queue (`QUEUE_CONNECTION=redis`; the `retry_after` note from `config/queue.php`);
    - a real `MAIL_MAILER`;
    - `MARKETING_TRACKING_URL` if tracking runs on another domain;
    - `REGISTRATION_MODE` (invite, closed or open, and the first-user rule);
    - `MARKETING_ALLOW_PRIVATE_SMTP_HOSTS=false` on shared installs.
4. **HTTPS and reverse proxies:** terminate TLS, set `APP_URL` to `https://`, and if behind a proxy or load balancer configure trusted proxies with `$middleware->trustProxies(at: [...])` in `bootstrap/app.php`. Without that, rate limits and session IPs see the proxy's address.
5. **Workers and scheduler:** a supervisor example for `php artisan queue:work --tries=3 --timeout=3600`, and the cron line for `schedule:run`.
6. **Sending setup:** SPF, DKIM and DMARC on the From domain; the provider webhook URL and signing key (signing-key providers refuse webhooks without one; SES pins its SNS topic).
7. **Keys:** keep `APP_KEY` secret and backed up. Rotating it needs `APP_PREVIOUS_KEYS`; see plan 025's note once it lands. Write: "Rotating APP_KEY currently breaks unsubscribe links in emails already sent; do not rotate it until plan 025 lands."
8. **Upgrading:** pull, install, build, then `php artisan migrate --force` and the cache commands; restart workers.

Keep it factual. Don't document features that don't exist.

**Verify**: `npx vp fmt DEPLOYING.md` then `npm run check` → exit 0

## Test plan

1. **`tests/Feature/SecurityHeadersTest.php`**, `test('web responses carry the baseline security headers')`: `get('/')` asserts the four headers. HSTS is absent on `http`. A request made with `https://` (`$this->get('https://localhost/')`, or `withServerVariables(['HTTPS' => 'on'])`) has HSTS.
2. **`test('inertia devtools is off unless explicitly enabled')`**:
    - with `app()->detectEnvironment(fn () => 'local')` (or `config(['app.env' => 'local'])`, whichever DevTools reads; see `DevTools::enabled()`) and no `INERTIA_DEVTOOLS_ENABLED`, `\Inertia\DevTools\DevTools::enabled()` is `false`;
    - with `config(['inertia.devtools.enabled' => true])` it's `true`.
3. **Revert check:** remove the `devtools` key from `config/inertia.php`, confirm test 2 fails, then restore it.

## Done criteria

- [ ] `grep -nE "^APP_ENV=production|^APP_DEBUG=false" .env.example` → 2 matches
- [ ] `grep -n "INERTIA_DEVTOOLS_ENABLED" config/inertia.php` → match
- [ ] `test -f DEPLOYING.md && test -f app/Http/Middleware/SecurityHeaders.php`
- [ ] Host and core suites pass with the new tests; PHPStan reports 0 errors; Pint is clean; frontend checks pass
- [ ] Only in-scope files changed

## STOP conditions

- An excerpt doesn't match.
- Turning on history encryption or the secure-cookie default breaks the dev server or the tests in a way that a `phpunit.xml` env line can't fix.
- `config/inertia.php` replaces the vendor config entirely, and adding `devtools` drops required sibling keys you can't identify.

## Maintenance notes

- **CSP is the next step:** use `Vite::useCspNonce()`, put the nonce on the inline theme script in `app.blade.php`, and allow `frame-src 'self'` for the previews.
- **Every new env flag that weakens security should default to the safe value,** with the opt-out documented in `DEPLOYING.md`.
- **Release note:** existing installs that copied the old `.env.example` should check that `APP_ENV` and `APP_DEBUG` are set safely. If an install ran with `local` in public, rotate provider credentials and API tokens.
