# Plan 001: CI runs on every push to `dev`, passes PHPStan, and tests core against MySQL 8.4

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 08eff55..HEAD -- .github/workflows/tests.yml composer.json phpstan.neon packages/core/src`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S–M
- **Risk**: LOW
- **Depends on**: none
- **Category**: dx / tests
- **Planned at**: commit `08eff55`, 2026-10-29

## Why this matters

The CI workflow only runs on pushes to `main` and on pull requests. `main` holds only the init commit and all work happens on `dev`, so CI has never run against the product code. The gate it would run (`composer ci:check`) is also already red: PHPStan level 7 reports 14 errors. Finally, production runs MySQL 8.4 but every test runs on in-memory SQLite. A MySQL-only bug has already shipped once (commit `1dd8352`, "name campaign recipients unique index to fit MySQL's 64-character limit"). After this plan, every push to `dev` is checked, the checks are green, and the core package's suite also runs on MySQL 8.4. Every later plan in `plans/` relies on this gate.

## Current state

**Project shape.** A Laravel 13 / PHP 8.4 host app at the repo root. The product lives in `packages/core`: the Composer package `gerenuk/marketing-core`, namespace `Gerenuk\Marketing`, with its own Pest suite run through Orchestra Testbench.

`.github/workflows/tests.yml` (whole trigger block, lines 1–10):

```yaml
name: tests

on:
    push:
        branches:
            - main
    pull_request:

permissions:
    contents: read
```

It has a single job, `ci`, which checks out the code, sets up PHP 8.4 and Node 22, runs `composer setup`, and then runs `composer ci:check`. Actions are pinned by SHA; keep that style.

`composer.json` scripts (relevant part):

```json
"ci:check": ["Composer\\Config::disableProcessTimeout", "npm run check", "npm run types:check", "@test"],
"types:check": ["phpstan analyse --memory-limit=1G"],
"test": ["@php artisan config:clear --ansi", "@lint:check", "@types:check", "@php artisan test", "@test:core"],
"test:core": ["@composer --working-dir=packages/core install --no-interaction --quiet", "cd packages/core && vendor/bin/pest"],
```

`packages/core/phpunit.xml` sets env vars with plain `<env name="DB_CONNECTION" value="sqlite"/>` and `<env name="DB_DATABASE" value=":memory:"/>`. These have no `force="true"`, so a real environment variable set by CI overrides them. `packages/core/tests/TestCase.php` uses `RefreshDatabase` and Testbench's default database config, which reads `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` from the environment.

**PHPStan errors at planning time** (`vendor/bin/phpstan analyse --memory-limit=1G --no-progress`, 14 errors). They are all type-precision issues, not runtime bugs:

| File:line                                                            | Error                                                                                                                                                                                                                                |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `packages/core/src/Campaigns/CampaignAudience.php:65`                | `Call to an undefined method Builder::active()` inside a `withCount` closure typed `fn (Builder $contacts)`. The `active` scope exists on `Contact` via `#[Scope]` (`Models/Contact.php:114`); the closure param is untyped generic. |
| `packages/core/src/Http/Controllers/ContactListController.php:37`    | passes `array<int, int\|string>` where `list<int\|string>` is expected                                                                                                                                                               |
| `packages/core/src/Http/Controllers/CustomFieldController.php:20`    | passes `array<mixed>` where `list<string>` is expected                                                                                                                                                                               |
| `packages/core/src/Http/Controllers/EmailTemplateController.php:145` | returns `non-empty-array` where a typed array shape is declared                                                                                                                                                                      |
| `packages/core/src/Http/Controllers/HomeController.php:114`          | returns `array<int, …>` where `list<…>` is declared                                                                                                                                                                                  |
| `packages/core/src/Http/Controllers/TagController.php:74,76`         | `Tag::query()->findOrFail($validated['into'])` is inferred as `Tag\|Collection`                                                                                                                                                      |
| `packages/core/src/Support/DailySeries.php:69`                       | `array` vs `list`                                                                                                                                                                                                                    |
| `packages/core/src/Support/HomeNotices.php:141`                      | `'type' => string` where `'danger'\|'info'\|'warning'` is declared                                                                                                                                                                   |
| `packages/core/src/Support/TagReferences.php:214`                    | `array<mixed,mixed>` vs `list<array<string,mixed>>`                                                                                                                                                                                  |
| `packages/core/src/Templates/StarterTemplates.php:55`                | `array` vs `list`                                                                                                                                                                                                                    |
| `packages/core/src/Templates/TemplateChoice.php:45`                  | `array` vs `list`                                                                                                                                                                                                                    |
| `app/Http/Controllers/Settings/SecurityController.php:89`            | `array` vs `list`                                                                                                                                                                                                                    |
| `database/seeders/SendTestSeeder.php:111`                            | `array` vs `list`                                                                                                                                                                                                                    |

