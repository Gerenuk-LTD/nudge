# Plan 015: Imports survive non-UTF-8 files, and A/B tests decide on real data and keep honest results

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 0ac75d7..HEAD -- packages/core/src/Imports/CsvReader.php packages/core/src/Jobs/ProcessImport.php packages/core/src/Jobs/PickAbTestWinner.php packages/core/src/Campaigns/AbTestResults.php packages/core/src/Http/Controllers/CampaignController.php packages/core/src/Models/Campaign.php`
> Expected: no output. On a mismatch with the excerpts, STOP.

## Status

- **Priority**: P2
- **Effort**: S–M
- **Risk**: LOW–MED (the A/B changes touch the send path; the claim-per-recipient design still prevents duplicate sends)
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `0ac75d7`, 2026-10-30

## Why this matters

There are three bugs to fix.

**1. One non-UTF-8 row fails a whole import on MySQL.** CSVs exported from Excel on Windows are often Windows-1252, so "José" is stored as the byte `0xE9`. `CsvReader` passes cells through untouched, and MySQL (`utf8mb4`, strict mode) rejects the invalid string. The exception isn't caught per row, so the import job fails at the same row on every retry, and none of the later rows are imported. SQLite accepts the bytes, so tests never caught this.

**2. An A/B winner can be picked with no data.** The winner job runs `ab_wait_minutes` after preparation, not after the sample was actually sent. If the sample is slowed down (a paused workspace, a daily cap, a low send rate), both variants can still show 0 sent when it runs. Ties go to A, so A wins by default and goes to the whole remaining audience.

**3. A/B results get mixed with the rest of the audience.** When the winner is chosen, every held recipient is given `variant = <winner>`, so the campaign page's "test results" for the winner include everyone else. The figures stop describing the test that justified the choice.

## Current state

All paths below are relative to `packages/core/`.

**`src/Imports/CsvReader.php`** `rawRows()` reads with `fgetcsv($stream, escape: '')`, strips a UTF-8 BOM from the first cell, and yields each row.

```php
while (($row = fgetcsv($stream, escape: '')) !== false) {
    if ($first && isset($row[0])) {
        $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
        $first = false;
    }

    yield $row;
}
```

**`src/Jobs/ProcessImport.php`** handles each row in a loop:

- it validates the row;
- on failure it appends an error row `['workspace_id', 'import_id', 'row_number' => $line, 'email', 'message', 'created_at']` and increments `failed_count`;
- otherwise it calls `$upsert->handle($data, ContactSource::Import)` with no `try`.

Progress is saved every `chunk_size` rows by `saveProgress($import, $counts, $errors)`.

**`src/Jobs/PickAbTestWinner.php`**:

- its constructor is `__construct(public int $campaignId, public int $workspaceId)`;
- it implements `ShouldQueue`, uses `Queueable`, and has no `$tries` property;
- inside `runAs`, it returns early unless the campaign is `Sending`;
- if `ab_winner` is null, it runs a compare-and-set update of `['ab_winner' => $results->leader($campaign), 'ab_decided_at' => now()]`;
- it then releases held recipients with `->update(['status' => Queued, 'variant' => $winner])`, queues batches if it decided or released anyone, and calls `finishIfDone`.

It is dispatched from:

- `src/Jobs/PrepareCampaign.php:162`: `PickAbTestWinner::dispatch(...)->delay(now()->addMinutes($campaign->ab_wait_minutes));`
- `src/Http/Controllers/CampaignController.php:234`: the manual "Pick the winner now" (`pickWinner`), which aborts with 409 unless `ab_enabled && ab_winner === null && status === Sending`
- `src/Jobs/RecoverStalledSends.php:130,136`: the stalled-send sweep

**`src/Campaigns/AbTestResults.php`**: `for(Campaign)` counts sent, opened and clicked recipients per `variant` in `['a','b']`. `leader()` returns `'b'` only if B's rate is strictly higher. It's used by `CampaignController::show` (`'results' => $abResults->for($campaign)`) and by `PickAbTestWinner`.

**`src/Models/Campaign.php`**:

- `#[Fillable]` includes `'ab_metric', 'ab_winner', 'ab_decided_at'`;
- the casts include `'ab_decided_at' => 'datetime'`;
- the PHPDoc lists each `@property`.

