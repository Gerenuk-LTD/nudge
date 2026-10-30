# Plan 003: Campaigns and automation runs can no longer get stuck forever

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 326a18e..HEAD -- packages/core/src/Jobs/PrepareCampaign.php packages/core/src/Jobs/PickAbTestWinner.php packages/core/src/Jobs/SendCampaignBatch.php packages/core/src/Jobs/AdvanceAutomationRun.php packages/core/src/Http/Controllers/SendingSettingsController.php packages/core/src/MarketingServiceProvider.php packages/core/src/Sending/RecipientDelivery.php packages/core/src/Enums/RecipientStatus.php`
> Expected output: nothing. If any listed file changed, compare its "Current
> state" excerpt below against the live code; on a mismatch, STOP.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (send path; mitigated by the existing per-recipient claim, so nothing in this plan can send an email twice)
- **Depends on**: plans/001-make-ci-a-real-gate.md (DONE), plans/002-fix-queue-and-scheduler-timing.md (DONE). 002 raised the database/redis queue `retry_after` to 3660s, which this plan's timing constants rely on.
- **Category**: bug
- **Planned at**: commit `326a18e`, 2026-10-29 (revised after a cold-read review; first draft was against `08eff55`)

## Why this matters

A broadcast campaign only becomes `sent` once none of its recipients are `queued`, `sending` or `held`. Several ordinary failures leave it `sending` for ever, with nothing that will move it on. The campaign can't be deleted, `sent_at` is never set, and the dashboard's setup step never ticks:

1. **A worker dies mid-send.** If a worker is killed (deploy, OOM, timeout) between claiming a recipient (`queued → sending`) and recording the result, that recipient is never retried (by design, to avoid duplicates), and it blocks completion for ever.
2. **A long pause.** While the workspace is paused, each batch job calls `release(300)`, and every release counts toward `$tries = 50`. After about 4 hours paused, every batch job fails for good. Resuming doesn't re-queue anything.
3. **The A/B winner job dies.** If it dies after recording the winner but before releasing the `held` recipients, the retry sees a winner and returns. The recipients stay held for ever. If it dies before recording a winner at all, nothing ever tries again.
4. **The last batch dies between its final send and `finishIfDone`.** Every recipient is done, but the campaign still says `sending`.
5. **An automation run's job throws three times.** The run stays `active` for ever. Only `waiting` runs are picked up again, and an `active` run also stops the contact from re-entering that automation.

After this plan, a scheduled sweep resolves each case safely. Pausing no longer burns job attempts, and resuming re-queues the work.

## Current state

**Project shape.** The product is the package `packages/core` (namespace `Gerenuk\Marketing`, Laravel 13, PHP 8.4). All paths below are relative to `packages/core/` unless they start with `config/` or `plans/`.

**Multi-tenancy.** Models using `BelongsToWorkspace` carry a global `WorkspaceScope` that **throws** when no workspace is current. This includes `Campaign`, `CampaignRecipient` and `AutomationRun`. To work across workspaces:

- query with `::withoutGlobalScopes()`, or
- wrap per-workspace work in `app(CurrentWorkspace::class)->runAs($workspace, fn () => ...)` (`src/Tenancy/CurrentWorkspace.php:43`).

`src/Jobs/EnrollScheduledTriggers.php:45-67` is the exemplar: it finds rows with `withoutGlobalScopes()`, then does the work inside `runAs`.

**Scheduled sweeper exemplar.** `src/Jobs/DispatchDueCampaigns.php` is an invokable class, not a queued job:

```php
namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Models\Campaign;

/**
 * Runs every minute and starts scheduled campaigns whose time has come, in every workspace.
 */
