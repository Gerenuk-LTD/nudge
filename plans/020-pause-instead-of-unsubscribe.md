# Plan 020: Recipients can pause emails for a while instead of unsubscribing

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat a86033c..HEAD -- packages/core/src/Http/Controllers/Public/UnsubscribeController.php packages/core/routes/public.php packages/core/resources/js/pages/marketing/public/unsubscribe.tsx packages/core/src/Campaigns/CampaignAudience.php packages/core/src/Sending/RecipientDelivery.php packages/core/src/Models/Contact.php`
> Expected: no output. If a security fix touched these files, compare them with the excerpts; on a real mismatch, STOP.

## Status

- **Priority**: P2 (wanted for the Christmas campaign)
- **Effort**: M
- **Risk**: MED. This touches who gets sent to. Paused contacts must be skipped everywhere a subscribed contact would be.
- **Depends on**: none
- **Category**: feature
- **Planned at**: commit `a86033c`, 2026-10-30

## Why this matters

Around the holidays, many people unsubscribe because they're getting too much email, not because they've lost interest. If the unsubscribe page offers "Pause for 30 days" or "Pause for 90 days" next to "Unsubscribe", a share of those people pause instead and come back automatically. The pause must hold everywhere: broadcasts, automation emails, audience counts, and the stalled-send sweep re-queuing work.

## Current state

All paths are relative to `packages/core/`.

**Contacts** (`database/migrations/2026_10_28_000002_create_audience_tables.php`) have `status` (`'subscribed'`/`'unsubscribed'`), `unsubscribed_at` and `archived_at`. `src/Models/Contact.php` has:

- `#[Fillable([... 'status', 'source', 'unsubscribed_at', 'archived_at'])]`;
- the casts `'status' => ContactStatus::class`, `'unsubscribed_at' => 'datetime'`;
- the `@property` docs.

**Who gets sent to.** There are two filters, and both must learn about pauses:

- `src/Campaigns/CampaignAudience.php`:

    ```php
    public function reachable(): Builder
    {
        return Contact::query()
            ->active()
            ->where('status', ContactStatus::Subscribed)
            ->whereNotIn('email', Suppression::query()->select('email'));
    }
    ```

    `sizes()` repeats the same three conditions inside a `withCount` closure (`reachableContacts()` since plan 001).

- `src/Sending/RecipientDelivery.php`, after claiming a recipient (around line 51):

    ```php
    if ($contact->archived_at !== null || $contact->status !== ContactStatus::Subscribed || Suppression::where('email', $recipient->email)->exists()) {
        $recipient->update(['status' => RecipientStatus::Skipped]);

        return DeliveryOutcome::Skipped;
    }
    ```

    Automation emails go through the same `deliver()` (from `StepRunner::sendEmail`), so this one check covers them.

**The public unsubscribe flow:**

- `routes/public-pages.php`: `GET u/{recipient}/{signature}` → `UnsubscribeController@show` (web middleware, Inertia page).
- `routes/public.php`: `POST u/{recipient}/{signature}` → `UnsubscribeController@store`, with no session or CSRF. It's authenticated by the HMAC signature in the URL. It also handles RFC 8058 one-click (`List-Unsubscribe=One-Click` returns a 200 with no page).
- `src/Http/Controllers/Public/UnsubscribeController.php`:
    - `show()` renders `marketing/public/unsubscribe` with `workspace`, `email`, `unsubscribed` and `action` (`$this->urls->unsubscribe($model)`);
    - `store()` calls `RecordEngagement::unsubscribe($model)`;
    - the private `recipient(int, string)` verifies `"unsubscribe:{$recipient}"` and loads the recipient and contact without global scopes.
- `src/Sending/TrackingUrls.php` signs payloads (`unsubscribe(CampaignRecipient)`, `verify(string $payload, string $signature)`).
- `resources/js/pages/marketing/public/unsubscribe.tsx` has no app layout. When subscribed, it shows a form with one "Unsubscribe" button posting to `new URL(action).pathname`; when unsubscribed, it shows "You are unsubscribed". Its docblock explains that it asks before acting, because link scanners open unsubscribe links.