`src/Sending/CampaignMessage.php:41` picks content with `$snapshot->variant($recipient->variant)`, so held recipients _must_ get `variant = <winner>` to receive the winning content. Don't change that. Instead, keep a snapshot of the test results.

**Migrations** live in `database/migrations/`. The latest is `2026_11_02_000001_add_webhook_topic_arn_to_providers_table.php`; follow its style.

**Tests**:

- `tests/Feature/ImportTest.php` has an `uploadAndStart($test, string $csv, array $mapping, array $options = [])` helper; see the "a mapped import creates…" test.
- `tests/Feature/AbTestTest.php` has `abCampaign($test, $overrides)`, 20 contacts in `beforeEach`, and runs the job with `app()->call([new PickAbTestWinner($campaign->id, $this->workspace->id), 'handle'])`.

## Commands you will need

| Purpose                 | Command                                                                | Expected |
| ----------------------- | ---------------------------------------------------------------------- | -------- |
| Core tests              | `cd packages/core && vendor/bin/pest --compact`                        | all pass |
| Host tests (migrations) | `php artisan test --compact`                                           | all pass |
| PHPStan                 | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`           | 0 errors |
| Pint                    | `vendor/bin/pint --dirty --format agent` then `vendor/bin/pint --test` | exit 0   |

## Scope

**In scope:**

- `src/Imports/CsvReader.php`
- `src/Jobs/ProcessImport.php` (the upsert call only)
- `src/Jobs/PickAbTestWinner.php`
- `src/Campaigns/AbTestResults.php`
- `src/Http/Controllers/CampaignController.php` (`pickWinner` only)
- `src/Models/Campaign.php`
- `database/migrations/2026_11_03_000001_add_ab_results_to_campaigns_table.php` (new)
- `tests/Feature/ImportTest.php`, `tests/Feature/AbTestTest.php`

**Out of scope:**

- `CampaignMessage`, `RecipientDelivery` and `PrepareCampaign`.
- Frontend.
- Statistical significance testing. This plan only refuses to decide on no data; it doesn't judge whether a lead is meaningful.

## Git workflow

- Branch: `advisor/015-import-and-ab`.
- Make two commits, both prefixed `fix:`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Convert non-UTF-8 cells, and record database failures per row

- **`CsvReader::rawRows`**: before yielding, map each cell. A cell that is not `null` and fails `mb_check_encoding($cell, 'UTF-8')` becomes `mb_convert_encoding($cell, 'UTF-8', 'Windows-1252')`. Windows-1252 is what Excel produces on Windows, and every byte is valid in it. Add a one-line comment saying so.
- **`ProcessImport`**: wrap the `$upsert->handle(...)` call (and the tag sync after it) in `try { ... } catch (\Illuminate\Database\QueryException $exception) { ... }`. On failure, increment `failed_count`, call `report($exception)`, and append an error row with the message `__('This row could not be saved.')`. That keeps it generic and doesn't leak SQL. The import then carries on.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/ImportTest.php` → all pass

### Step 2: Snapshot the A/B results when the winner is chosen

- Create the migration, which adds a nullable `json('ab_results')` to `campaigns` after `ab_decided_at`. `down()` drops it.
- On `Campaign`:
    - add `'ab_results'` to `#[Fillable]`;
    - add the cast `'ab_results' => 'array'`;
    - add `@property array{a: array{sent: int, opened: int, clicked: int, rate: float}, b: array{sent: int, opened: int, clicked: int, rate: float}}|null $ab_results` to the PHPDoc.