class DispatchDueCampaigns
{
    public function __invoke(): void
    {
        Campaign::withoutGlobalScopes()
            ->broadcasts()
            ->where('status', CampaignStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->select(['id', 'workspace_id'])
            ->each(fn (Campaign $campaign) => PrepareCampaign::dispatchFor($campaign));
    }
}
```

`src/MarketingServiceProvider.php:56-64` registers the schedule at HEAD:

```php
private function registerSchedule(): void
{
    $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
        $schedule->call(new DispatchDueCampaigns)->everyMinute()->name('marketing:dispatch-due-campaigns')->withoutOverlapping(10);
        $schedule->call(new AdvanceDueAutomationRuns)->everyMinute()->name('marketing:advance-automation-runs')->withoutOverlapping(10);
        $schedule->job(new EnrollScheduledTriggers([AutomationTrigger::SegmentEntered]))->everyFiveMinutes()->name('marketing:segment-triggers')->withoutOverlapping(30);
        $schedule->job(new EnrollScheduledTriggers([AutomationTrigger::DateField, AutomationTrigger::Inactivity]))->dailyAt('08:00')->name('marketing:daily-triggers')->withoutOverlapping(120);
    });
}
```

`packages/core/tests/Feature/ScheduleTest.php` asserts there are exactly **4** `marketing:*` entries, each with an overlap lock of ≤ 120 minutes. This plan adds a fifth, so that test's count must change to 5 (Step 6).

**Claim pattern and `updated_at`.** All state changes use compare-and-set: `Model::query()->whereKey($id)->where('status', X)->update([...]) === 1`. Eloquent's builder `update()` also sets `updated_at` unless you pass `updated_at` explicitly (in which case your value wins). So `updated_at` on `campaign_recipients` and `automation_runs` (both have `timestamps()`) records when a row last changed state.

`src/Sending/RecipientDelivery.php:38-43`:

```php
// Claim the recipient so no other worker sends it too. A recipient left claimed by a crash is
// never retried, so nobody gets a duplicate.
$claimed = CampaignRecipient::query()
    ->whereKey($recipient->id)
    ->where('status', RecipientStatus::Queued)
    ->update(['status' => RecipientStatus::Sending]) === 1;
