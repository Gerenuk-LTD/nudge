# Plan 014: Close the remaining small security gaps — webhook SSRF, private SMTP hosts, CSV formulas, API 404s and invalid-token floods

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 0ac75d7..HEAD -- packages/core/src/Automations/WebhookCaller.php packages/core/src/Http/Requests/ProviderRequest.php packages/core/src/Http/Controllers/SuppressionController.php packages/core/src/Http/Controllers/ImportController.php packages/core/src/Http/Middleware/AuthenticateApiToken.php packages/core/src/MarketingServiceProvider.php packages/core/config/marketing.php`
> Expected: no output. On a mismatch with the excerpts, STOP.

## Status

- **Priority**: P2
- **Effort**: M (five small, independent fixes)
- **Risk**: LOW–MED
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `0ac75d7`, 2026-10-30

## Why this matters

These five gaps were each low-to-medium on their own, and each is cheap to close:

1. **Automation webhooks can reach internal addresses.** The webhook step checks that the target host is public, but only looks up IPv4 (`gethostbynamel`), and the HTTP client then resolves the host again on its own. So a host with a private IPv6 address, or one whose DNS changes between the check and the request (DNS rebinding), gets past the check.
2. **SMTP providers accept any host.** On a hosted, multi-tenant install, a workspace owner could point the SMTP host at internal services and use "Send test" to probe them, since the raw connection error is shown back. Self-hosters legitimately use local relays such as Postfix on `localhost`, so this must be a config switch that allows private hosts by default.
3. **CSV exports can carry spreadsheet formulas.** Suppression and import-error exports write values (provider bounce text, uploaded row values) as-is. A cell starting with `=`, `+`, `-` or `@` runs as a formula when staff open the file in Excel or Sheets.
4. **API 404s leak internal class names.** A missing record returns `No query results for model [Gerenuk\Marketing\Models\Contact]`.
5. **Invalid API tokens are never rate limited per IP.** Each made-up token gets its own fresh allowance and its own database lookup, so one IP can flood the API with lookups.

## Current state

All paths below are relative to `packages/core/` unless they say otherwise.

**1. `src/Automations/WebhookCaller.php`** (whole file):

```php
class WebhookCaller
{
    public function __construct(private readonly Http $http) {}

    public function post(string $url, array $payload): int
    {
        $this->ensurePublic($url);

        return $this->http->timeout(10)->acceptJson()->withoutRedirecting()->post($url, $payload)->status();
    }

