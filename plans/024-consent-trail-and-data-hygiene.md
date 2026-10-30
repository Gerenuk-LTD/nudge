# Plan 024: Every subscription change is on record, logs hold no contact data, and uploaded spreadsheets don't linger

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat a86033c..HEAD -- packages/core/src/Http/Controllers/ContactController.php packages/core/src/Actions/Contacts packages/core/src/Http/Controllers/Api/ContactController.php packages/core/src/Http/Controllers/SuppressionController.php packages/core/src/Jobs/ProcessImport.php packages/core/src/Sending/RecipientDelivery.php packages/core/src/Support/ContactTimeline.php packages/core/src/Enums/EventType.php packages/core/src/MarketingServiceProvider.php app/Http/Controllers/Settings/ProfileController.php`
> Plans 020 and 023 may have touched `RecipientDelivery.php`, `EventType.php` or `MarketingServiceProvider.php`. Merge around their changes; that alone isn't a STOP.

## Status

- **Priority**: P1. The Christmas send makes proof of consent matter.
- **Effort**: S–M
- **Risk**: LOW. The changes add records and delete files that are no longer needed.
- **Depends on**: none
- **Category**: security / compliance
- **Planned at**: commit `a86033c`, 2026-10-30

## Why this matters

1. **No proof of consent.** When a recipient unsubscribes, the contact timeline records it. Several other paths change subscription status without a trace:
    - a staff member clicking "Subscribe again";
    - changing the status on the contact edit form;
    - an API call with `resubscribe: true` (plan 007);
    - an import that unsubscribes people.

    If someone who unsubscribed complains after the Christmas send, you can't show who opted them back in, or when. Under GDPR the burden of proving consent is on you. Deleting a bounce or complaint suppression is also unrecorded.

2. **Contact data in logs.**
    - `ProcessImport` calls `report($exception)` on a `QueryException`. Laravel puts the SQL _with bindings_ in that message, which means the row's email, name and custom fields.
    - `RecipientDelivery` reports every failed send, and provider errors often quote the recipient address.

    Logs sit outside every retention and erasure control.

3. **Uploaded spreadsheets are kept forever.** `ProcessImport` never deletes the uploaded CSV. Files are only removed if someone deletes the import by hand. Every contact spreadsheet ever uploaded stays on disk, including columns that were never mapped (phone numbers, addresses).
4. **A self-lockout bug.** A case-only email change (`Ann@x.com` to `ann@x.com`) skips the password check, which compares lowercased values, but saves the email un-lowercased. Login lowercases its input, so on case-sensitive databases (SQLite, Postgres) the user can no longer sign in.

## Current state

All paths are relative to `packages/core/` unless they start with `app/`.

**`src/Http/Controllers/ContactController.php`:**

```php
/**
 * Stops all marketing email to the contact straight away, and notes on their timeline who did it.
 */
public function unsubscribe(Request $request, Contact $contact): RedirectResponse
{
    if ($contact->status !== ContactStatus::Unsubscribed) {
        DB::transaction(function () use ($request, $contact): void {
            (new ChangeContactStatus)->apply($contact, ContactStatus::Unsubscribed);
            $contact->save();

            $contact->events()->create([
                'type' => EventType::Unsubscribed,
                'data' => ['by' => $request->user()->name],
                'occurred_at' => now(),
            ]);
        });
    }
    ...
}