```

On a send failure (lines 63-69) it sets `status => Failed, 'error' => ...` and calls `$campaign->increment('failed_count')`. Automation emails also go through `RecipientDelivery::deliver` (from `src/Automations/StepRunner.php:77`), but not through `SendCampaignBatch`. `StepRunner::sendEmail` creates the recipient with `firstOrCreate` (lines 63-66) and treats any non-`queued` recipient as already done (lines 72-74).

`src/Jobs/SendCampaignBatch.php:49-53` and `:85-96`:

```php
if ($workspace->isSendingPaused()) {
    $this->release(300);

    return;
}
...
public static function finishIfDone(Campaign $campaign): void
{
    $waiting = $campaign->recipients()->whereIn('status', [RecipientStatus::Queued, RecipientStatus::Sending, RecipientStatus::Held])->exists();

    if (! $waiting) {
        Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending)
            ->update(['status' => CampaignStatus::Sent, 'sent_at' => now()]);
    }
}
```

At lines 58-61, a campaign with no provider or snapshot makes the batch job call `$this->fail(...)`.

`src/Jobs/PrepareCampaign.php:164-176` is the only place batches are queued apart from `PickAbTestWinner`. It imports `Illuminate\Support\Collection`.

```php
private function queueBatches(Campaign $campaign): void
{
    $campaign->recipients()
        ->where('status', RecipientStatus::Queued)
        ->select('id')
        ->chunkById(self::BATCH_SIZE, function (Collection $recipients) use ($campaign): void {
            SendCampaignBatch::dispatch($campaign->id, $campaign->workspace_id, array_values($recipients->map(fn (CampaignRecipient $recipient) => $recipient->id)->all()));
        });

    if ($campaign->recipients_count === 0) {
        $campaign->update(['status' => CampaignStatus::Sent, 'sent_at' => now()]);
    }
}
```

Line 161 dispatches `PickAbTestWinner` delayed by `ab_wait_minutes`. `PrepareCampaign` also sets `sending_started_at` when it claims the campaign.

`src/Jobs/PickAbTestWinner.php:28-61` (the whole `handle`):

```php
public function handle(CurrentWorkspace $currentWorkspace, AbTestResults $results): void
{
    $currentWorkspace->runAs(Workspace::findOrFail($this->workspaceId), function () use ($results): void {
        $campaign = Campaign::find($this->campaignId);

        if ($campaign === null || $campaign->status !== CampaignStatus::Sending || $campaign->ab_winner !== null) {
            return;
        }

        $winner = $results->leader($campaign);

        // Only one copy of this job may release the held recipients.
        $decided = Campaign::query()->whereKey($campaign->id)->whereNull('ab_winner')
            ->update(['ab_winner' => $winner, 'ab_decided_at' => now()]) === 1;

        if (! $decided) {
            return;
        }

        $campaign->recipients()->where('status', RecipientStatus::Held)
            ->update(['status' => RecipientStatus::Queued, 'variant' => $winner]);

        $campaign->recipients()
            ->where('status', RecipientStatus::Queued)
            ->where('variant', $winner)
            ->whereNull('sent_at')
            ->select('id')
            ->chunkById(PrepareCampaign::BATCH_SIZE, function (Collection $recipients) use ($campaign): void {
                SendCampaignBatch::dispatch($campaign->id, $campaign->workspace_id, array_values($recipients->map(fn (CampaignRecipient $recipient) => $recipient->id)->all()));
            });

        SendCampaignBatch::finishIfDone($campaign);
    });
}
```

`src/Http/Controllers/SendingSettingsController.php`, `resume()`:

```php
public function resume(): RedirectResponse
{
    $this->currentWorkspace->getOrFail()->update(['sending_paused_at' => null, 'sending_paused_reason' => null]);

    Inertia::flash('toast', ['type' => 'success', 'message' => __('Sending resumed.')]);

    return to_route('marketing.sending.edit');
}
```

The route is `marketing.sending.resume` (POST, behind `can:providers.manage`; the workspace owner has it).

`src/Jobs/AdvanceDueAutomationRuns.php:24-29` claims runs `waiting → active` and then dispatches `AdvanceAutomationRun`. `src/Jobs/AdvanceAutomationRun.php` has:

- `implements ShouldBeUnique, ShouldQueue` and a `uniqueId()` returning the run id (lines 20, 34-37);
- no `$uniqueFor`, so a lock left behind by a killed worker never expires;
- `$tries = 3` and no `failed()`;
- a private `exit(AutomationRun $run, string $reason)` (lines 130-135) that marks the run `Exited` and records an `AutomationExited` event using `$run->automation->name`.

The run saves after every step, so a re-run repeats at most the step in progress.

**Other facts.**

- `src/Sending/SendRateLimiter.php` has `allowsToday(Provider $provider): bool`.
- Campaign columns used here: `status`, `kind` (`Campaign::query()->broadcasts()` limits to broadcasts), `provider_id`, `ab_enabled` (bool), `ab_wait_minutes` (int), `ab_winner` (?string), `sending_started_at` (datetime), `failed_count`.
- Relations: `$campaign->provider`, `$campaign->snapshot`, `$campaign->recipients()`.

**Enums.**

- `RecipientStatus`: `Queued, Held, Sending, Sent, Failed, Skipped, Delivered, Bounced, Complained`.
- `CampaignStatus`: `Draft, Scheduled, Sending, Sent, Cancelled`.
- `AutomationRunStatus`: `Active, Waiting, Completed, Exited`.

**Code conventions.** One-sentence PHPDoc summary on every class. Explicit return types. Curly braces always. User-facing strings in `__()`. Run Pint before committing.

**Test conventions.** Pest `test('sentence', fn)` in `tests/Feature/*`. The sync queue is used in tests (`QUEUE_CONNECTION=sync`), and the cache is the array store. After running jobs, call `$this->useWorkspace($this->workspace)` before querying scoped models.

Test helper functions in other test files are **global functions**: `sendNow` in `SendingTest.php:40`, `abCampaign` in `AbTestTest.php:28`, `liveAutomation` in `AutomationTest.php:57`. **Do not copy them under the same name** (fatal "cannot redeclare" when the full suite loads), and do not call them from the new file (it must also pass when run alone). Define new helpers in `RecoveryTest.php` with names starting `recovery`. There is **no** `CampaignRecipient` factory; recipients come from scheduling a campaign. Factories that exist: `Contact`, `Campaign`, `Automation`, `ContactList`, `Workspace`, and others.

## Commands you will need

All from the repo root.

| Purpose    | Command                                                                        | Expected on success                                            |
| ---------- | ------------------------------------------------------------------------------ | -------------------------------------------------------------- |
| New tests  | `cd packages/core && vendor/bin/pest --compact tests/Feature/RecoveryTest.php` | all pass                                                       |
| Core suite | `cd packages/core && vendor/bin/pest --compact`                                | all pass (212 at planning time, plus the new tests)            |
| Host tests | `php artisan test --compact`                                                   | all pass                                                       |
| PHPStan    | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`                   | 0 errors in files you touched (see STOP conditions for others) |
| Pint fix   | `vendor/bin/pint --dirty --format agent`                                       | exit 0                                                         |
| Pint check | `vendor/bin/pint --test`                                                       | exit 0                                                         |
| Schedule   | `CACHE_STORE=array php artisan schedule:list`                                  | includes `marketing:recover-stalled-sends`                     |

## Scope

**In scope**:

- `src/Campaigns/QueueCampaignBatches.php` (create)
- `src/Jobs/RecoverStalledSends.php` (create)
- `src/Jobs/PrepareCampaign.php` (`queueBatches` only)
- `src/Jobs/PickAbTestWinner.php`
- `src/Jobs/SendCampaignBatch.php` (paused branch only)
- `src/Jobs/AdvanceAutomationRun.php` (add `$uniqueFor` and `failed()`)
- `src/Http/Controllers/SendingSettingsController.php` (`resume` only)
- `src/MarketingServiceProvider.php` (`registerSchedule`: one new line)
- `src/Enums/RecipientStatus.php` (docblock on `Sending` only)
- `tests/Feature/RecoveryTest.php` (create)
- `tests/Feature/ScheduleTest.php` (count 4 → 5 only)

**Out of scope** (do NOT touch):

- `RecipientDelivery.php`. The rule "never retry a claimed recipient" stays: a recipient whose outcome is unknown becomes `Failed` and is never re-sent.
- `PrepareCampaign::handle` and a `failed()` for it. That needs a product decision; it's deferred.
- A/B minimum-data rules and results accuracy (separate findings).
- `config/queue.php` (including the beanstalkd `retry_after`), frontend files, migrations, new enum cases.

## Git workflow

- Branch: `advisor/003-recover-stuck-sends` from `dev`.
- Commit message example: `fix: recover campaigns and automation runs left stuck by crashes and long pauses`
- End commit messages with: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- Do NOT push or open a PR unless instructed.

## Steps

### Step 1: Extract batch queuing into `QueueCampaignBatches`

Create `src/Campaigns/QueueCampaignBatches.php`:

```php
<?php

namespace Gerenuk\Marketing\Campaigns;

use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Jobs\PrepareCampaign;
use Gerenuk\Marketing\Jobs\SendCampaignBatch;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Illuminate\Support\Collection;

/**
 * Queues send batches for every recipient of a campaign still waiting to be sent. Safe to call again:
 * each recipient is claimed before sending, so a recipient queued twice is still sent once.
 */
