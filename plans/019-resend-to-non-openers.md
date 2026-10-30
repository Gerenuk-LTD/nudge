# Plan 019: Resend a campaign to the people who didn't open it

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat a86033c..HEAD -- packages/core/src/Segments packages/core/src/Http/Controllers/CampaignController.php packages/core/routes/web.php packages/core/resources/js/components/segment-builder.tsx packages/core/resources/js/pages/marketing/campaigns/show.tsx`
> Expected: no output. If plan 018 work or security fixes touched these files, compare them with the excerpts below; on a real mismatch, STOP.

## Status

- **Priority**: P2 (wanted for the Christmas campaign)
- **Effort**: M
- **Risk**: LOW (additive)
- **Depends on**: plans/018-engagement-segments.md (DONE). This plan extends the same segment machinery.
- **Category**: feature
- **Planned at**: commit `a86033c`, 2026-10-30

## Why this matters

A standard seasonal tactic is a "second chance" send. A few days after the main campaign, you resend it, often with a new subject line, to the people who received it but didn't open it. Today the app can't target "people who didn't open campaign X". This plan adds two things:

1. **Per-campaign segment conditions.** A "Campaign" field has these operators: received, didn't receive, opened, didn't open, clicked, didn't click. The value is the campaign.
2. **A "Resend to non-openers" action** on a sent campaign's page. In one click it creates a segment, "Didn't open: <campaign name>" (received X **and** didn't open X), and a draft copy of the campaign aimed at that segment. It then opens the copy in the editor, so you can change the subject and schedule it.

It also fixes a small bug from plan 015. `duplicate()` uses `replicate()` with an explicit exclusion list that predates the new `ab_results` column, so a duplicated A/B campaign carries the original's frozen test results.

## Current state

All paths are relative to `packages/core/`.

**Segments (plan 018 established the pattern):**

- `src/Segments/FieldType.php` has cases `Text, Number, Date, Boolean, Choice, Tag, ContactList, Activity`.
    - `operators()` returns, among others, `self::Activity => ['in_last_days', 'not_in_last_days', 'never']`.
    - `operatorNeedsValue()` lists the valueless operators: `is_empty, is_not_empty, is_true, is_false, never`.
- `src/Segments/SegmentFieldCatalog.php` `build()` lists fields including:

    ```php
    new SegmentField('activity:received', 'Received an email', FieldType::Activity, 'sent_at'),
    new SegmentField('tag', 'Tag', FieldType::Tag, null, array_values(Tag::query()->orderBy('name')->get()
        ->map(fn (Tag $tag) => ['value' => $tag->id, 'label' => $tag->name])->all())),
    ```

- `src/Segments/SegmentQuery.php` `applyCondition()` matches on `$field->type`.
    - `FieldType::Tag` uses `whereHas('tags', fn ($tags) => $tags->whereKey($value))`.
    - `FieldType::Activity` calls `applyActivity()`, which uses `whereHas('campaignRecipients', ...)`. `Contact::campaignRecipients()` exists.
- `src/Segments/SegmentDefinitionValidator.php` `valueIsValid()` validates `Choice`, `Tag` and `ContactList` values by checking that the value is in `$field->options`.
- `resources/js/components/segment-builder.tsx`:
    - `ValueInput` renders a `NativeSelect` whenever `field.options.length > 0`;
    - `describeCondition` uses the option label for option-backed fields.

**Campaigns:**

- `src/Models/Campaign.php` has a `broadcasts()` scope and `status` (`CampaignStatus`: Draft, Scheduled, Sending, Sent, Cancelled).
- `campaign_recipients` has `campaign_id`, `contact_id`, `sent_at`, `opened_at` and `clicked_at`.
- `src/Http/Controllers/CampaignController.php` `duplicate(Request $request, Campaign $campaign)`:

    ```php
    abort_if($campaign->kind === CampaignKind::Automation, 404);

    $copy = $campaign->replicate(['status', 'approved_at', 'approved_by', 'scheduled_at', 'sending_started_at', 'sent_at', 'recipients_count', 'ab_winner', 'ab_decided_at']);
    $copy->fill([
        'name' => __(':name (copy)', ['name' => $campaign->name]),
        'status' => CampaignStatus::Draft,
        'created_by' => $this->user($request)->getAuthIdentifier(),
    ]);

    // Sent copies start from what was actually sent.
    if ($campaign->snapshot !== null) {
        $copy->fill(['subject' => $campaign->snapshot->subject, 'html' => $campaign->snapshot->html]);
    }

    $copy->forceFill(array_fill_keys(['sent_count', 'failed_count', 'delivered_count', 'bounced_count', 'complained_count', 'opened_count', 'clicked_count', 'unsubscribed_count'], 0))->save();

    return to_route('marketing.campaigns.edit', $copy);
    ```

- `routes/web.php` line 106 registers `Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate'])->name('campaigns.duplicate');`. It sits inside a `can:campaigns.manage` group; check the group it's in.
- Campaigns use `audience_type` (`AudienceType` enum: AllContacts, Lists, Segment) and `segment_id`.
- `src/Models/Segment.php` stores `name` and a `definition` array of the form `['match' => 'all', 'conditions' => [...]]`. `SegmentController::store` validates through `SegmentDefinitionValidator`.
- `resources/js/pages/marketing/campaigns/show.tsx` is the sent and sending campaign page. Its header has action buttons, such as "Stop sending", which since plan 008 sits behind a confirmation dialog. Follow those buttons' pattern, and use Wayfinder actions imported from `@/actions/Gerenuk/Marketing/Http/Controllers/CampaignController`.

