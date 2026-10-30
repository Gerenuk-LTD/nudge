# Plan 012: CI goes green — the last three PHPStan errors and JSON key-order assertions on MySQL

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat ee7cbc9..HEAD -- app/Http/Controllers/Settings/SecurityController.php database/seeders/SendTestSeeder.php packages/core/src/Support/HomeNotices.php packages/core/tests/Feature/EmailTemplateTest.php`
> Expected: no output. On a mismatch with the excerpts, STOP.

## Status

- **Priority**: P1. CI is the gate every other plan relies on.
- **Effort**: S
- **Risk**: LOW. The fixes are type-only, plus test assertions.
- **Depends on**: none
- **Category**: dx / tests
- **Planned at**: commit `ee7cbc9`, 2026-10-30

## Why this matters

The first CI run on `dev` (GitHub Actions run `41047667867`) failed in both jobs:

- **`ci`**: PHPStan level 7 reports 3 errors in recently committed code. They are type-precision issues, not runtime bugs.
- **`core-mysql`**: 3 tests in `EmailTemplateTest` fail with "Failed asserting that two arrays are identical". MySQL's `JSON` column type stores object keys in its own canonical order. For example, `{"version":1,"settings":[],"blocks":[...]}` comes back with `blocks` first, and inside a block `html` comes before `type`. The data is identical; only the key order differs. The tests compare with Pest's `toBe` (strict `===`), which also compares key order. The builder reads this state by key, so the order doesn't matter to the product. The fix belongs in the tests: use `toEqual`, which compares associative arrays without regard to key order.

Until both jobs pass, CI can't catch regressions.

## Current state

**PHPStan output** (`vendor/bin/phpstan analyse --memory-limit=1G --no-progress`):

1. `app/Http/Controllers/Settings/SecurityController.php:89`: `sessions()` should return `list<array{id: string, browser: string|null, platform: string|null, ip_address: string|null, last_active_at: string, current: bool}>|null` but returns `array<int, array{..., ip_address: mixed, ...}>`.

    ```php
    return DB::connection(config('session.connection'))
        ->table(config('session.table', 'sessions'))
        ->where('user_id', $request->user()->getAuthIdentifier())
        ->orderByDesc('last_activity')
        ->limit(20)
        ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
        ->map(fn (object $session) => [
            'id' => hash('sha256', $session->id),
            ...$this->device((string) $session->user_agent),
            'ip_address' => $session->ip_address,
            'last_active_at' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
            'current' => $session->id === $request->session()->getId(),
        ])
        ->values()
        ->all();
    ```

2. `database/seeders/SendTestSeeder.php:111`: `askOtherInboxes()` should return `list<string>` but returns `array<int, non-falsy-string>`. The method ends with `collect(...)->map(...)->filter()->each(...)->values()->all();`.
3. `packages/core/src/Support/HomeNotices.php:141`: `notice()` declares `@return array{key: string, type: 'info'|'warning'|'danger', ...}`, but its parameter is `string $type`. Its signature is `private function notice(string $key, string $type, string $heading, string $body, ?array $action): array`, and its PHPDoc has `@param array{0: string, 1: string}|null $action` but nothing for `$type`. Every call site passes one of those three literals; check lines 35-100 to confirm.

**Fix patterns** (the same ones used when PHPStan was cleaned up before):

- For "array vs list", wrap the result in `array_values(...)`. Larastan does not infer `list` from `->values()->all()` here.
- For `mixed` to `string|null`, narrow explicitly: `'ip_address' => is_string($session->ip_address) ? $session->ip_address : null`.
- For `string` to a literal union, add a `@param 'info'|'warning'|'danger' $type` PHPDoc. This narrows the type for callers, which is a real fix and not a silence, because every caller passes a literal.

The project rules (`AGENTS.md`) forbid `@phpstan-ignore`, baseline entries, inline `@var` overrides, and widening types.

**Failing MySQL assertions** (`packages/core/tests/Feature/EmailTemplateTest.php`):

```php
// line 76, in a dataset test with 3 cases; 2 of them fail on MySQL
expect(Campaign::sole())->editor->toBe($content['editor'])->editor_state->toBe($content['editor_state'])->html->toBe($content['html']);
// line 90
expect($campaign->fresh())->editor->toBe('blocks')->editor_state->toBe($document);
// line 96
expect($campaign->fresh()->ab_editor_state_b)->toBe($document);
```

## Commands you will need

Run these from the repo root.

| Purpose                                               | Command                                                                                                                                                                                 | Expected                        |
| ----------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------- |
| PHPStan                                               | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`                                                                                                                            | `[OK] No errors` / `"errors":0` |
| Core tests                                            | `cd packages/core && vendor/bin/pest --compact`                                                                                                                                         | all pass                        |
| Host tests                                            | `php artisan test --compact`                                                                                                                                                            | all pass                        |
| Pint                                                  | `vendor/bin/pint --dirty --format agent`, then `vendor/bin/pint --test`                                                                                                                 | exit 0                          |
| MySQL, only if a passwordless local root is available | `cd packages/core && DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=marketing_core_test DB_USERNAME=root DB_PASSWORD= vendor/bin/pest --compact tests/Feature/EmailTemplateTest.php` | all pass                        |