    private function ensurePublic(string $url): void
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($host)) {
            throw new RuntimeException('Webhook URLs must use https.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            throw new RuntimeException("Could not resolve {$host}.");
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException("{$host} points at a private address.");
            }
        }
    }
}
```

`Http` is `Illuminate\Http\Client\Factory`. Laravel's HTTP client passes options through to Guzzle with `->withOptions([...])`. Guzzle's curl handler accepts `'curl' => [CURLOPT_RESOLVE => ['host:port:ip']]`, which pins the connection to an address you choose.

The existing test `tests/Feature/AutomationTest.php` `test('webhook steps refuse private addresses', ...)` shows the pattern and uses `Http::fake()`.

**2. SMTP.** `src/Sending/ProviderType.php` defines SMTP's credential fields as `host`, `port`, `encryption` (`starttls`/`tls`), `username` and `password`. `src/Http/Requests/ProviderRequest.php` builds the `credentials.*` rules in a loop over `$type->fields()`. `ProviderController::test` turns any send exception into `ValidationException::withMessages(['to' => __('Sending failed: :message', ...)])`. `config/marketing.php` has a `sending` array (`'queue' => ..., 'auto_pause' => [...]`).

**3. CSV exports.**

- `src/Http/Controllers/SuppressionController.php` `export()` writes `[$suppression->email, $suppression->reason->value, $suppression->detail, $suppression->created_at?->toDateString()]` with `fputcsv(..., escape: '')`.
- `src/Http/Controllers/ImportController.php` `errors()` writes `[$error->row_number, $error->email, $error->message]`.

**4. API 404s.** Core API routes live under `config('marketing.api_prefix')` (`api/v1`). The host's `bootstrap/app.php` renders JSON for `api/*`. Laravel's exception handler (`Illuminate\Foundation\Exceptions\Handler`) has `renderable(callable)`, which a package can call from its service provider: `$this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderable(fn (NotFoundHttpException $e, Request $request) => ...)`.

**5. Rate limiting.** `src/MarketingServiceProvider.php` `registerRateLimiting()`:

```php
RateLimiter::for('marketing-api', function (Request $request): Limit {
    $token = $request->bearerToken();

    return Limit::perMinute(config('marketing.api_requests_per_minute'))
        ->by($token !== null ? 'token:'.hash('sha256', $token) : 'ip:'.$request->ip());
});
```

`src/Http/Middleware/AuthenticateApiToken.php` returns `response()->json(['message' => 'Unauthenticated.'], 401)` when the token is missing, unknown or expired.

**Tests.** Pest, under `tests/Feature/`. `ApiTest.php` has an `apiToken($workspace, $user, $permissions)` helper. `SuppressionTest.php` and `ImportTest.php` cover the exports; read them for their setup. `ProviderTest.php` creates providers over HTTP.

## Commands you will need

Run these from the repo root.

| Purpose    | Command                                                                  | Expected |
| ---------- | ------------------------------------------------------------------------ | -------- |
| Core tests | `cd packages/core && vendor/bin/pest --compact`                          | all pass |
| One file   | `cd packages/core && vendor/bin/pest --compact tests/Feature/<File>.php` | all pass |
| Host tests | `php artisan test --compact`                                             | all pass |
| PHPStan    | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`             | 0 errors |
| Pint       | `vendor/bin/pint --dirty --format agent` then `vendor/bin/pint --test`   | exit 0   |

## Scope

**In scope:**

- `src/Automations/WebhookCaller.php`
- `src/Http/Requests/ProviderRequest.php`
- `config/marketing.php`
- `src/Support/CsvCell.php` (new)
- `src/Http/Controllers/SuppressionController.php` (`export` only)
- `src/Http/Controllers/ImportController.php` (`errors` only)
- `src/MarketingServiceProvider.php`
- `src/Http/Middleware/AuthenticateApiToken.php`
- Tests: `tests/Feature/AutomationTest.php`, `ProviderTest.php`, `SuppressionTest.php`, `ImportTest.php` and `ApiTest.php`

**Out of scope:**

- The builder's link fields. They were rejected: HTML blocks can already contain any markup, and every preview is sandboxed.
- `ProviderController::test`'s error message. Keep it, because users need it to debug SMTP, and private hosts are now gated.
- The host's `bootstrap/app.php`.

## Git workflow

- Branch: `advisor/014-security-hygiene`.
- Make one commit per numbered fix, prefixed `fix(security): ...`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Pin automation webhooks to a vetted address

Rewrite `WebhookCaller`:

- **`ensurePublic(string $url): array{host: string, port: int, ip: string}`**:
    - Keep the https and host checks.
    - Port is `$parts['port'] ?? 443`.
    - Resolve every address. For an IP literal, use it directly. Otherwise use `dns_get_record($host, DNS_A | DNS_AAAA) ?: []` and collect each record's `ip` (A) or `ipv6` (AAAA).
    - Keep the "Could not resolve" and "points at a private address" exceptions, and apply the private/reserved check to every address.
    - Return the first address as the one to connect to.
- **`post()`**: call `ensurePublic`, then pass `->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])` before `->post(...)`. For IPv6, wrap the address in brackets: `[{$ip}]`.
- Update the class docblock to mention that the connection is pinned to the checked address, so DNS can't change between the check and the request.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/AutomationTest.php` → all pass

### Step 2: Gate private SMTP hosts behind config

- In `config/marketing.php`'s `sending` array, add `'allow_private_smtp_hosts' => (bool) env('MARKETING_ALLOW_PRIVATE_SMTP_HOSTS', true),`. Above it, add a comment: "Self-hosted installs often relay through a local mail server. On a shared, multi-tenant install, set this to false so workspace owners can't point SMTP at internal services."
- In `ProviderRequest::rules()`, when the type is `ProviderType::Smtp` and the config is false, add a closure rule to `credentials.host`:
    - resolve the host (a literal IP, or `gethostbynamel` plus `dns_get_record(..., DNS_AAAA)`);
    - fail with "This host is on a private network, which this installation doesn't allow." when any address is private or reserved, or when nothing resolves.
    - Put the resolution in a small private method on the request.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/ProviderTest.php` → all pass

### Step 3: Neutralise formulas in CSV exports

Create `src/Support/CsvCell.php`:

```php
/**
 * Makes a value safe to open in a spreadsheet: text starting with a formula character is prefixed with an
 * apostrophe, so Excel and Sheets show it instead of running it.
 */