**Tests:**

- `tests/Feature/SegmentTest.php` has `matchingEmails($conditions, $match = 'all')`, `condition($field, $op, $value)` and a `recordEngagement($email, $sentDaysAgo, $openedDaysAgo = null, $clickedDaysAgo = null)` helper from plan 018. Read it, since it creates a campaign per call.
- `tests/Feature/SendingTest.php` covers campaign sending and has a `sendNow` helper.

## Commands you will need

| Purpose                  | Command                                                                                                                              | Expected          |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------ | ----------------- |
| Segment + campaign tests | `cd packages/core && vendor/bin/pest --compact tests/Feature/SegmentTest.php tests/Feature/SendingTest.php`                          | all pass          |
| Core suite               | `cd packages/core && vendor/bin/pest --compact`                                                                                      | all pass          |
| Host suite               | `php artisan test --compact`                                                                                                         | all pass          |
| PHPStan / Pint           | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`; `vendor/bin/pint --dirty --format agent` then `vendor/bin/pint --test` | 0 errors / exit 0 |
| Frontend                 | `npm run types:check && npm run check && npm run build && CI=true npm run test`                                                      | exit 0            |

## Scope

**In scope:**

- `src/Segments/FieldType.php`
- `src/Segments/SegmentFieldCatalog.php`
- `src/Segments/SegmentQuery.php`
- `src/Segments/SegmentDefinitionValidator.php`
- `src/Http/Controllers/CampaignController.php` (`duplicate` exclusion plus the new `resendToNonOpeners` action)
- `routes/web.php` (one route)
- `resources/js/components/segment-builder.tsx`
- `resources/js/types.ts` (the `'campaign'` field type)
- `resources/js/pages/marketing/campaigns/show.tsx`
- `tests/Feature/SegmentTest.php`
- `tests/Feature/SendingTest.php`, or a new `tests/Feature/ResendTest.php`

**Out of scope:**

- Automatic resend scheduling, which would be a "resend after N days" setting on the original campaign.
- A/B-specific resend logic.
- Automation campaigns.

## Git workflow

- Branch: `advisor/019-resend-to-non-openers`.
- Commits:
    - `fix: don't copy frozen a/b results when duplicating a campaign`;
    - `feat: segment by campaign and resend to non-openers`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Fix the duplicate

Add `'ab_results'` to the `replicate([...])` exclusion list in `CampaignController::duplicate`.

**Verify**: add a test in `SendingTest.php` (or `AbTestTest.php`):

- duplicating a campaign whose `ab_results` is set gives a copy with `ab_results` null;
- the test fails without the fix.

### Step 2: Campaign segment field

Make these changes:

- **`FieldType`:** add `case Campaign = 'campaign';` with operators `['received', 'not_received', 'opened', 'not_opened', 'clicked', 'not_clicked']`. Every one takes a value: the campaign id.
- **`SegmentFieldCatalog`:** add, after the activity fields:

    ```php
    new SegmentField('campaign', 'Campaign', FieldType::Campaign, null, array_values(Campaign::query()->broadcasts()->whereIn('status', [CampaignStatus::Sending, CampaignStatus::Sent])->latest('sent_at')->limit(100)->get(['id', 'name'])->map(fn (Campaign $campaign) => ['value' => $campaign->id, 'label' => $campaign->name])->all())),
    ```

    It shows only broadcasts that have gone out, and the 100 most recent. Import the classes.

- **`SegmentDefinitionValidator`:** add `FieldType::Campaign` to the `Choice, Tag, ContactList` arm, so the value must be one of the options.
- **`SegmentQuery`:** add `FieldType::Campaign => $this->applyCampaign($query, $operator, $value)`. In `applyCampaign`, each positive operator is `whereHas('campaignRecipients', fn ($r) => $r->where('campaign_id', $value)->whereNotNull(<column>))`, and each `not_*` operator is the same with `whereDoesntHave`. The columns are `received` → `sent_at`, `opened` → `opened_at` and `clicked` → `clicked_at`. Add a PHPDoc noting that "didn't open" also matches people who never received the campaign, so pair it with "received" to resend.

