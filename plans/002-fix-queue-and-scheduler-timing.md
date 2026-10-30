# Plan 002: Queue redelivery, trigger dispatch and scheduler locks no longer cause double runs or missed automations

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 08eff55..HEAD -- config/queue.php .env.example packages/core/src/Jobs/StartAutomationsForTrigger.php packages/core/src/Jobs/EnrollScheduledTriggers.php packages/core/src/MarketingServiceProvider.php packages/core/config/marketing.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: plans/001-make-ci-a-real-gate.md (for a trustworthy test gate)
- **Category**: bug
- **Planned at**: commit `08eff55`, 2026-10-29

## Why this matters

Three timing bugs cause work to run twice or not at all:

1. **Long jobs get re-delivered while still running.** The queue hands a job to a second worker once `retry_after` seconds pass, and `retry_after` is 90. `PrepareCampaign` (timeout 1800s) and `ProcessImport` (timeout 3600s) routinely run longer. Two copies of `PrepareCampaign` can each pick their own random A/B sample, so up to twice the intended test audience gets test emails. Two copies of `ProcessImport` double-count rows.
2. **Automation trigger jobs can run before the contact exists.** They are dispatched inside a database transaction while `after_commit` is false. A fast worker runs the job before the commit, `Contact::find()` returns null, and the trigger is silently dropped (a new subscriber never gets the welcome series). Jobs dispatched in a transaction that later rolls back still run.
3. **A killed scheduler can stall sending for a day.** The scheduled callbacks use `withoutOverlapping()` with Laravel's default 24-hour lock. If `schedule:run` is killed mid-callback, scheduled campaigns and automation waits stop for up to a day. Separately, the 5-minute segment-trigger job has no overlap guard at all.

## Current state

**Project shape.** A Laravel 13 host app at the repo root. The product package is `packages/core` (namespace `Gerenuk\Marketing`). Core must stay reusable in other hosts, so it **cannot** rely on the host's `config/queue.php`. Host-specific queue settings belong in the host. Core documents what it needs in `packages/core/config/marketing.php` comments.

`config/queue.php` (host):

```php
// line 43 (database connection)
'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
'after_commit' => false,
// line 71 (redis connection)
'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
'after_commit' => false,
```

The longest job timeouts in core are:

- `packages/core/src/Jobs/ProcessImport.php:29-31`: `$tries = 3; $timeout = 3600;`
- `packages/core/src/Jobs/PrepareCampaign.php:33-35`: `$tries = 3; $timeout = 1800;`
- `packages/core/src/Jobs/SendCampaignBatch.php:26-28`: `$tries = 50; $timeout = 900;`

Laravel's rule is that `retry_after` must be larger than the longest job `timeout`.

`packages/core/src/Jobs/StartAutomationsForTrigger.php:18`:

```php
class StartAutomationsForTrigger implements ShouldQueue
{
    use Queueable;
```

It is dispatched from `packages/core/src/Automations/AutomationTriggers.php` (`listsJoined`, `tagsAdded`, `event`). Those methods are called **inside** `DB::transaction` in `packages/core/src/Actions/Contacts/UpsertContact.php:43-81` and from `UpdateContact.php:41-42`, `StepRunner.php:111,121`, `ProcessImport.php:107` and `Api/ContactListController.php:61`. Laravel provides `Illuminate\Contracts\Queue\ShouldQueueAfterCommit` (it extends `ShouldQueue`). A job implementing it is held until the surrounding transaction commits, and dropped if it rolls back. Outside a transaction it dispatches immediately.

`packages/core/src/MarketingServiceProvider.php:55-63`:

```php
private function registerSchedule(): void
{
    $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
        $schedule->call(new DispatchDueCampaigns)->everyMinute()->name('marketing:dispatch-due-campaigns')->withoutOverlapping();
        $schedule->call(new AdvanceDueAutomationRuns)->everyMinute()->name('marketing:advance-automation-runs')->withoutOverlapping();
        $schedule->job(new EnrollScheduledTriggers([AutomationTrigger::SegmentEntered]))->everyFiveMinutes()->name('marketing:segment-triggers');
        $schedule->job(new EnrollScheduledTriggers([AutomationTrigger::DateField, AutomationTrigger::Inactivity]))->dailyAt('08:00')->name('marketing:daily-triggers');
    });
}
```

