# Plan 027: `POST events` accepts an `Idempotency-Key`, and each event starts automations once

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Every path below is relative to
> `packages/core/` unless it says otherwise. If anything in the "STOP
> conditions" section occurs, stop and report. Don't improvise. When done,
> update this plan's row in `plans/README.md`, unless a reviewer dispatched
> you and told you they maintain the index. Also tell the operator, so they
> can mark plan 011 in the Laravel client's index (`gerenuk/nudge-for-laravel`,
> local clone `C:\Dev\nudge-for-laravel`), where this plan began.
>
> **Drift check (run first)**:
> `git diff --stat 9f2f007..HEAD -- packages/core/src/Http/Controllers/Api/EventController.php packages/core/src/Jobs/StartAutomationsForTrigger.php packages/core/src/Automations/AutomationTriggers.php packages/core/src/Models/ActivityEvent.php packages/core/database/migrations packages/core/routes/api.php`
> If the controller, job or triggers class changed, compare them with the
> "Current state" excerpts. On a mismatch, STOP. Other new migrations are fine.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MEDIUM. It changes the hot path for API events and adds a migration to a large, append-only table.
- **Depends on**: none. The matching client change is plan 012 in `nudge-for-laravel`, and it can ship before or after this plan.
- **Category**: bug
- **Planned at**: commit `9f2f007` (branch `dev`), 2026-11-11. This is a copy of plan 011 in `nudge-for-laravel`. The design comes from that repo's `plans/007-findings.md` → "Decisions" and "Chosen server design". This repo's index already lists the gap at `plans/README.md:65`.

## Why this matters

The Laravel client retries queued events after timeouts and 5xx responses. The
server writes the event row before it answers, so each retry of a request that
was already recorded adds another row and matches automations again. When an
automation allows re-entry and its first run has already finished, the
duplicate starts a new run, and the customer gets a second email or webhook.
There's a second case: if dispatching the trigger fails after the row is
written, the client gets a 500, retries, and the row is duplicated.

After this plan, a client can send `Idempotency-Key: <uuid>`. A repeat with the
same key in the same workspace returns the original 202 and writes nothing.
Each event starts automations at most once on success, and at least once if a
dispatch fails, whether or not a key was sent.

## Current state

- `src/Http/Controllers/Api/EventController.php:22-53`, `store()`: it validates; finds or creates the contact (`:31-39`); `ActivityEvent::create([...])` (`:43-48`); `$triggers->event($contact, $validated['event'], $properties)` (`:50`); and returns `202` with `['data' => ['id' => $event->id, 'event' => $validated['event'], 'email' => $contact->email]]` (`:52`).
- `src/Automations/AutomationTriggers.php:38-41`, `event()`: `StartAutomationsForTrigger::dispatch($contact->workspace_id, $contact->id, AutomationTrigger::ApiEvent, $name, ['event' => $name, 'properties' => $properties])`.
- `src/Jobs/StartAutomationsForTrigger.php:18-63`: a `ShouldQueueAfterCommit` job. Its constructor is `(int $workspaceId, int $contactId, AutomationTrigger $trigger, string $value, array $context = [])`. `handle()` runs as the workspace, finds the contact, then enrols every matching active automation through `EnrollContact`.
- `src/Models/ActivityEvent.php:25-28`: table `events`, `#[WithoutTimestamps]`, `#[Fillable(['workspace_id', 'contact_id', 'campaign_id', 'campaign_recipient_id', 'type', 'data', 'occurred_at'])]`, with `BelongsToWorkspace` (global workspace scope, `workspace_id` stamped on create).
- `database/migrations/2026_10_29_000001_create_sending_tables.php:144-156`: the `events` table, with no unique indexes.
- `src/Actions/Contacts/UpsertContact.php:34-41`: the existing pattern for catching `UniqueConstraintViolationException` and treating it as "someone else got there first".
- Tests: `tests/Feature/AutomationTest.php:71-78` has `apiEvent($test, $email, $event)`, which posts to `route('marketing.api.events.store')` with a token from `CreateApiToken`. `tests/Feature/AutomationTest.php:306` is "custom events land on the contact timeline". `tests/Feature/ApiTest.php:19-22` has `apiToken()`.

## Commands you will need

Run these from `packages/core` (PowerShell: `php`/`composer`; Git Bash: `php.bat`/`composer.bat`).

| Purpose         | Command                                                                                       | Expected         |
| --------------- | --------------------------------------------------------------------------------------------- | ---------------- |
| Tests           | `vendor/bin/pest`                                                                             | all pass         |
| One file        | `vendor/bin/pest tests/Feature/ApiTest.php`                                                   | all pass         |
| Static analysis | `vendor/bin/phpstan analyse --memory-limit=1G` (or `composer types:check` from the repo root) | `[OK] No errors` |
| Style           | `vendor/bin/pint --test` (or `composer lint` from the root)                                   | passes           |