- In `AbTestResults::for`, if `$campaign->ab_results !== null`, return it. Add a PHPDoc line: "Once a winner is chosen, the results from that moment are returned, so the rest of the audience doesn't blur the test."
- In `PickAbTestWinner`, compute `$snapshot = $results->for($campaign)` before the compare-and-set update. Then choose the winner from the snapshot rather than calling `leader()` again: `'b'` if B's rate is strictly higher, otherwise `'a'`. Write `'ab_results' => $snapshot` in the same update as `ab_winner`. To keep one source of truth, add `public function leaderOf(array $results): string` to `AbTestResults`, and make `leader()` call `leaderOf($this->for($campaign))`.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/AbTestTest.php` → all pass

### Step 3: Don't decide while the sample is still unsent

- Add a constructor parameter `public bool $force = false` to `PickAbTestWinner`. In `CampaignController::pickWinner`, dispatch it with `force: true`, because the user explicitly asked to pick now.
- In `handle`, when `ab_winner === null && ! $this->force`, wait if either of these holds:
    - some sample recipient is still waiting: `$campaign->recipients()->whereIn('variant', ['a', 'b'])->whereIn('status', [RecipientStatus::Queued, RecipientStatus::Sending])->exists()`;
    - the snapshot shows 0 sent for both variants.

    Don't use `release()`, because the job has no `$tries`. Instead re-dispatch yourself with `self::dispatch($this->campaignId, $this->workspaceId)->delay(now()->addMinutes(10));` and `return`.

- Cap the waiting: once `now()` is past `$campaign->sending_started_at?->copy()->addMinutes($campaign->ab_wait_minutes)->addDay()`, decide anyway. Add a comment explaining the cap: don't hold the rest of the audience forever.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/AbTestTest.php` → all pass

## Test plan

In `ImportTest.php`:

1. `test('a windows-1252 file imports names with accents')`. Upload `"email,first\njose@example.com,Jos\xE9\n"`, mapped `['email', 'first_name']`. Expect the import `Completed`, `failed_count` 0, and `first_name` === `'José'`. On the current code the raw byte is stored, so the test fails on SQLite as well; confirm that with a revert check.

In `AbTestTest.php`, using `Queue::fake([PickAbTestWinner::class])` while scheduling: 2. `test('the winner is not picked while the sample is still unsent')`. Schedule, then set every recipient with a variant back to `Queued` with `sent_at` null. Run the job without force. Expect `ab_winner` still null, and `Queue::assertPushed(PickAbTestWinner::class)` for the re-dispatch, beyond the one from preparation. 3. `test('picking the winner now decides even without data')`. Same setup, but run `new PickAbTestWinner($id, $wsId, force: true)`. Expect `ab_winner` to be `'a'`. 4. `test('the test results stay as they were when the winner was chosen')`. Schedule, give B a click, and run the job; the winner is B. Then read `app(AbTestResults::class)->for($campaign->fresh())['b']['sent']`. Expect it to equal the sample's B count (2 with 20 contacts at 20%), not 18 or more.

Revert checks: run tests 1, 2 and 4 against the code without each fix, confirm they fail, then restore the fix. Report the results.

## Done criteria

- [ ] `grep -n "Windows-1252" packages/core/src/Imports/CsvReader.php` matches
- [ ] `grep -n "QueryException" packages/core/src/Jobs/ProcessImport.php` matches
- [ ] The new migration exists, and the host suite passes (it runs migrations)
- [ ] Core suite passes with 4 new tests; PHPStan reports 0 errors; Pint is clean
- [ ] Only in-scope files changed

## STOP conditions

- An excerpt doesn't match.
- `RecoverStalledSends` breaks because of the new waiting behaviour. For example, its test for a lost A/B winner job expects an immediate decision. Report it instead of changing the sweep.
- Snapshotting results needs a frontend change to render. The `results` prop shape must stay identical.

## Maintenance notes

- Any new path that picks a winner must go through `PickAbTestWinner`, so that the snapshot and the no-data wait apply.
- If statistical significance is added later, it belongs in `AbTestResults::leaderOf`.