`withoutOverlapping(int $expiresAt = 1440)` takes minutes. Both minute callbacks are already safe against overlap through compare-and-set claims, so a short lock is enough.

`packages/core/src/Jobs/EnrollScheduledTriggers.php` implements only `ShouldQueue`. Its `handle()` scans every active automation's segment. Re-enrolment is already guarded by `EnrollContact`, so overlapping copies are wasteful, not incorrect.

`packages/core/config/marketing.php:58-79` holds the `sending` config block with a comment header. Match that comment style.

**Test conventions.** Pest, `test('sentence describing behaviour', function () { ... })`. Tests live in `packages/core/tests/Feature/*.php` and use `$this->createOwner()` (returns `[$user, $workspace]` and sets the current workspace). The queue is `sync` in tests (`packages/core/phpunit.xml`), and Laravel's sync queue honours `ShouldQueueAfterCommit`. Use `packages/core/tests/Feature/AutomationTest.php` as the pattern: read how it creates an active `ListJoined` automation (search for `AutomationTrigger::ListJoined` or `list_id`) and reuse that setup.

## Commands you will need

| Purpose               | Command                                                                          | Expected on success                         |
| --------------------- | -------------------------------------------------------------------------------- | ------------------------------------------- |
| Core tests (one file) | `cd packages/core && vendor/bin/pest --compact tests/Feature/AutomationTest.php` | all pass                                    |
| Core tests (all)      | `cd packages/core && vendor/bin/pest --compact`                                  | all pass                                    |
| Host tests            | `php artisan test --compact`                                                     | all pass                                    |
| PHPStan               | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`                     | `[OK] No errors` (assuming plan 001 landed) |
| Pint                  | `vendor/bin/pint --dirty --format agent`                                         | exit 0                                      |
| Schedule listing      | `php artisan schedule:list`                                                      | lists the four `marketing:*` entries        |

## Scope

**In scope**:

- `config/queue.php` (host): `retry_after` defaults only
- `.env.example`: add commented queue keys
- `packages/core/config/marketing.php`: comment only
- `packages/core/src/Jobs/StartAutomationsForTrigger.php`
- `packages/core/src/Jobs/EnrollScheduledTriggers.php`
- `packages/core/src/MarketingServiceProvider.php` (`registerSchedule` only)
- `packages/core/tests/Feature/AutomationTest.php` (add tests)
- `packages/core/tests/Feature/ScheduleTest.php` (create)

**Out of scope** (do NOT touch):

- Job `$timeout` / `$tries` values. Lowering them is a different trade-off.
- `after_commit` in `config/queue.php`. Turning it on globally changes every job in the host; this plan opts in per job.
- `UpsertContact`, `AutomationTriggers` and their call sites. The fix is on the job class, so callers need no change.
- `SendCampaignBatch`, `PickAbTestWinner` and campaign recovery. Plan 003 handles those.

## Git workflow

- Branch: `advisor/002-queue-timing` from `dev`.
- Commit message example: `fix: dispatch automation triggers after commit and stop long jobs being redelivered`
- End commit messages with: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- Do NOT push or open a PR unless instructed.

## Steps

### Step 1: Raise `retry_after` above the longest job timeout

In `config/queue.php`, change both defaults from `90` to `3660` (the 3600s import timeout plus a minute):

```php
'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 3660),
...
'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 3660),
```

Leave the beanstalkd and sqs entries alone. In `.env.example`, next to `QUEUE_CONNECTION`, add commented lines:

```
# Must exceed the longest job timeout (imports: 3600s).
# DB_QUEUE_RETRY_AFTER=3660
# REDIS_QUEUE_RETRY_AFTER=3660
```

In `packages/core/config/marketing.php`, extend the "Sending" comment block with one sentence: "The host queue connection's `retry_after` must exceed the longest job timeout (imports run up to an hour), or long jobs are handed to a second worker while still running."

**Verify**: `php artisan config:show queue.connections.database.retry_after` → `3660` (if your local `.env` sets `DB_QUEUE_RETRY_AFTER` the value differs; then check `grep -n "3660" config/queue.php` → 2 matches)

### Step 2: Dispatch automation triggers only after the transaction commits

Change `StartAutomationsForTrigger` to `implements ShouldQueueAfterCommit` (import `Illuminate\Contracts\Queue\ShouldQueueAfterCommit`, and drop the now-redundant `ShouldQueue` import if it is no longer used).

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/AutomationTest.php` → all pass