CI also runs the core tests on MySQL 8.4 (`.github/workflows/tests.yml:77-85`), so the migration must work on both MySQL and SQLite.

## Scope

**In scope** (`packages/core/`):

- A new migration: `database/migrations/2026_11_11_000001_add_idempotency_to_events_table.php`
- `src/Models/ActivityEvent.php`
- `src/Http/Controllers/Api/EventController.php`
- `src/Automations/AutomationTriggers.php`
- `src/Jobs/StartAutomationsForTrigger.php`
- Tests: `tests/Feature/ApiTest.php` (or a new `tests/Feature/EventIdempotencyTest.php`, if that matches local style better)
- If plan 026's OpenAPI spec (`packages/core/docs/openapi.json`) exists by the time you run this, document the header there too.

**Out of scope**:

- Keys on any other route. Only `POST events` is needed. See `plans/007-findings.md` in `nudge-for-laravel` → Decisions, item 2.
- Rejecting a reused key that arrives with a different body. This was left optional and isn't decided.
- Pruning old keys. They're UUIDs on rows that are already kept.
- Any change to the Laravel client. That is plan 012 in `nudge-for-laravel`.

## Git workflow

- Branch off `dev`: `advisor/027-event-idempotency-key`.
- The repo may have uncommitted work of the maintainer's (at planning time: `plans/README.md` modified and `plans/026-api-docs-shipped-with-core.md` untracked). **Don't stage, commit, stash or discard it.** Stage only the files you change, by path.
- One commit per step is fine. Match the server's message style (`feat: ...`, `fix: ...`, `test: ...`), for example `fix: replay API events sent again with the same Idempotency-Key`.
- Don't push or open a PR unless the operator tells you to.

## Steps

### Step 1: Migration

Create `database/migrations/2026_11_11_000001_add_idempotency_to_events_table.php`:

- `idempotency_key`: `string(255)`, nullable.
- `triggered_at`: `timestamp`, nullable.
- A unique index on `['workspace_id', 'idempotency_key']`. Both MySQL and SQLite allow many rows with a null key under a unique index, so existing rows and events without a key aren't affected.
- `down()` drops the index, then the columns.

Add both columns to `ActivityEvent`'s `#[Fillable]`. Cast `triggered_at` as `datetime`, and add both to the `@property` docblock.

**Verify**: `vendor/bin/pest` → all pass (the schema change alone breaks nothing).

### Step 2: The job claims the event before starting automations

In `StartAutomationsForTrigger`:

- Add a last constructor parameter, `public ?int $eventId = null`. The other dispatch sites (`AutomationTriggers.php:21`, `:31`) don't pass it and behave exactly as before.
- In `handle()`, inside `runAs(...)`, when `$eventId !== null`: take `Cache::lock("marketing:event-trigger:{$this->eventId}", 60)->block(10, ...)`, the same lock style as `EnrollContact.php:30`. Inside the lock:
    1. Load the event (`ActivityEvent::query()->whereKey($this->eventId)->first()`). If it's missing or `triggered_at !== null`, return. It was already handled.
    2. Run the existing matching and enrolment.
    3. Then set `triggered_at = now()` and save.

    Set `triggered_at` **after** enrolling, not before. Then a job that crashes part-way is retried and finishes the work, and the automations it already enrolled stay protected by `EnrollContact`'s "already in an active or waiting run" check (`EnrollContact.php:33-36`). The lock stops two copies running at the same time.

- When `$eventId === null`, `handle()` behaves exactly as today.

In `AutomationTriggers::event()`, add an `int $eventId` parameter and pass it to the job as `eventId:`.

**Verify**: `vendor/bin/pest tests/Feature/AutomationTest.php` → all pass after updating the one call site in Step 3. Write the job tests in Step 4.

### Step 3: The controller replays repeats

In `EventController::store()`:

1. Validate the header: `$key = $request->header('Idempotency-Key')`. If it's present, it must be a non-empty string of at most 255 visible ASCII characters (`/^[\x21-\x7E]{1,255}$/`). Otherwise return 422 with `['message' => 'The Idempotency-Key header must be 1 to 255 visible ASCII characters.']`. An absent header means `null`, which gives today's behaviour.
2. **Before** the contact lookup, if `$key !== null`, look for `ActivityEvent::query()->where('idempotency_key', $key)->first()`. The global scope limits it to the token's workspace. If one is found, return `$this->replay($existing)` (below).
3. Otherwise carry on as today, adding `'idempotency_key' => $key` to `ActivityEvent::create([...])`. Wrap the create in `try`/`catch (UniqueConstraintViolationException)`, following `UpsertContact.php:34-41`. On a violation, load the row by key and return `$this->replay($existing)`.
4. Call `$triggers->event($contact, $validated['event'], $properties, $event->id)`.

