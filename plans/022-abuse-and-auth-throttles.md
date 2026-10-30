# Plan 022: Close the abuse and brute-force gaps — SES region SSRF, unbounded test sends, unthrottled auth endpoints

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat a86033c..HEAD -- packages/core/src/Sending/ProviderType.php packages/core/src/Http/Requests/ProviderRequest.php packages/core/src/Actions/Campaigns/SendTestEmail.php packages/core/src/Http/Controllers/CampaignController.php packages/core/src/Http/Controllers/ProviderController.php packages/core/routes/web.php app/Http/Middleware/ThrottlePasswordChecks.php app/Providers/FortifyServiceProvider.php`
> If plan 021 landed first, `bootstrap/app.php` will have changed; that's fine. Any other mismatch with the excerpts is a STOP.

## Status

- **Priority**: P1. Do this before the Christmas send.
- **Effort**: S–M
- **Risk**: LOW–MED. Tighter limits could annoy legitimate users; they're set generously.
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `a86033c`, 2026-10-30

## Why this matters

1. **SSRF through the SES region.** The Amazon SES provider's `region` credential is free text. AsyncAws builds the API host as `"https://email.$region.amazonaws.com"` for any region it doesn't recognise (`vendor/async-aws/ses/src/SesClient.php`, the `default` branch of `getEndpointMetadata`). So a value like `x@internal-host#` sends real email, and "Send test", to any host. That includes private ones, which gets around the `MARKETING_ALLOW_PRIVATE_SMTP_HOSTS` control.
2. **Unbounded test sends.** `POST campaigns/{campaign}/test` (`can:campaigns.manage`, which Editors have) sends the draft's arbitrary HTML to up to 5 arbitrary addresses per request, with no rate limit. It goes through the real provider even while the workspace is auto-paused, it ignores suppressions and the daily limit, and it leaves no record. Provider "Send test" (`providers.test`) has no limit either. A malicious or compromised Editor can spam or phish through the org's sending domain right before the big send.
3. **Brute-force and enumeration.**
    - `PATCH settings/profile` checks `current_password` when the email changes, with no throttle. A stolen session can guess passwords without limit, which gets around plan 009's `ThrottlePasswordChecks`.
    - `POST forgot-password` and `POST register` aren't throttled.
    - Forgot-password says "We can't find a user with that email address", which reveals which accounts exist.
    - 2FA codes have only a per-minute limit, with no daily cap. That's roughly 7,200 guesses a day.

## Current state

**`packages/core/src/Sending/ProviderType.php`:**

- line 76: SES's `new CredentialField('region', 'Region', default: 'eu-west-1')` has no `options`.
- line 110: Scaleway's `new CredentialField('region', 'Region', default: 'fr-par')` has no options either.
- line 135: the DSN is `'ses+api://'.$value('access_key_id').':'.$value('secret_access_key').'@default'.$query(['region' => $credentials['region'] ?? null])`.

`CredentialField` takes `options` as a list of `['value' => ..., 'label' => ...]`.

**`packages/core/src/Http/Requests/ProviderRequest.php`** builds the `credentials.<key>` rules in a loop:

```php
$rules['credentials.'.$field->key] = [
    $required ? 'required' : 'nullable',
    'string',
    'max:1000',
    ...($field->options === [] ? [] : [Rule::in(array_column($field->options, 'value'))]),
];
```

After that loop sits plan 014's SMTP host rule (`if ($type === ProviderType::Smtp && $field->key === 'host' && ! config(...))`). Follow the same shape for the region rule.

**`packages/core/src/Actions/Campaigns/SendTestEmail.php`:** `handle(Campaign $campaign, Workspace $workspace, array $emails)`. It resolves `$campaign->provider` (or throws "Choose a sending provider first."), builds a `[Test]` snapshot, and sends each address through `$this->transports->for($provider)->send(...)`. It checks nothing else.

**`packages/core/src/Http/Controllers/CampaignController.php` `test()`** (around line 187) validates `emails` (1–5 entries, `email:filter`) and calls the action.

**`packages/core/routes/web.php`:**

- line 107: `campaigns.test`, in the `can:campaigns.manage` group;
- around line 147: `providers.test`, in the `can:providers.manage` group.