Note: at planning time, `HomeNotices.php`, `HomeController.php`, `EmailTemplateController.php`, `SecurityController.php` and `SendTestSeeder.php` had **uncommitted** changes in the author's working tree, so the errors you see may differ. Fix whatever PHPStan reports when you run it.

**Fix pattern for `array` vs `list`.** Wrap the value in `array_values(...)` at the return or argument site, or end a collection chain with `->values()->all()`. For `TagController:74`, use `Tag::query()->whereKey($validated['into'])->firstOrFail()` or cast to `(int)` with a single-model find. The project rules (`AGENTS.md`, PHPStan output instructions) forbid `@phpstan-ignore`, baseline entries, inline `@var` overrides, and widening types to silence errors. Fix the real type.

**Conventions.** PHP follows Laravel Pint's `laravel` preset (`pint.json`). Commit messages are lowercase conventional commits, for example `fix: name campaign recipients unique index to fit MySQL's 64-character limit`.

## Commands you will need

Run from the repo root unless noted.

| Purpose                                               | Command                                                                                                                                                          | Expected on success             |
| ----------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------- |
| PHPStan                                               | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`                                                                                                     | `[OK] No errors`                |
| Pint check                                            | `vendor/bin/pint --test`                                                                                                                                         | exit 0                          |
| Pint fix                                              | `vendor/bin/pint --dirty --format agent`                                                                                                                         | exit 0                          |
| Host tests                                            | `php artisan test --compact`                                                                                                                                     | all pass (46 at planning time)  |
| Core tests (SQLite)                                   | `cd packages/core && vendor/bin/pest --compact`                                                                                                                  | all pass (209 at planning time) |
| Core tests (MySQL, if a local MySQL 8.x is available) | `cd packages/core && DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=marketing_core_test DB_USERNAME=root DB_PASSWORD= vendor/bin/pest --compact` | all pass                        |
| Validate workflow YAML                                | `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/tests.yml'))" && echo ok`                                                                   | `ok`                            |

## Scope

**In scope**:

- `.github/workflows/tests.yml`
- Files PHPStan reports errors in (the table above). Change type precision only.

**Out of scope** (do NOT touch):

- `phpstan.neon` level or paths. Do not lower the level or exclude files.
- Any behaviour change in the files you fix for PHPStan. If a fix would change runtime behaviour, STOP.
- `composer.json` scripts. CI should keep calling them; the new MySQL job adds a separate step instead.
- `.env.example`, `config/*`. They are handled by other plans.

## Git workflow

- Branch: `advisor/001-ci-gate` from `dev`.
- One commit for the PHPStan fixes (`fix: resolve phpstan level 7 errors`) and one for the workflow (`ci: run on dev and test core against mysql 8.4`).
- End commit messages with: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Fix every PHPStan error at its root

Run PHPStan. For each error, apply the matching fix pattern from "Current state". Do not add ignores.

For `CampaignAudience.php:65`, type the closure parameter generically so Larastan resolves the scope, for example `fn (Builder $contacts) =>` becomes a closure whose `@param Builder<Contact> $contacts` PHPDoc is on a named closure. Alternatively, replace `->active()` with the equivalent `->whereNull('archived_at')` **only if** `Contact::active()` (`packages/core/src/Models/Contact.php:114-120`) is exactly that condition. Read it first.