Add a private `replay(ActivityEvent $event): JsonResponse`:

- If `$event->triggered_at === null`, dispatch the trigger again: `$triggers->event($contact, $event->data['name'], $event->data['properties'] ?? [], $event->id)`. `ActivityEvent` has no `contact` relation, so load it with `Contact::query()->find($event->contact_id)`. Skip the dispatch if the contact no longer exists. Don't add a relation just for this. That covers the "row written, dispatch failed, client got a 500" case. If the original job is just slow, the duplicate dispatch is a no-op once Step 2's claim runs.
- Return `202` with `['data' => ['id' => $event->id, 'event' => $event->data['name'], 'email' => <the contact's email>]]`, the same shape as `:52`.

The repeat returns the stored event even if the repeat's body differs. The key wins. Say so in a comment.

**Verify**: `vendor/bin/pest` → all pass.

### Step 4: Tests

Add these, using `apiToken()`/`apiEvent()` style posts with `withHeader('Idempotency-Key', ...)`:

1. **A repeat returns the same event and adds no row.** Post twice with the same key. Both give 202 with the same `data.id`, and `ActivityEvent::query()->where('type', EventType::Custom)->count()` is 1.
2. **A repeat doesn't enrol again.** Use an active `api_event` automation with `allow_reentry = true` and only an exit step, so the first run completes straight away (build it with the existing helpers in `AutomationTest.php`). Post, let the queue run (the default test queue is sync), post again with the same key. `AutomationRun::count()` is 1.
3. **Without a key nothing changes.** Two posts without a header give two rows. This pins today's behaviour.
4. **The same key in two workspaces gives two events.** Use a second workspace and token. Both posts return 202 with different ids.
5. **A repeat after a failed dispatch dispatches again.** Create the event row directly, with a key and `triggered_at = null` (as if the dispatch failed). `Queue::fake()`, post with that key, then `Queue::assertPushed(StartAutomationsForTrigger::class, fn ($job) => $job->eventId === $row->id)`.
6. **A job run twice for one event enrols once.** Run `StartAutomationsForTrigger` for an event twice with `allow_reentry = true` and a completing automation. There's one run, and `triggered_at` is set after the first.
7. **A bad key is refused.** An empty key, a 256-character key, and one containing a space all give 422, and no row is created.
8. **`create_contact` with a repeat creates nothing new.** Post with `create_contact: true` and a key for a new email twice. There's one contact and one event.

Revert check: comment out the lookup from Step 3.2. Tests 1, 2 and 8 must fail. Comment out the claim from Step 2. Test 6 must fail. Restore both.

**Verify**: `vendor/bin/pest` → all pass; `vendor/bin/phpstan analyse --memory-limit=1G` → `[OK] No errors`; `vendor/bin/pint --test` → passes.

### Step 5: Docs

- If `packages/core/docs/openapi.json` exists (plan 026), document the optional `Idempotency-Key` header on `POST events`: "Send the same key when retrying. A repeat within the workspace returns the original response and records nothing new."
- Add a line to the core package's changelog, if it has one, in its own style.

**Verify**: `git status --porcelain` shows only the files in Scope (plus the maintainer's pre-existing changes, untouched).

## Done criteria

- [ ] Migration adds `idempotency_key` (unique per workspace) and `triggered_at` to `events`, and runs on SQLite and MySQL
- [ ] A repeat with the same key returns the original 202 and writes nothing
- [ ] A repeat whose original trigger never ran dispatches it again
- [ ] `StartAutomationsForTrigger` with an event id enrols at most once per event, including when run twice
- [ ] No header means today's behaviour
- [ ] All 8 tests pass, and the revert checks fail as described
- [ ] Pest, PHPStan and Pint pass
- [ ] The maintainer's uncommitted server work is untouched
- [ ] This repo's `plans/README.md` row for 027 is updated, and the operator has been told so plan 011 in `nudge-for-laravel` can be marked too

## STOP conditions

- The drift check shows the controller, job or triggers class changed in ways the excerpts don't cover.
- The unique index fails on MySQL because of existing data. It shouldn't, since every existing key is null. If it does, report it.
- Tests need the queue to be real (not sync) to run Step 4's enrolment tests, and the existing helpers can't do that.
- You find another route that the Laravel client queues. Only `PUT contacts/{email}` and `POST events` were found. Report it rather than widening scope.

## Maintenance notes

- `triggered_at` is set for every API event from now on, keyed or not. Events written before the migration have it null, so a replay of a pre-migration key can't happen (they have no key).
- If more routes ever need keys, move the lookup into middleware, but keep storage on the domain row. That's what makes the 5xx case recoverable.
- Reviewer focus: the order inside the job's lock (check, enrol, then mark), and that the replay path never inserts.