public function resubscribe(Contact $contact): RedirectResponse
{
    (new ChangeContactStatus)->apply($contact, ContactStatus::Subscribed);
    $contact->save();
    ...
}
```

`update()` (around line 112) calls `UpdateContact::handle($contact, ContactData::fromValidated(...))`, and that applies `$data->status` through `ChangeContactStatus` (`src/Actions/Contacts/UpdateContact.php:37`).

`src/Actions/Contacts/ChangeContactStatus.php` sets `status` and `unsubscribed_at`, without saving.

**`src/Actions/Contacts/UpsertContact.php`** (after plan 007). Inside its transaction, it applies `$data->status` when `! $resubscribing || $allowResubscribe`. Callers:

- `Api\ContactController::upsert` (`ContactSource::Api`, which passes `resubscribe`);
- `ProcessImport` (`ContactSource::Import`, which only sends `unsubscribed`);
- the web "Add contact" form (`Manual`, new contacts only).

**`src/Support/ContactTimeline.php`** (lines 30-45) maps each `EventType` to a label and a detail. For example, `EventType::Unsubscribed => [__('Unsubscribed'), match (true) { ... }]` reads `data['by']` and similar keys; read the whole match arm. **`src/Enums/EventType.php`** has `Unsubscribed` and others; plan 020 may have added `Paused`.

**`src/Http/Controllers/SuppressionController.php` `destroy(Suppression $suppression)`** deletes the suppression and flashes a toast.

**`src/Jobs/ProcessImport.php`:**

- per row (since plan 015): `catch (QueryException $exception) { report($exception); ...error row with __('This row could not be saved.') }`;
- at the end: `$import->update(['status' => ImportStatus::Completed, 'finished_at' => now()]);`;
- `failed(?Throwable $exception)` marks the import Failed.

The file sits at `$import->file_path` on `config('marketing.imports.disk')`. `ImportController::destroy` shows the deletion call: `Storage::disk(config('marketing.imports.disk'))->delete($import->file_path)`.

`ImportController::show` reads preview rows from the file only for **pending** imports (around lines 73-81); confirm this. Error CSVs come from `import_errors` rows, not the file.

**`src/Sending/RecipientDelivery.php`**, in the send failure branch:

```php
} catch (Throwable $exception) {
    report($exception);

    $recipient->update(['status' => RecipientStatus::Failed, 'error' => mb_substr($exception->getMessage(), 0, 1000)]);
```

**`src/MarketingServiceProvider.php` `registerSchedule()`** adds `$schedule->call(new X)->...->name('marketing:...')->withoutOverlapping(N)` entries. `tests/Feature/ScheduleTest.php` asserts the **count** of `marketing:*` entries, so update it when adding one.

**`app/Http/Controllers/Settings/ProfileController.php` `update()`** (plan 009) fills from `$request->safe()->except('current_password')`, then checks `isDirty('email')`. `Contact::normalizeEmail()` exists in core, but for users use `mb_strtolower(trim(...))`.

## Commands you will need

| Purpose        | Command                                         | Expected          |
| -------------- | ----------------------------------------------- | ----------------- |
| Core tests     | `cd packages/core && vendor/bin/pest --compact` | all pass          |
| Host tests     | `php artisan test --compact`                    | all pass          |
| PHPStan / Pint | as usual                                        | 0 errors / exit 0 |

## Scope

**In scope:**

- `src/Enums/EventType.php` (one case: `Resubscribed`)
- `src/Support/ContactTimeline.php` (its label)
- `src/Http/Controllers/ContactController.php` (`resubscribe` and `update`)
- `src/Actions/Contacts/UpdateContact.php`
- `src/Actions/Contacts/UpsertContact.php`
- `src/Http/Controllers/SuppressionController.php` (`destroy`)
- `src/Jobs/ProcessImport.php`
- `src/Jobs/PruneStaleImports.php` (new)
- `src/MarketingServiceProvider.php` (schedule one entry)
- `src/Sending/RecipientDelivery.php` (the report line)
- `app/Http/Controllers/Settings/ProfileController.php` (lowercase email)
- tests, including `tests/Feature/ScheduleTest.php`'s count

**Out of scope:**

- Contact erasure ("forget this contact"). That's a follow-up plan.
- Log rotation settings, which plan 021 covers in `.env.example` and `DEPLOYING.md`.
- The automation approval question.

## Git workflow

- Branch: `advisor/024-consent-and-data-hygiene`.
- Make one commit per step.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Record every status change

- Add `case Resubscribed = 'resubscribed';` to `EventType`. In `ContactTimeline`, add a label, `__('Subscribed again')`, with detail text built from `data['by']` and `data['via']`, following the `Unsubscribed` arm's pattern.
- Create one helper for recording, for example a static `ChangeContactStatus::record(Contact $contact, ContactStatus $to, array $context): void`, or a small new class. It creates the event (`Unsubscribed` or `Resubscribed`) with `data` holding the context. Only call it when the status really changed. Compare before and after; `ChangeContactStatus::apply` returns early when nothing changes, so capture the status beforehand.
- Use it everywhere a status changes:
    - **`ContactController::resubscribe`**: context `['by' => user name, 'via' => 'dashboard']`, inside a transaction. Add `Request $request` as a parameter.
    - **`ContactController::unsubscribe`**: switch to the helper and keep its data, adding `'via' => 'dashboard'`.
    - **`UpdateContact`**: when the status changes, context `['via' => 'contact form']` plus `by` passed in from the controller. Add an optional `?string $by = null` parameter to `handle()`; the controller passes the user's name.
    - **`UpsertContact`**: when a status change is actually applied to an **existing** contact, context `['via' => $source->value]`. For the API, also add the token ID: add an optional `array $context = []` parameter, and have `Api\ContactController` pass `['token_id' => $request->attributes->get('marketing_api_token')?->id]`. New contacts don't get an event; their creation is the record.
- In **`SuppressionController::destroy`**, write `Log::notice('Suppression removed', ['workspace' => $suppression->workspace_id, 'suppression' => $suppression->id, 'reason' => $suppression->reason->value, 'by' => $request->user()->getAuthIdentifier()])`. Don't log the email. The events table needs a contact, and suppressions may have none, which is why this uses a log line.

**Verify**: core suite passes.

### Step 2: Keep contact data out of logs

- **`ProcessImport`**: replace `report($exception)` with `Log::warning('Import row could not be saved', ['import' => $import->id, 'row' => $line, 'sqlstate' => $exception->getCode()])`. That keeps no SQL and no bindings. Use `$exception->errorInfo[0] ?? $exception->getCode()` for the SQLSTATE, whichever is available.
- **`RecipientDelivery`**: replace `report($exception)` with `Log::warning('Campaign send failed', ['campaign' => $campaign->id, 'recipient' => $recipient->id, 'provider' => $provider->id, 'exception' => $exception::class])`. Keep storing the message in `campaign_recipients.error` as it is today; that's inside the app, under workspace access control.

**Verify**: core suite passes.

### Step 3: Don't keep uploaded spreadsheets

- **`ProcessImport`**: delete the file after marking the import `Completed`, and in `failed()`, using `Storage::disk(config('marketing.imports.disk'))->delete($import->file_path)`. Wrap the delete so a missing file is ignored.
- **Create `src/Jobs/PruneStaleImports.php`**, an invokable like `DispatchDueCampaigns`. It finds imports across workspaces (`Import::withoutGlobalScopes()`) still `Pending` (never mapped or started) with `created_at` older than 7 days. For each, it deletes the file and the import. Give it a class PHPDoc.
- **Schedule it daily:** `$schedule->call(new PruneStaleImports)->daily()->name('marketing:prune-stale-imports')->withoutOverlapping(60);`. Update `ScheduleTest`'s expected count by one.

**Verify**: core suite passes, including ScheduleTest.

### Step 4: Store account emails lowercased

In `ProfileController::update`, after filling, set `$request->user()->email = mb_strtolower(trim($request->user()->email));` before the `isDirty('email')` check, so a case-only change isn't saved as a change.

**Verify**: host suite passes.

## Test plan

Core:

1. `test('subscribing someone again from the dashboard is recorded with who did it')`: a `Resubscribed` event exists with `by` and `via: dashboard`.
2. `test('changing status on the contact form is recorded')`.
3. `test('an api resubscribe is recorded with the token')`: `PUT` with `resubscribe: true`, and the event `data.via` is `api` with a `token_id`.
4. `test('an import that unsubscribes people records it')`: an import with a status column of `unsubscribed` produces an `Unsubscribed` event with `via: import`.
5. `test('no event is recorded when nothing changed')`: resubscribing an already-subscribed contact adds no event.
6. `test('a completed import deletes its uploaded file')`: after `uploadAndStart`, `Storage::disk('local')->assertMissing($import->file_path)`.
7. `test('stale unstarted imports are pruned')`: an import created and left `Pending` 8 days ago is removed with its file after running `PruneStaleImports`. A 1-day-old one is kept.
8. `test('a failed import row does not put contact data in the log')`: use `Log::spy()` or `Log::shouldReceive`. Force a `QueryException` for one row, for example with a value too long for a column on the test DB if that's possible. If that's not possible on SQLite, assert at unit level that the warning context has no `email` key. Note what you did.

Host:

9. `test('changing only the case of the email is not a change')`: no `current_password` needed, and the stored email is lowercase.

**Revert check:** remove the event recording from `resubscribe`, confirm test 1 fails, then restore it.

## Done criteria

- [ ] `grep -n "case Resubscribed" packages/core/src/Enums/EventType.php` → match
- [ ] `grep -n "report(\$exception)" packages/core/src/Jobs/ProcessImport.php packages/core/src/Sending/RecipientDelivery.php` → no matches
- [ ] `test -f packages/core/src/Jobs/PruneStaleImports.php` and `grep -n "prune-stale-imports" packages/core/src/MarketingServiceProvider.php` → match
- [ ] Core and host suites pass with the new tests; PHPStan reports 0 errors; Pint is clean
- [ ] Only in-scope files changed

## STOP conditions

- An excerpt doesn't match beyond the changes plans 020 and 023 are known to make.
- `ImportController::show` or the errors download reads the uploaded file for **completed** imports. Then deleting it would break the page; report it.
- The `events` table requires columns the helper can't fill for a status change.

## Maintenance notes

- **Any new path that changes subscription status must call the recording helper.** Reviewers should grep for `ChangeContactStatus` and check each call site records.
- **Follow-up:** a "forget this contact" erasure action. It would anonymise the contact, recipient email copies and import errors, and keep a hashed suppression so they can't be re-imported.
- **Follow-up:** a generic audit log table for workspace-level actions that have no contact, such as suppression removal and the approval setting. These use log notices for now.