**Verify**: segment tests pass

### Step 3: Builder labels

In `segment-builder.tsx`, add the operator labels:

| Operator       | Label          |
| -------------- | -------------- |
| `received`     | received       |
| `not_received` | didn't receive |
| `opened`       | opened         |
| `not_opened`   | didn't open    |
| `clicked`      | clicked        |
| `not_clicked`  | didn't click   |

`describeCondition` should read "Campaign opened “November news”". Option-backed values already render the option label, so check the label isn't double-quoted. Add `'campaign'` to `SegmentFieldType` in `types.ts`.

**Verify**: `npm run types:check && npm run check` → exit 0

### Step 4: "Resend to non-openers"

- **Route**, inside the same group and permission as `campaigns.duplicate`: `Route::post('campaigns/{campaign}/resend', [CampaignController::class, 'resendToNonOpeners'])->name('campaigns.resend');`.
- **Action:**
    - `abort_unless($campaign->kind === CampaignKind::Broadcast && $campaign->status === CampaignStatus::Sent, 404)`.
    - Create `Segment::create(['name' => __("Didn't open: :name", ['name' => $campaign->name]), 'definition' => ['match' => 'all', 'conditions' => [['type' => 'condition', 'field' => 'campaign', 'operator' => 'received', 'value' => $campaign->id], ['type' => 'condition', 'field' => 'campaign', 'operator' => 'not_opened', 'value' => $campaign->id]]]])`. Validate the definition with `SegmentDefinitionValidator` before creating it, the way `SegmentController::store` does.
    - Then duplicate the campaign. Extract the body of `duplicate()` into a private `copyOf(Request, Campaign): Campaign` used by both actions, so the logic isn't repeated.
    - Set the copy's `audience_type` to `AudienceType::Segment`, `segment_id` to the new segment, and name it `__(':name (resend)', ...)`.
    - Redirect to the copy's edit page with a toast: "Resend drafted for people who didn't open. Change the subject line before sending."
- **UI:** on `campaigns/show.tsx`, for `status === 'sent'` and users who can `campaigns.manage`, add a "Resend to non-openers" button. It opens a confirmation dialog that says what will be created, following the "Stop sending" dialog pattern, and then posts to `CampaignController.resendToNonOpeners`. Regenerate the Wayfinder actions if the build needs them.

**Verify**: `npm run types:check && npm run check && npm run build` → exit 0

## Test plan

1. **Duplicate fix:** see Step 1.
2. **In `SegmentTest.php`, `test('campaign conditions find who received, opened or clicked a given campaign')`.** Use two campaigns:
    - ann receives and opens campaign 1;
    - bob receives campaign 1 but doesn't open it;
    - cat gets nothing.

    Expect:
    - `received 1` returns ann and bob;
    - `opened 1` returns ann;
    - received 1 plus `not_opened 1` returns bob;
    - `not_received 1` returns cat.

    Campaign IDs come from the catalog options, so the campaign must be `Sent` with `sent_at` set; use `Campaign::factory()->create(['status' => CampaignStatus::Sent, 'sent_at' => now()])` with explicit `CampaignRecipient` rows. Add an invalid case to the dataset: the id of a campaign from another workspace, or a draft campaign, is rejected.

3. **In `ResendTest.php`, `test('resending to non-openers drafts a copy aimed at the right segment')`.** Send a campaign to 3 contacts, mark one as opened, then post to `campaigns.resend`. Expect a redirect to the edit page of a new draft with `audience_type` Segment, and that `app(CampaignAudience::class)->count($copy)` is 2.
4. **`test('only sent broadcasts can be resent')`.** A draft gives 404.
5. **`test('viewers cannot resend')`.** A Viewer gets 403.

**Revert check**: remove the `whereNotNull` in the `opened` branch, confirm test 2 fails, then restore it.

## Done criteria

- [ ] `grep -n "'ab_results'" packages/core/src/Http/Controllers/CampaignController.php` matches inside `replicate([...])`
- [ ] `grep -n "case Campaign" packages/core/src/Segments/FieldType.php` matches
- [ ] `grep -n "campaigns.resend" packages/core/routes/web.php` matches
- [ ] Core and host suites pass with the new tests; PHPStan reports 0 errors; Pint is clean; the frontend checks and build pass
- [ ] Only in-scope files changed

## STOP conditions

- An excerpt doesn't match.
- The campaign options query makes every segment page noticeably heavier. It should be one small indexed query; if it's slow, report it rather than caching.
- The resend action needs a migration.

## Maintenance notes

- The "Campaign" field lists the 100 most recent sent broadcasts. If people need older ones, switch to a searchable picker.
- "didn't open" depends on open tracking. Apple Mail Privacy Protection marks many people as having opened, so resends reach fewer people than really missed the email. That's acceptable for a second chance.