**Events.** `src/Enums/EventType.php` has cases such as `Unsubscribed`. `ActivityEvent::recordFor(CampaignRecipient $recipient, EventType $type, array $data = [])` writes a timeline event.

**Contact profile.** `pages/marketing/contacts/show.tsx`, with its data from `ContactController::show`, shows the subscription status. Staff should see when someone is paused. Follow how `unsubscribed_at` is shown or passed.

## Commands you will need

| Purpose        | Command                                                                         | Expected                      |
| -------------- | ------------------------------------------------------------------------------- | ----------------------------- |
| Core suite     | `cd packages/core && vendor/bin/pest --compact`                                 | all pass                      |
| Host suite     | `php artisan test --compact`                                                    | all pass (it runs migrations) |
| PHPStan / Pint | as in other plans                                                               | 0 errors / exit 0             |
| Frontend       | `npm run types:check && npm run check && npm run build && CI=true npm run test` | exit 0                        |

## Scope

**In scope:**

- `database/migrations/2026_11_04_000001_add_paused_until_to_contacts_table.php` (new)
- `src/Models/Contact.php` (column, cast, PHPDoc, and an `isPaused()` helper)
- `src/Campaigns/CampaignAudience.php`
- `src/Sending/RecipientDelivery.php` (the skip check)
- `src/Enums/EventType.php` (one case)
- `src/Http/Controllers/Public/UnsubscribeController.php`
- `routes/public.php` (one route)
- `resources/js/pages/marketing/public/unsubscribe.tsx`
- `src/Http/Controllers/ContactController.php` and `pages/marketing/contacts/show.tsx` (display only)
- Tests: `tests/Feature/SendingTest.php` and `tests/Feature/TrackingTest.php`, or a new `tests/Feature/PauseTest.php`

**Out of scope:**

- A full preference centre (topics or lists).
- The API and imports setting pauses.
- A staff UI for setting a pause; staff only see it.
- Changing what one-click unsubscribe does. It must stay a real unsubscribe.

## Git workflow

- Branch: `advisor/020-pause-emails`.
- Commit: `feat: let recipients pause emails for 30 or 90 days instead of unsubscribing`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Store the pause

- **Migration:** add a nullable `timestamp('paused_until')` to `contacts`, after `unsubscribed_at`. Its `down()` drops it.
- **`Contact`:**
    - add the column to `#[Fillable]`;
    - add the cast `'paused_until' => 'datetime'`;
    - add `@property CarbonInterface|null $paused_until`;
    - add this helper:

        ```php
        /** Whether the contact asked for a break from emails that hasn't ended yet. */
        public function isPaused(): bool
        {
            return $this->paused_until !== null && $this->paused_until->isFuture();
        }
        ```

- **`EventType`:** add `case Paused = 'paused';`. Check whether `ContactTimeline` or the frontend maps event types to labels or icons; if so, add a label "Paused emails".

**Verify**: `php artisan test --compact` → passes (migrations run)

### Step 2: Never send to paused contacts

- **`CampaignAudience::reachable()` and the `sizes()` closure:** add `->where(fn (Builder $q) => $q->whereNull('paused_until')->orWhere('paused_until', '<=', now()))`. If `reachable()` and `reachableContacts()` repeat the same conditions, give both this condition.
- **`RecipientDelivery`:** add `|| $contact->isPaused()` to the skip condition.

**Verify**: `cd packages/core && vendor/bin/pest --compact` → all pass

### Step 3: Offer the pause on the unsubscribe page

- **Route** in `routes/public.php`, next to the unsubscribe POST: `Route::post('u/{recipient}/{signature}/pause', [UnsubscribeController::class, 'pause'])->whereNumber('recipient')->name('unsubscribe.pause');`. It reuses the same signature (`unsubscribe:{id}`), so no new signing is needed.
- **`UnsubscribeController::pause(Request $request, int $recipient, string $signature)`:**
    - validate `days` with `['required', 'integer', 'in:30,90']`;
    - load the recipient with the existing private `recipient()` method;
    - inside `runAs` the workspace:
        - set `paused_until = now()->addDays($days)` on the contact, but only if the contact is subscribed. A pause never resubscribes anyone;
        - record `ActivityEvent::recordFor($model, EventType::Paused, ['days' => $days])`;
    - redirect back to the unsubscribe page URL.
