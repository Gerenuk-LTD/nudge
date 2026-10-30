# Plan 016: Update symfony/mime past its header-injection advisory

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `composer show symfony/mime | grep versions` should print `v8.1.7`. If it already shows 8.1.10 or later, mark this plan REJECTED ("fixed independently") and stop.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW (patch release)
- **Depends on**: none
- **Category**: migration (dependency)
- **Planned at**: commit `0ac75d7`, 2026-10-30
- **Approval**: the maintainer approved this dependency change on 2026-10-30. The project rules require approval for dependency changes.

## Why this matters

`composer audit` reports advisory PKSA-wtxr-p26d-nn42 (CVE-2026-45070, medium) against `symfony/mime` v8.1.7: "Email Header Injection via Non-Token Characters in Mime Parameter Names". It's fixed in 8.1.10. Every outgoing email is built with `symfony/mime`, so even though no path that reaches the bug has been confirmed, a patch release is cheap insurance.

## Current state

- `symfony/mime` v8.1.7 is installed. The constraints that allow it are:
    - `laravel/framework` requires `^7.4.0 || ^8.0.0`;
    - `symfony/mailer` requires `^7.4|^8.0`.

    So 8.1.10 is allowed with no constraint changes.

- `composer.lock` at the repo root is committed.
- `packages/core/composer.lock` is gitignored on purpose, because core is a library. Its `vendor/` resolves fresh on install.

## Commands you will need

Run these from the repo root.

| Purpose   | Command                                                                          | Expected                                                          |
| --------- | -------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| Update    | `composer update symfony/mime --no-interaction`                                  | updates `symfony/mime` to ≥ 8.1.10 and nothing unrelated          |
| Audit     | `composer audit --locked`                                                        | no advisories for `symfony/mime`                                  |
| Core deps | `composer --working-dir=packages/core update symfony/mime --no-interaction`      | core's local vendor gets the fix too, so its tests run against it |
| Tests     | `php artisan test --compact` and `cd packages/core && vendor/bin/pest --compact` | all pass                                                          |
| PHPStan   | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`                     | 0 errors                                                          |

## Scope

**In scope:** `composer.lock` only.

**Out of scope:**

- `composer.json`: no constraint changes are needed.
- Any other package update. Don't use `--with-all-dependencies` unless the update fails without it, and if it does, STOP.

## Git workflow

- Branch: `advisor/016-symfony-mime`.
- Commit: `chore(deps): update symfony/mime to fix a header injection advisory`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Update

Run the Update command, then `git diff --stat composer.lock`, then `git diff composer.lock | grep '"name"'`. Only `symfony/mime` should change, plus its own `symfony/*` polyfills if Composer requires them.

**Verify**: `composer show symfony/mime | grep versions` shows 8.1.10 or later, and `composer audit --locked` shows no `symfony/mime` advisory.

### Step 2: Test

Run the core deps update (it isn't committed, but it makes core's tests use the fixed version), then both test suites and PHPStan.

**Verify**: all pass

## Done criteria

- [ ] `symfony/mime` is ≥ 8.1.10 in `composer.lock`
- [ ] `composer audit --locked` reports no `symfony/mime` advisory
- [ ] Both suites pass; PHPStan reports 0 errors
- [ ] `git diff --stat` shows only `composer.lock`

## STOP conditions

- The update pulls in any package other than `symfony/mime` and its polyfills.
- Any test fails after the update.

## Maintenance notes

- Consider adding `composer audit` to CI, so the next advisory shows up there instead of in an audit.