class CsvCell
{
    public static function safe(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }
}
```

Wrap every cell written by `SuppressionController::export` and `ImportController::errors` in `CsvCell::safe(...)`. Leave the header rows alone.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/SuppressionTest.php tests/Feature/ImportTest.php` → all pass

### Step 4: Generic 404s for the API

In `MarketingServiceProvider::boot()`, register a renderable on the exception handler. For `Symfony\Component\HttpKernel\Exception\NotFoundHttpException` on requests where `$request->is(trim(config('marketing.api_prefix'), '/').'/*')`, return `response()->json(['message' => 'Not found.'], 404)`. For anything else, return `null` so normal rendering continues. Put this in a private `registerApiErrors()` method, called alongside the other `register*` calls.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/ApiTest.php` → all pass

### Step 5: Rate-limit failed API authentication per IP

In `AuthenticateApiToken::handle`:

- before looking up the token, if `RateLimiter::tooManyAttempts('marketing-api-auth:'.$request->ip(), 30)`, return `response()->json(['message' => 'Too many attempts.'], 429)`;
- on every 401 path, call `RateLimiter::hit('marketing-api-auth:'.$request->ip(), 60)`.

Valid tokens never count against this limit.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/ApiTest.php` → all pass

## Test plan

1. **`AutomationTest.php`**, `test('a webhook host with a private IPv6 address is refused')`. If a deterministic AAAA lookup isn't possible, test the IP-literal path instead: `https://[::1]/hook` and `https://[fd00::1]/hook` must be refused before any request (`Http::assertNothingSent()`). Name the test after what it actually checks.
2. **`ProviderTest.php`**, `test('private smtp hosts can be refused by config')`. With `config(['marketing.sending.allow_private_smtp_hosts' => false])`, storing an SMTP provider with host `127.0.0.1` gives `assertSessionHasErrors('credentials.host')`. With the default config, the same request succeeds.
3. **`SuppressionTest.php`**, `test('exports neutralise spreadsheet formulas')`. Suppress an address with detail `=HYPERLINK("http://x")`, call the export, and assert the streamed CSV contains `'=HYPERLINK` (use `$response->streamedContent()`).
4. **`ImportTest.php`**: the same for the import-errors download. Use a row whose email is `=1+1`; it fails validation and lands in the errors file.
5. **`ApiTest.php`**, `test('a missing record returns a plain not found message')`. Expect `getJson(route('marketing.api.contacts.show', 'nobody@example.com'))` to give `assertNotFound()->assertExactJson(['message' => 'Not found.'])`.
6. **`ApiTest.php`**, `test('repeated bad tokens from one address are rate limited')`. 30 requests with random tokens give 401, and the 31st gives 429.

Revert check: for tests 3 and 6, temporarily remove the fix, confirm the test fails, then restore it. Report the results.

## Done criteria

- [ ] `grep -n "CURLOPT_RESOLVE" packages/core/src/Automations/WebhookCaller.php` matches
- [ ] `grep -n "allow_private_smtp_hosts" packages/core/config/marketing.php` matches
- [ ] `grep -rn "CsvCell::safe" packages/core/src/Http/Controllers` shows matches in both controllers
- [ ] Core and host suites pass with at least 6 new tests; PHPStan reports 0 errors; Pint is clean
- [ ] Only in-scope files changed

## STOP conditions

- An excerpt doesn't match.
- `withOptions(['curl' => ...])` isn't accepted by the installed HTTP client, or `Http::fake()` breaks because of it. Report this rather than dropping the pinning.
- An existing test relies on the raw 404 message or on unlimited bad-token attempts.

## Maintenance notes

- Any new outbound HTTP call to a user-supplied URL should go through the same resolve, check and pin approach. Consider extracting it if a second caller appears.
- Hosted multi-tenant deployments should set `MARKETING_ALLOW_PRIVATE_SMTP_HOSTS=false`. Document this in the deployment notes.