class QueueCampaignBatches
{
    public function handle(Campaign $campaign): void
    {
        $campaign->recipients()
            ->where('status', RecipientStatus::Queued)
            ->select('id')
            ->chunkById(PrepareCampaign::BATCH_SIZE, function (Collection $recipients) use ($campaign): void {
                SendCampaignBatch::dispatch($campaign->id, $campaign->workspace_id, array_values($recipients->map(fn (CampaignRecipient $recipient) => $recipient->id)->all()));
            });
    }
}
```

In `PrepareCampaign::queueBatches`, replace the `chunkById` block with `app(QueueCampaignBatches::class)->handle($campaign);`, and keep the `recipients_count === 0` block below it. Remove imports that are now unused. Use `app(...)` here, not constructor or method injection.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/SendingTest.php tests/Feature/AbTestTest.php` → all pass

### Step 2: Make `PickAbTestWinner` safe to retry, without re-queuing on every run

Rewrite the closure body inside the existing `runAs(...)` wrapper (keep the wrapper) to exactly this logic:

```php
$campaign = Campaign::find($this->campaignId);

if ($campaign === null || $campaign->status !== CampaignStatus::Sending) {
    return;
}

// Only one copy of this job decides the winner; any copy may finish releasing the held recipients.
$decided = false;

if ($campaign->ab_winner === null) {
    $decided = Campaign::query()->whereKey($campaign->id)->whereNull('ab_winner')
        ->update(['ab_winner' => $results->leader($campaign), 'ab_decided_at' => now()]) === 1;
}

$winner = Campaign::query()->whereKey($campaign->id)->value('ab_winner');

$released = $campaign->recipients()->where('status', RecipientStatus::Held)
    ->update(['status' => RecipientStatus::Queued, 'variant' => $winner]);

if ($decided || $released > 0) {
    app(QueueCampaignBatches::class)->handle($campaign);
}

SendCampaignBatch::finishIfDone($campaign);
```