### Step 3: Short scheduler locks, and an overlap guard for segment triggers

In `MarketingServiceProvider::registerSchedule`:

- Change both `->withoutOverlapping()` to `->withoutOverlapping(10)`.
- Add `->withoutOverlapping(30)` to the `marketing:segment-triggers` entry and `->withoutOverlapping(120)` to `marketing:daily-triggers`.

Then make `EnrollScheduledTriggers` implement `Illuminate\Contracts\Queue\ShouldBeUnique`, with:

```php
public int $uniqueFor = 1800;

public function uniqueId(): string
{
    return implode(',', array_map(fn (AutomationTrigger $trigger) => $trigger->value, $this->triggers));
}
```

This follows the `uniqueId()` style already used in `packages/core/src/Jobs/PrepareCampaign.php:47-50` and `AdvanceAutomationRun.php:34-37`.

**Verify**: `php artisan schedule:list` → four `marketing:*` entries, no error
**Verify**: `cd packages/core && vendor/bin/pest --compact` → all pass

### Step 4: Tests

Add the tests listed in the Test plan.

**Verify**: `cd packages/core && vendor/bin/pest --compact --filter="after the contact is saved|rolled back|schedule"` → the new tests pass

## Test plan

In `packages/core/tests/Feature/AutomationTest.php`:

1. `test('list automations start only after the contact is saved')`. Set up an active `ListJoined` automation for a list, reusing that file's existing setup helpers. Inside `DB::transaction(function () { ... })`, call `app(UpsertContact::class)->handle(...)` to add a new contact to that list, then **still inside the transaction** assert `AutomationRun::count()` is `0`. After the transaction, assert `AutomationRun::count()` is `1`. (Before the fix the in-transaction count is 1, so this test fails on the old code. Confirm that by temporarily reverting Step 2 and seeing it fail, then restore.)
2. `test('triggers from a rolled back save are dropped')`. Same setup. Inside a transaction that throws after the upsert (wrap it in `try { DB::transaction(...) } catch (RuntimeException) {}`), then assert `AutomationRun::count()` is `0` and `Contact::count()` is `0`.

Create `packages/core/tests/Feature/ScheduleTest.php`: 3. `test('marketing schedule entries hold their overlap lock for minutes, not a day')`. Resolve `app(Schedule::class)`, filter `->events()` to those whose `description` starts with `marketing:`, and assert there are 4, each with `withoutOverlapping` true and `expiresAt` ≤ 120.

## Done criteria

- [ ] `grep -n "3660" config/queue.php` → 2 matches
- [ ] `grep -n "ShouldQueueAfterCommit" packages/core/src/Jobs/StartAutomationsForTrigger.php` → match
- [ ] `grep -c "withoutOverlapping(" packages/core/src/MarketingServiceProvider.php` → 4
- [ ] `cd packages/core && vendor/bin/pest --compact` passes, including the 3 new tests
- [ ] `php artisan test --compact` passes
- [ ] PHPStan clean; `vendor/bin/pint --test` exits 0
- [ ] `git status` shows only in-scope files changed

## STOP conditions

- The new test 1 still sees `1` run inside the transaction after Step 2. That means the sync queue in this Laravel version does not defer after-commit jobs. Report it; do not switch the test to `Queue::fake()` without saying so.
- `Schedule` events don't expose `withoutOverlapping` / `expiresAt` as public properties in this Laravel version. Report it, and write test 3 by asserting on `php artisan schedule:list` output instead only if that is reliable.
- Any existing automation test fails after Step 2. A test may rely on triggers running inside a transaction; report which one.

## Maintenance notes

- Any new job dispatched from inside a transaction (imports, contact saves, step runner) should implement `ShouldQueueAfterCommit` too. Reviewers should check for this.
- If a job's `$timeout` is ever raised above 3600, `retry_after` must be raised with it.
- Raising `retry_after` means a job on a crashed worker waits up to an hour before retry. For sends that is safe (recipients are claimed one by one), and plan 003 adds a sweep for anything left half-done.
- Deferred: a separate queue connection for long jobs, so short jobs keep a fast `retry_after`. That needs deploy-side worker config.
