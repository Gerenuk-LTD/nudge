# Plan 046: Laravel and Symfony past their November 2026 security advisories

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in "STOP conditions" happens, stop and report. Don't
> improvise. When done, update this plan's row in `plans/README.md`, unless a
> reviewer dispatched you and told you they maintain the index.
>
> **Drift check (run first)**: `composer audit --locked` at the repo root.
> It should report advisories for `laravel/framework` and `symfony/*`
> (13 advisories across 7 packages at planning time). If it reports none,
> mark this plan REJECTED ("fixed independently") and stop.

## Status

- **Priority**: P1. Do this first: plan 047 builds on it.
- **Effort**: S
- **Risk**: LOW–MED (minor and patch releases of the framework)
- **Depends on**: none
- **Category**: migration (dependency)
- **Planned at**: commit `6982215`, 2026-11-15
- **Approval**: the project rules require approval for dependency changes. The maintainer asked for the round 6 findings to be planned on 2026-11-15; **confirm with them that this update is approved before running Step 1** if your dispatcher hasn't already said so.

## Why this matters

`composer audit --locked` reports 13 advisories. They affect `laravel/framework` v13.39.x (fixed in ≥ 13.40.0) and Symfony 8.1.9 `http-foundation`, `http-kernel`, `routing`, `http-client`, `serializer` and `yaml` (fixed in ≥ 8.1.11). The ones that matter for this app:

- "Generated URLs Can Point to a Host Chosen by the Client" (`symfony/http-foundation`). Password-reset links are built from the request's host (plan 047 pins the host in the app as well).
- "The `private_ranges` Trusted Proxies Shortcut Trusts Clients…" (`symfony/http-foundation`). It affects anyone who follows `DEPLOYING.md` and trusts proxies.
- "Inline Fragments Let Untrusted Forwarded Headers Set the Host…" (`symfony/http-kernel`).
- The serializer advisory: `web-auth/webauthn-lib` (passkeys) uses `symfony/serializer`.

Not relevant, but fixed anyway: Laravel's Concurrency-facade advisory (the app doesn't use it), and `NoPrivateNetworkHttpClient` (core's `WebhookCaller` does its own IP pinning).

## Current state

- `composer.lock` at the repo root is committed. `packages/core/composer.lock` is gitignored on purpose (core is a library).
- The previous dependency plan, `plans/016-update-symfony-mime.md`, followed the same shape: `composer.lock` only, no constraint changes.
- `composer.json` constraints already allow these versions (`laravel/framework` `^13`, Symfony `^7.4|^8.0` through Laravel). If Composer says a constraint blocks the update, that's a STOP.

## Commands you will need

From the repo root. In Git Bash on Windows, use `composer.bat` and `php.bat`; in PowerShell, `composer` and `php`.

| Purpose    | Command                                                                        | Expected                                                                                     |
| ---------- | ------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------- |
| Update     | `composer update laravel/framework "symfony/*" --with-dependencies --no-interaction` | updates only Laravel, Symfony and packages Laravel requires                         |
| Audit      | `composer audit --locked`                                                      | "No security vulnerability advisories found"                                                 |
| Core deps  | `composer --working-dir=packages/core update laravel/framework "symfony/*" --with-dependencies --no-interaction` | core's local vendor matches, so its tests run against the fix |
| Host tests | `php artisan test --compact`                                                   | all pass                                                                                     |
| Core tests | `cd packages/core && vendor/bin/pest --compact`                                | all pass except the 7 "Amazon SES through SNS" tests that fail on Windows (OpenSSL config)   |
| PHPStan    | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`                   | 0 errors                                                                                     |
| Pint       | `vendor/bin/pint --test`                                                       | passes                                                                                       |
| Frontend   | `npm run check` and `npm run types:check`                                      | pass (Wayfinder output may regenerate; it's gitignored)                                      |

## Scope

**In scope:** `composer.lock` only.

**Out of scope:**

- `composer.json`. No constraint changes.
- Any package outside `laravel/*` and `symfony/*` that Composer doesn't pull in as a requirement of those. Don't use `--with-all-dependencies` or a bare `composer update`.
- Any code change made to "fix" a test. If a test breaks, STOP.

## Git workflow

- Branch off `dev`: `advisor/046-framework-security-updates`.
- One commit: `chore(deps): update Laravel and Symfony past the November security advisories`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Update

Run the Update command. Then list what changed:

```sh
git diff composer.lock | grep -E '^[-+]\s+"(name|version)"'
```

**Verify**: `laravel/framework` is ≥ 13.40.0, every `symfony/*` package listed by the audit is ≥ 8.1.11, and every other changed package is one Laravel or Symfony requires (for example `laravel/prompts`, `symfony/polyfill-*`, `nesbot/carbon`). If anything unrelated moved (for example `laravel/fortify`, `inertiajs/*`, `dedoc/scramble`), STOP.

### Step 2: Audit

**Verify**: `composer audit --locked` → no advisories. If any remain because a fixed version isn't released yet, record which in your report and continue.

### Step 3: Test

Run the Core deps update (not committed; it only makes core's tests run on the new versions), then host tests, core tests, PHPStan, Pint and the frontend checks.

**Verify**: all pass as described in the Commands table.

### Step 4: Commit

Stage `composer.lock` only (`git add composer.lock`), and commit.

**Verify**: `git show --stat HEAD` lists only `composer.lock`.

## Test plan

No new tests: this is a dependency patch. The existing suites are the gate. Plan 047 adds the Host-header tests that this update's URL fix is relevant to.

## Done criteria

- [ ] `composer audit --locked` reports no advisories (or the report lists the unreleased ones)
- [ ] `git show --stat HEAD` changes only `composer.lock`
- [ ] Host tests, core tests (apart from the 7 known SES failures), PHPStan, Pint, `npm run check` and `types:check` pass
- [ ] `plans/README.md` row updated

## STOP conditions

- Composer refuses the update because of a constraint in `composer.json`.
- Packages outside Laravel's and Symfony's own requirements change.
- Any test that passed before the update fails after it.
- The audit at the drift check lists advisories for packages other than `laravel/framework` and `symfony/*`. Report them; they need their own approval.

## Maintenance notes

- Local checkouts need `composer install` after this merges.
- The release note can say "framework security update"; no behaviour change is expected.
- If Laravel 13.40 changed trusted-proxy or trusted-host defaults, plan 047's Step 1 excerpts may need a second look. Mention any such changelog entry in your report.