**`packages/core/src/Sending/SendRateLimiter.php`** has `allowsToday(Provider $provider): bool` and `recordSend(Provider $provider)`. `Workspace::isSendingPaused()` exists. `Suppression::where('email', ...)->exists()` checks suppressions within the current workspace scope.

**`app/Http/Middleware/ThrottlePasswordChecks.php`:**

```php
public function handle(Request $request, Closure $next): Response
{
    if ($request->routeIs('password.confirm.store')) {
        return app(ThrottleRequests::class)->handle($request, $next, 6, 1, 'password-confirm');
    }

    return $next($request);
}
```

It's registered in the web group, so it sees Fortify's routes, whose names are `password.email` (POST forgot-password) and `register.store`. Settings routes are named `profile.update`.

**`app/Providers/FortifyServiceProvider.php` `configureRateLimiting()`:**

```php
RateLimiter::for('two-factor', function (Request $request) {
    return Limit::perMinute(5)->by($request->session()->get('login.id'));
});
```

**Fortify's forgot-password failure response:** `PasswordResetLinkController` resolves `app(FailedPasswordResetLinkRequestResponse::class, ['status' => $status])` for any failure, including "user not found". The contract is `Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse`. The success response is `SuccessfulPasswordResetLinkRequestResponse`; read both in `vendor/laravel/fortify/src/Http/Responses/` to mirror what the success response returns.

**Tests:**

- core: `packages/core/tests/Feature/ProviderTest.php`, `SendingTest.php` (`CapturingTransports` records sends);
- host: `tests/Feature/Auth/PasswordResetTest.php`, `RegistrationTest.php`, `TwoFactorChallengeTest.php`, `tests/Feature/Settings/ProfileUpdateTest.php`.

## Commands you will need

| Purpose        | Command                                         | Expected          |
| -------------- | ----------------------------------------------- | ----------------- |
| Core tests     | `cd packages/core && vendor/bin/pest --compact` | all pass          |
| Host tests     | `php artisan test --compact`                    | all pass          |
| PHPStan / Pint | as usual                                        | 0 errors / exit 0 |

## Scope

**In scope:**

- `packages/core/src/Http/Requests/ProviderRequest.php`
- `packages/core/src/Actions/Campaigns/SendTestEmail.php`
- `packages/core/routes/web.php` (throttle middleware on the two test routes only)
- `packages/core/src/MarketingServiceProvider.php` (a named rate limiter)
- `app/Http/Middleware/ThrottlePasswordChecks.php`
- `app/Providers/FortifyServiceProvider.php`
- `app/Http/Responses/GenericPasswordResetLinkResponse.php` (new)
- tests in the files listed above

**Out of scope:**

- The `ProviderType` field definitions. Keep regions free text so new AWS regions work; validate the format instead.
- The `ProviderController::test` error message.
- Login limiter changes beyond 2FA.

## Git workflow

- Branch: `advisor/022-abuse-and-throttles`.
- Make one commit per step, prefixed `fix(security): ...`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Validate cloud region codes

In `ProviderRequest::rules()`, when the type is `ProviderType::AmazonSes` or `ProviderType::Scaleway` and a `region` field is present, append `'regex:/^[a-z]{2}(-[a-z]+)+-\d+$/'` to the region rules. That matches `eu-west-1`, `us-gov-west-1` and `ap-southeast-2`. Scaleway's regions look like `fr-par` and `nl-ams`, with no trailing number. Use the same rule for both if it fits, or give Scaleway its own allow-list of `fr-par`, `nl-ams` and `pl-waw`.

Add a validation message: "Enter a region code such as eu-west-1."

**Verify**: the ProviderTest tests pass.

### Step 2: Bound test sends

- **A named limiter in `MarketingServiceProvider::registerRateLimiting()`:**

    ```php
    RateLimiter::for('marketing-test-sends', fn (Request $request) => Limit::perHour(20)->by('test-send:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    ```

- Add `->middleware('throttle:marketing-test-sends')` to the `campaigns.test` and `providers.test` routes.
- **In `SendTestEmail::handle`, before sending:**
    - if `$workspace->isSendingPaused()`, throw `ValidationException::withMessages(['emails' => __('Sending is paused for this workspace, so tests are paused too.')])`;
    - skip any address that has a `Suppression`, and if every address is suppressed, throw `__('These addresses are suppressed and can\'t be emailed.')`;
    - before each send, if `! app(SendRateLimiter::class)->allowsToday($provider)`, throw a validation error about the daily limit;
    - after each successful send, call `recordSend($provider)`.

    Use constructor injection for `SendRateLimiter`.