The `$decided || $released > 0` guard matters. The delayed copy that `PrepareCampaign` dispatches still runs after a manual "pick winner". It finds no held recipients and doesn't decide, so it doesn't queue a second set of batches. `QueueCampaignBatches` has no `variant`/`sent_at` filter; that is intended, because every `queued` recipient left at this point should be sent, and the claim stops a duplicate send. Remove imports that are now unused (`Collection`, `CampaignRecipient`, `PrepareCampaign` if no longer referenced).

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/AbTestTest.php` → all pass

### Step 3: Stop burning attempts while paused, and re-queue on resume

In `SendCampaignBatch::handle`, replace `$this->release(300);` in the paused branch with nothing, so the branch just returns. Put this comment above the `return;`: `// Recipients stay queued; resuming sending (or the stalled-send sweep) queues them again.`

In `SendingSettingsController::resume`, add a parameter `QueueCampaignBatches $queueBatches` (Laravel injects it). After clearing the pause, add:

```php
Campaign::query()->broadcasts()->where('status', CampaignStatus::Sending)
    ->each(fn (Campaign $campaign) => $queueBatches->handle($campaign));
```

Add the needed imports (`Campaign`, `CampaignStatus`, `QueueCampaignBatches`).

**Verify**: `cd packages/core && vendor/bin/pest --compact` → all pass

### Step 4: Expire stale unique locks and exit failed automation runs

In `AdvanceAutomationRun`, add `public int $uniqueFor = 3600;` below `$tries`. Without it, a lock left by a killed worker silently drops every later dispatch for that run. Then add:

```php
/**
 * After the last attempt fails, take the contact out of the automation, so the run is not stuck "active"
 * and the contact can enter again if the automation allows re-entry.
 */
public function failed(?Throwable $exception): void
{
    $workspace = Workspace::find($this->workspaceId);

    if ($workspace === null) {
        return;
    }

    app(CurrentWorkspace::class)->runAs($workspace, function (): void {
        $run = AutomationRun::with('automation')->find($this->runId);

        if ($run !== null && $run->status === AutomationRunStatus::Active) {
            $this->exit($run, __('Stopped after an error'));
        }
    });
}
```

Import `Throwable`. Leave `exit()` unchanged.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/AutomationTest.php` → all pass

### Step 5: Add the `RecoverStalledSends` sweep

Create `src/Jobs/RecoverStalledSends.php`. It's an invokable class like `DispatchDueCampaigns`, with the class PHPDoc "Runs every five minutes and unsticks sends and automation runs left behind by crashed workers, long pauses or failed jobs, in every workspace."

Constants:

```php
/** Longer than SendCampaignBatch's 900s timeout, so a healthy send in progress is never touched. */
public const STALE_SENDING_MINUTES = 30;

/** Longer than the queue's retry_after (3660s), so a crashed job has had its own retry first. */
public const STALE_PROGRESS_MINUTES = 120;
```

`__invoke()` runs these four private methods in order. Each one gets a workspace with `Workspace::find($workspaceId)` (skip if null) and re-loads the campaign **inside** `runAs`, so every column and relation is available.

**(a) `failStuckRecipients()`.** Compute `$cutoff = now()->subMinutes(self::STALE_SENDING_MINUTES)` once, then:

```php
$pairs = CampaignRecipient::withoutGlobalScopes()
    ->where('status', RecipientStatus::Sending)
    ->where('updated_at', '<', $cutoff)
    ->select(['workspace_id', 'campaign_id'])
    ->distinct()
    ->get();