- **`show()`:** also pass `pausedUntil` (an ISO string or null) and `pauseAction` (the pause URL, built the same way as `action`). Add a `pauseUrl()` method to `TrackingUrls` if that's cleaner; it must use the same signature payload.
- **`unsubscribe.tsx`:**
    - when subscribed and not paused, show two secondary buttons above "Unsubscribe": "Pause for 30 days" and "Pause for 90 days". Each posts `{ days }` to the pause action's pathname, the way the unsubscribe form posts;
    - when paused, show "Emails are paused until <date>." and keep the "Unsubscribe" button available;
    - keep the page's no-layout style, and update the docblock to mention pausing.

**Verify**: `npm run types:check && npm run check && npm run build` → exit 0

### Step 4: Show it to staff

In `ContactController::show`, pass `paused_until` as an ISO string or null. In `contacts/show.tsx`, show "Paused until <date>" next to the subscription status when it's set and still in the future. Follow the existing badge style.

**Verify**: types, lint and build → exit 0

## Test plan

Put these in a new `tests/Feature/PauseTest.php`. Use the `beforeEach` and `sendNow` patterns from `SendingTest.php`, and `CapturingTransports`.

1. `test('a recipient can pause emails from the unsubscribe page')`: send a campaign, take the recipient, post `days=30` to the pause URL built by `TrackingUrls`. Expect the contact's `paused_until` to be about 30 days ahead, the status still `Subscribed`, and a `Paused` event.
2. `test('paused contacts are left out of new campaigns and their counts')`: pause one of 3 contacts. `CampaignAudience::count()` for an all-contacts campaign is 2, and sending reaches 2 (`transports->sent`).
3. `test('a contact paused after the audience was built is skipped at send time')`: `Queue::fake` the batch, schedule, then pause one contact, then run the batch. That recipient is `Skipped`.
4. `test('a pause ends by itself')`: set `paused_until` in the past. The contact is reachable again.
5. `test('pausing needs a valid signature and an allowed length')`: a bad signature gives 404, and `days=7` gives a validation error.
6. `test('pausing never resubscribes someone who unsubscribed')`: for an unsubscribed contact, `paused_until` stays null and the status stays unsubscribed.
7. `test('one-click unsubscribe still unsubscribes')`: post `List-Unsubscribe=One-Click` and expect the status `Unsubscribed`. Keep or reuse the existing one-click test if there is one.

**Revert check**: remove the `isPaused()` check from `RecipientDelivery`, confirm test 3 fails, then restore it.

## Done criteria

- [ ] The new migration exists, and `grep -n "paused_until" packages/core/src/Campaigns/CampaignAudience.php` matches
- [ ] `grep -n "isPaused" packages/core/src/Sending/RecipientDelivery.php` matches
- [ ] `grep -n "unsubscribe.pause" packages/core/routes/public.php` matches
- [ ] Core and host suites pass with the new tests; PHPStan reports 0 errors; Pint is clean; the frontend checks and build pass
- [ ] Only in-scope files changed

## STOP conditions

- An excerpt doesn't match.
- There's another place that decides who to send to, besides `CampaignAudience` and `RecipientDelivery`. For example, a segment-triggered automation might enrol from a different query. Report it; a pause has to hold everywhere.
- The public POST can't receive `days` without CSRF or session problems. It sits with the existing session-less unsubscribe POST, so it shouldn't.

## Maintenance notes

- Every new send path must respect `paused_until`. `RecipientDelivery` is the backstop, but audience counts should match too.
- A pause isn't a consent change. One-click and "Unsubscribe" stay real unsubscribes.
- Follow-up: "Pause until the new year" as a dated option during the holiday season, and a full preference centre.