**Verify**: SendingTest and ProviderTest pass.

### Step 3: Throttle the remaining password and identity endpoints

- **Extend `ThrottlePasswordChecks`:**
    - `profile.update` with `PATCH` gets 6 per minute, prefix `profile-update`;
    - `password.email` gets 5 per minute, keyed by IP, prefix `password-email`;
    - `register.store` gets 5 per minute, keyed by IP, prefix `register`.

    `ThrottleRequests` keys on the user for authenticated requests and the IP for guests by default, which is what we want. Update the class PHPDoc to list every route it covers.

- **Generic forgot-password response.** Create `app/Http/Responses/GenericPasswordResetLinkResponse.php`, implementing `FailedPasswordResetLinkRequestResponse`. It returns exactly what the successful response returns: the same redirect-back with the `status` flash, using `__(Password::RESET_LINK_SENT)`, or JSON for JSON requests. Bind it in `FortifyServiceProvider::register()` with `$this->app->singleton(FailedPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);`.
- **Daily 2FA cap.** Change the `two-factor` limiter to return two limits:

    ```php
    [Limit::perMinute(5)->by($id), Limit::perDay(50)->by('day:'.$id)]
    ```

    where `$id` is `$request->session()->get('login.id')`.

**Verify**: `php artisan test --compact` → all pass. The existing PasswordResetTest may assert the "can't find a user" error; if so, update it to the generic status, and say so.

## Test plan

**Core:**

1. `test('ses regions must be real region codes')`: storing an SES provider with region `x@evil.test#` gives `assertSessionHasErrors('credentials.region')`, and `eu-west-1` is accepted.
2. `test('test sends are refused while sending is paused')`: pause the workspace, post a test, and expect an error with nothing sent.
3. `test('test sends skip suppressed addresses and count against the daily limit')`:
    - a suppressed address alone gives an error;
    - with `daily_limit` 1, the first test sends and the second is refused.
4. `test('test sends are rate limited')`: 20 requests succeed and the 21st returns 429. Use one address per request; `CapturingTransports` makes sends instant.

**Host:**

5. `test('changing the email is rate limited')`: 6 PATCHes with a wrong `current_password` give 302 with errors, and the 7th gives 429.
6. `test('forgot password does not reveal whether an account exists')`: the response for an unknown email has the same `status` session value as for a known one, and `assertSessionHasNoErrors()`.
7. `test('forgot password and register are rate limited')`: the 6th attempt from the same IP gives 429.
8. `test('two factor attempts are capped per day')`: use `RateLimiter::hit` 50 times on the daily key, or loop the requests, and expect a 429 at the cap. Use the test's session `login.id`.

**Revert checks**, reporting each result:

- remove the region regex and confirm test 1 fails;
- remove the paused check and confirm test 2 fails;
- remove the `profile.update` throttle and confirm test 5 fails.

## Done criteria

- [ ] `grep -n "regex:" packages/core/src/Http/Requests/ProviderRequest.php` → match
- [ ] `grep -n "marketing-test-sends" packages/core/routes/web.php packages/core/src/MarketingServiceProvider.php` → matches in both
- [ ] `grep -n "profile.update\|password.email\|register.store" app/Http/Middleware/ThrottlePasswordChecks.php` → 3 matches
- [ ] Core and host suites pass with the new tests; PHPStan reports 0 errors; Pint is clean
- [ ] Only in-scope files changed

## STOP conditions

- An excerpt doesn't match.
- A Fortify route name differs from `password.email` or `register.store`. Check with `php artisan route:list --name=password` and `--name=register`, and use the real names; STOP only if no matching route exists.
- Binding the generic reset response breaks the success path.

## Maintenance notes

- `MARKETING_ALLOW_PRIVATE_SMTP_HOSTS` and the region format check together close the outbound host routes we found. Any new provider field that ends up in a hostname needs the same treatment.
- Test sends are now limited to 20 per user per hour. If people need more, raise the limit in the limiter, but keep the pause and suppression checks.
- Follow-up: optionally restrict test recipients to workspace members' addresses on shared installs.