```

Do **not** use `chunkById` here; it needs an `id` column, which a distinct pair list doesn't have. The list is one row per affected campaign, so it's small. For each pair, inside `runAs`:

- Run `$failed = CampaignRecipient::query()->where('campaign_id', $pair->campaign_id)->where('status', RecipientStatus::Sending)->where('updated_at', '<', $cutoff)->update(['status' => RecipientStatus::Failed, 'error' => __('The worker stopped before the send was confirmed. Not retried, to avoid a duplicate.')]);`
- If `$failed > 0`, run `Campaign::query()->whereKey($pair->campaign_id)->increment('failed_count', $failed);`.

This also applies to automation email recipients, which is intended: `StepRunner` treats a non-queued recipient as done.

**(b) `finishOrRequeueBroadcasts()`.** Iterate `Campaign::withoutGlobalScopes()->broadcasts()->where('status', CampaignStatus::Sending)->select(['id', 'workspace_id'])->chunkById(100, ...)`. For each, inside `runAs`, load `$campaign = Campaign::with(['provider', 'snapshot'])->find($id)` (skip if null), then:

1. Call `SendCampaignBatch::finishIfDone($campaign)`, then `$campaign->refresh()`. If the status is no longer `Sending`, continue to the next campaign. This rescues case 4.
2. Skip the campaign if any of these holds:
    - `$workspace->isSendingPaused()`;
    - `$campaign->provider === null || $campaign->snapshot === null` (re-queuing would only produce failing jobs);
    - `! app(SendRateLimiter::class)->allowsToday($campaign->provider)`;
    - `$campaign->recipients()->where('updated_at', '>=', now()->subMinutes(self::STALE_PROGRESS_MINUTES))->exists()` (it's still making progress).
3. **A/B winner never recorded:** if `$campaign->ab_enabled && $campaign->ab_winner === null`, held recipients exist, and `$campaign->sending_started_at` is not null and `<= now()->subMinutes($campaign->ab_wait_minutes + self::STALE_PROGRESS_MINUTES)`, then dispatch `PickAbTestWinner::dispatch($campaign->id, $campaign->workspace_id)` through the throttle below. Continue to the next campaign.
4. **Winner recorded but held recipients not released:** if `$campaign->ab_winner !== null` and held recipients exist, dispatch `PickAbTestWinner` through the throttle. Continue to the next campaign.
5. **Queued recipients with no progress:** if queued recipients exist, run `app(QueueCampaignBatches::class)->handle($campaign)` through the throttle.

**Throttle.** Wrap each dispatch or re-queue in steps 3-5 in `if (Cache::add("marketing:recover:campaign:{$campaign->id}", true, now()->addMinutes(self::STALE_PROGRESS_MINUTES))) { ... }` (`Illuminate\Support\Facades\Cache`). This caps the safety net at one re-queue per campaign per two hours, so a campaign whose batches are just waiting behind a long queue doesn't pile up duplicate jobs every five minutes.

**(c) `wakeStuckAutomationRuns()`.**

```php
AutomationRun::withoutGlobalScopes()
    ->where('status', AutomationRunStatus::Active)
    ->where('updated_at', '<', now()->subMinutes(self::STALE_PROGRESS_MINUTES))
    ->update(['status' => AutomationRunStatus::Waiting, 'next_run_at' => now()]);
```

The existing every-minute `AdvanceDueAutomationRuns` then claims and advances them.

Register the sweep in `MarketingServiceProvider::registerSchedule` as a fifth line, after the existing four:

```php
$schedule->call(new RecoverStalledSends)->everyFiveMinutes()->name('marketing:recover-stalled-sends')->withoutOverlapping(10);
```

Change the `RecipientStatus::Sending` docblock to: `/** Claimed by a worker. A recipient left here after a crash is marked failed by the stalled-send sweep, never retried, so nobody gets a duplicate. */`

**Verify**: `CACHE_STORE=array php artisan schedule:list` → includes `marketing:recover-stalled-sends`
**Verify**: `cd packages/core && vendor/bin/pest --compact` → all pass except `ScheduleTest` (it still expects 4 entries; fixed in Step 6)

### Step 6: Tests

In `tests/Feature/ScheduleTest.php`, change `toHaveCount(4)` to `toHaveCount(5)`. Change nothing else in that file. Then write `tests/Feature/RecoveryTest.php` per the Test plan.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/RecoveryTest.php` → 10 passed
**Verify**: `cd packages/core && vendor/bin/pest --compact` → all pass

## Test plan

**File setup for `tests/Feature/RecoveryTest.php`.**

`beforeEach`:

- `[$this->owner, $this->workspace] = $this->createOwner();`
- `$this->workspace->update(['postal_address' => '1 High Street']);`
- `$this->transports = new CapturingTransports; $this->app->instance(ProviderTransports::class, $this->transports);`
- `$this->provider = Provider::create(['name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 100, 'credentials' => ['host' => 'h', 'port' => '25', 'encryption' => 'starttls']]);`

Helper names below are unique to this file. Define them as functions in the file, in this exact form:

```php
function recoveryStart($test, int $contacts = 3, array $overrides = []): Campaign
{
    Contact::factory()->count($contacts)->create();
    $campaign = Campaign::factory()->create(['provider_id' => $test->provider->id, ...$overrides]);
    app(ScheduleCampaign::class)->handle($campaign, $test->workspace->fresh());
    $test->useWorkspace($test->workspace);

    return $campaign->fresh();
}

function recoverySweep($test): void
{
    (new RecoverStalledSends)();
    $test->useWorkspace($test->workspace);
}

function recoveryAge(Builder $query, int $minutes): void
{
    $query->update(['updated_at' => now()->subMinutes($minutes)]);
}
```

Here `Builder` is `Illuminate\Database\Eloquent\Builder`. Aging must come **after** any status change you make, or set both in one `update`. Pause a workspace with `$this->workspace->update(['sending_paused_at' => now(), 'sending_paused_reason' => 'Test'])`, and **always after** `recoveryStart`, because scheduling a campaign in a paused workspace throws.

**Tests**:

1. `a recipient left sending by a crash is failed and the campaign finishes`. `recoveryStart($this, 2)` (sends synchronously; the campaign ends `Sent`). Then:
    - set the campaign back to `Sending`: `Campaign::query()->whereKey($c->id)->update(['status' => CampaignStatus::Sending])`;
    - set one recipient to `Sending` with `updated_at` 60 minutes ago, in one `update`;
    - sweep.

    Expect: that recipient is `Failed` with a non-empty `error`; the campaign is `Sent`; `failed_count` went up by 1 from its value before the sweep.

2. `a recipient claimed moments ago is left alone`. Same as 1, but `updated_at` is now. Expect the recipient is still `Sending` and the campaign still `Sending`.
3. `a sending campaign with nothing left waiting is finished`. `recoveryStart($this, 2)`, set the campaign back to `Sending` (all recipients are already `Sent`), then sweep. Expect `Sent`.
4. `a paused workspace does not burn batch attempts`. `Queue::fake([SendCampaignBatch::class])`, then `$c = recoveryStart($this, 3)`, then pause. Build `$job = (new SendCampaignBatch($c->id, $this->workspace->id, CampaignRecipient::pluck('id')->all()))->withFakeQueueInteractions();` and run `app()->call([$job, 'handle'])`. Expect `$job->assertNotReleased()`, `$this->transports->sent` is empty, and 3 `Queued` recipients.
5. `resuming re-queues campaigns that were sending`. A standalone test, repeating test 4's setup up to and including the pause (no job call needed). Then `$this->actingAs($this->owner)->post(route('marketing.sending.resume'))`. Expect `Queue::assertPushed(SendCampaignBatch::class, 2)`: one push from preparation, one from resume.
6. `a sending campaign with no progress is queued again by the sweep, at most once per window`. `Queue::fake([SendCampaignBatch::class])`, `recoveryStart($this, 3)`, `recoveryAge(CampaignRecipient::query(), 180)`, then sweep **twice**. Expect `Queue::assertPushed(SendCampaignBatch::class, 2)`: preparation plus one re-queue, not two.
7. `the sweep leaves paused workspaces alone`. As 6 (one sweep), but pause after `recoveryStart` and before the sweep. Expect `Queue::assertPushed(SendCampaignBatch::class, 1)`.
8. `an A/B winner job that died after choosing still releases the held recipients`.
    - `Queue::fake([PickAbTestWinner::class])`.
    - `recoveryStart($this, 20, ['subject' => 'Subject A', 'ab_enabled' => true, 'ab_subject_b' => 'Subject B', 'ab_sample_percent' => 20, 'ab_wait_minutes' => 60])`.
    - Set `ab_winner` to `'a'` with `Campaign::query()->whereKey(...)->update([...])`.
    - Run `app()->call([new PickAbTestWinner($c->id, $this->workspace->id), 'handle'])`, then `useWorkspace`.

    Expect 0 `Held` recipients and the campaign `Sent`. (This test fails on the old code, which returned early once a winner existed.)