## Scope

**In scope:**

- `app/Http/Controllers/Settings/SecurityController.php` (`sessions()` return expression only)
- `database/seeders/SendTestSeeder.php` (`askOtherInboxes()` return only)
- `packages/core/src/Support/HomeNotices.php` (`notice()` PHPDoc only)
- `packages/core/tests/Feature/EmailTemplateTest.php` (the three `editor_state` / `ab_editor_state_b` assertions only)

**Out of scope:**

- Every other behaviour. Don't change what `sessions()` selects or filters; plan 009 does that.
- Casts on models: don't change the JSON column type or the `array` cast.
- `phpstan.neon`.

## Git workflow

- Branch: `advisor/012-ci-green`.
- Commit: `fix: clear phpstan errors and compare stored json without key order in tests`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Fix the three PHPStan errors

Apply the fix patterns above:

- **`SecurityController::sessions()`:** wrap the chain in `array_values(...)`, keeping `->values()->all()` inside, and narrow `ip_address`.
- **`SendTestSeeder::askOtherInboxes()`:** wrap the return in `array_values(...)`.
- **`HomeNotices::notice()`:** add the `@param 'info'|'warning'|'danger' $type` line to its PHPDoc.

**Verify**: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` → 0 errors

### Step 2: Make the JSON assertions ignore key order

In `EmailTemplateTest.php`, change these assertions from `->toBe(...)` to `->toEqual(...)`:

- `->editor_state->toBe($content['editor_state'])` (line 76)
- `->editor_state->toBe($document)` (line 90)
- `expect(...->ab_editor_state_b)->toBe($document)` (line 96)

Leave the `editor` and `html` assertions as `toBe`. Add a one-line comment above the first of these: `// MySQL's JSON columns reorder object keys, so stored documents are compared without regard to key order.`

**Verify**: `cd packages/core && vendor/bin/pest --compact` → all pass. If MySQL is available locally, run the MySQL command too.

## Test plan

No new tests. The existing suite is the check, and CI's `core-mysql` job confirms Step 2 after the push.

## Done criteria

- [ ] PHPStan reports 0 errors.
- [ ] `grep -c "toEqual" packages/core/tests/Feature/EmailTemplateTest.php` returns at least 3.
- [ ] The core and host suites pass, and Pint is clean.
- [ ] Only in-scope files are changed, and no `phpstan-ignore` appears anywhere.

## STOP conditions

- Any PHPStan error can only be fixed by changing behaviour.
- A `HomeNotices::notice()` caller passes a non-literal or a value outside the three allowed ones. That would be a real bug; report it.
- The failing assertions turn out to differ in values, not just key order.

## Maintenance notes

- Any test that asserts on a value read back from a JSON column (such as `editor_state`, `definition`, `trigger_config` or `custom_fields`) should use `toEqual`, not `toBe`. Reviewers should watch for this, because SQLite keeps key order and hides the difference locally.