**Verify**: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` → `[OK] No errors`
**Verify**: `cd packages/core && vendor/bin/pest --compact` → all pass; `php artisan test --compact` → all pass

### Step 2: Run CI on pushes to `dev`

In `.github/workflows/tests.yml`, change `branches: [main]` to include `dev`:

```yaml
on:
    push:
        branches:
            - main
            - dev
    pull_request:
```

**Verify**: the YAML validation command → `ok`

### Step 3: Add a MySQL 8.4 job for the core suite

Add a second job, `core-mysql`, to the same workflow:

- `runs-on: ubuntu-latest`
- a `services.mysql` container with `image: mysql:8.4`, env `MYSQL_DATABASE: marketing_core_test` and `MYSQL_ALLOW_EMPTY_PASSWORD: 'yes'`, `ports: ['3306:3306']`, and a health check option string: `--health-cmd="mysqladmin ping -h 127.0.0.1" --health-interval=10s --health-timeout=5s --health-retries=10`
- steps: the same pinned `actions/checkout` and `shivammathur/setup-php` steps as the `ci` job (copy them exactly, including SHAs and `persist-credentials: false`), then:
    - `composer install --no-interaction --prefer-dist` (root, so path-repo symlinks resolve)
    - `composer --working-dir=packages/core install --no-interaction --prefer-dist`
    - run `vendor/bin/pest` with `working-directory: packages/core` and env `DB_CONNECTION: mysql`, `DB_HOST: 127.0.0.1`, `DB_PORT: 3306`, `DB_DATABASE: marketing_core_test`, `DB_USERNAME: root`, `DB_PASSWORD: ''`

Do not add Node to this job; the core PHP suite doesn't need it.

**Verify**: the YAML validation command → `ok`
**Verify**: `grep -n "mysql:8.4" .github/workflows/tests.yml` → one match

### Step 4: Run the core suite on MySQL locally if you can

Check for a reachable MySQL with `mysql -h 127.0.0.1 -u root -e 'select version()'` (or `mysqladmin ping`). If one is available, create the database (`mysql -h 127.0.0.1 -u root -e 'create database if not exists marketing_core_test'`) and run the MySQL core test command from the table.

- If it passes: record "MySQL suite verified locally" in your report.
- If tests fail on MySQL: **do not fix product code in this plan.** Record each failing test name and its error message in your report and in a new "MySQL-only failures" note under this plan's row in `plans/README.md`. Those failures are real production bugs that need their own plan.
- If no MySQL is reachable: record "MySQL job not verified locally" and continue.

## Test plan

No new tests. The new CI job re-runs the existing core suite on MySQL.

## Done criteria

- [ ] `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` → `[OK] No errors`
- [ ] `grep -rn "phpstan-ignore" app packages/core/src database` → no new matches compared with `08eff55`
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `php artisan test --compact` and `cd packages/core && vendor/bin/pest --compact` both pass
- [ ] `.github/workflows/tests.yml` triggers on `dev` and has a `core-mysql` job using `mysql:8.4`
- [ ] Your report states whether the MySQL suite was verified locally, and lists any MySQL-only failures
- [ ] `git status` shows changes only in the workflow and in files PHPStan flagged

## STOP conditions

- A PHPStan error can only be fixed by changing runtime behaviour (not just types). Report the error and your proposed change.
- PHPStan reports a **new** error that is not type-precision (for example "undefined method" on a method that really doesn't exist). That's a real bug; report it.
- Fixing an error appears to need `phpstan.neon` changes.
- MySQL failures: record them as described in Step 4; do not fix them here.

## Maintenance notes

- The `core-mysql` job installs fresh dependency versions every run because `packages/core/composer.lock` is gitignored (deliberate for a library). A red run with no code change probably means an upstream release; check before blaming the diff.
- If Redis-specific behaviour (locks, queues) is ever tested, add a `redis:7` service to `core-mysql` the same way.
- Deferred: Composer and npm caching and job splitting. They're worth doing, but they're speed work, not correctness.