9. `a lost A/B winner job is dispatched again after its window`. Same setup as 8, but leave `ab_winner` null. Update `sending_started_at` to `now()->subMinutes(60 + 121)` and age all recipients by 180 minutes, then sweep. Expect `Queue::assertPushed(PickAbTestWinner::class, 2)`: one from preparation, one from the sweep.
10. `a failed automation run is exited and a long-stuck active run is woken`.
    - `$automation = Automation::factory()->create(['name' => 'Welcome'])`.
    - `$contacts = Contact::factory()->count(2)->create()`.
    - Create two runs directly: `AutomationRun::create(['automation_id' => $automation->id, 'contact_id' => $contacts[0]->id, 'status' => AutomationRunStatus::Active])`, and the same for `$contacts[1]`.
    - Call `(new AdvanceAutomationRun($run1->id, $this->workspace->id))->failed(new RuntimeException('boom'))`, then `useWorkspace`. Expect run 1 is `Exited` with `exit_reason` "Stopped after an error".
    - Age run 2 by 180 minutes with `recoveryAge(AutomationRun::query()->whereKey($run2->id), 180)`, sweep, and expect run 2 is `Waiting`.

    (Don't use `EnrollContact`; under the sync queue it runs the run to completion immediately.)

## Done criteria

- [ ] `test -f packages/core/src/Jobs/RecoverStalledSends.php && test -f packages/core/src/Campaigns/QueueCampaignBatches.php && echo ok` → `ok`
- [ ] `grep -n "release(300)" packages/core/src/Jobs/SendCampaignBatch.php` → no match
- [ ] `grep -n "function failed\|uniqueFor" packages/core/src/Jobs/AdvanceAutomationRun.php` → 2 matches
- [ ] `grep -n "Cache::add" packages/core/src/Jobs/RecoverStalledSends.php` → at least 1 match
- [ ] `CACHE_STORE=array php artisan schedule:list` lists `marketing:recover-stalled-sends`
- [ ] `cd packages/core && vendor/bin/pest --compact` passes, including 10 tests in `RecoveryTest.php`, and `ScheduleTest` expects 5
- [ ] `php artisan test --compact` passes; `vendor/bin/pint --test` exits 0
- [ ] PHPStan reports no errors in any file this plan touched
- [ ] `git diff --stat dev...HEAD` lists only in-scope files

## STOP conditions

- The drift check shows changes and an excerpt above no longer matches.
- An existing test asserts that `SendCampaignBatch` releases while paused, or that `PickAbTestWinner` returns early when a winner exists. Report it; don't delete or weaken the test.
- PHPStan reports errors in files this plan didn't touch. Note them in your report and carry on; don't fix them. At planning time three existed in the author's uncommitted files.
- Any change appears to need edits to `RecipientDelivery.php`, a migration, or a new enum case.
- A test in the Test plan cannot be made to pass without changing the behaviour this plan specifies (not just test setup). Report the test, what you observed, and why.

## Maintenance notes

- `STALE_SENDING_MINUTES` must stay above `SendCampaignBatch::$timeout` (900s). `STALE_PROGRESS_MINUTES` must stay above the queue's `retry_after` (3660s on database/redis after plan 002). If either timeout is raised, raise these too.
- The beanstalkd connection still has `retry_after` 90 (`config/queue.php:51`). On beanstalkd, a batch can be redelivered mid-run. That can't double-send (the claim prevents it), but it does produce extra jobs. Raise it if that driver is ever used.
- Known, accepted duplicates (no duplicate emails):
    - Under a daily cap, released batch jobs come back just after midnight, and the sweep may re-queue the same recipients once around then.
    - Pass (c) can re-run the in-progress step of a run whose job was lost. For a webhook step, that means the webhook may be posted twice, once.
- Reviewers should check that every new path that queues batches goes through `QueueCampaignBatches`, and that any new "retry" path goes through the `Cache::add` throttle.
- Deferred: `PrepareCampaign` has no `failed()`, so a campaign whose preparation throws three times stays `sending` with few or no recipients. Fixing it needs a product decision (back to draft with a visible error, or auto-retry), likely with a new column. Plan it separately.
