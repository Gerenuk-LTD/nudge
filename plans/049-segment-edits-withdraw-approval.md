# Plan 049: Editing a segment withdraws the approvals that depend on it

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say otherwise. If anything in "STOP conditions" happens, stop
> and report. Don't improvise. When done, update this plan's row in
> `plans/README.md`, unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 6982215..HEAD -- packages/core/src/Http/Controllers/SegmentController.php packages/core/src/Http/Controllers/CampaignController.php packages/core/src/Jobs/PrepareCampaign.php packages/core/resources/js/pages/marketing/campaigns/edit.tsx packages/core/resources/js/pages/marketing/segments/form.tsx`
> If any of these changed, compare them with the "Current state" excerpts.
> On a mismatch, STOP. Plan 048 changes `AutomationController.php` and
> `AutomationApproval.php`; those changes are expected.

## Status

- **Priority**: P1. This bypasses a control the workspace switched on.
- **Effort**: M
- **Risk**: MED. It can return scheduled campaigns to Draft and pause live automations.
- **Depends on**: plan 048 (run it first; both change automation approval)
- **Category**: security / authorization
- **Planned at**: commit `6982215`, 2026-11-15

## Why this matters

With "require campaign approval" switched on, nothing goes out until someone with `campaigns.approve` approves it, and editing a campaign's content withdraws the approval. But a campaign aimed at a **segment** is resolved from the segment's **live definition** at send time, and editing the segment touches no approval.

The default **Editor** role has `segments.manage` and `campaigns.send`, but not `campaigns.approve`. So an approver approves a campaign aimed at a 40-contact segment, the Editor widens the segment to everyone, and the Editor (or the scheduler) sends it to everyone. The same applies to an approved "enters a segment" automation, whose fingerprint covers the trigger's `segment_id` but not the segment's definition.

This plan withdraws those approvals when a non-approver changes a segment's definition, and adds a send-time backstop.

## Current state

**`src/Http/Controllers/SegmentController.php:61-68`**:

```php
public function update(Request $request, Segment $segment, CurrentWorkspace $currentWorkspace): RedirectResponse
{
    $segment->update($this->validated($request, $currentWorkspace, $segment));

    Inertia::flash('toast', ['type' => 'success', 'message' => __('Segment saved.')]);

    return to_route('marketing.segments.edit', $segment);
}
```

The route sits in `Route::middleware('can:segments.manage')` (`routes/web.php:69-75`). The controller extends `Controller`, which has `$this->user($request)` returning a `MarketingUser` (`src/Http/Controllers/Controller.php:14`). Segment definitions can't reference other segments (no `segment` field in `src/Segments/SegmentFieldCatalog.php`), so only direct users of a segment matter.

**Campaigns use a segment** when `audience_type === AudienceType::Segment` and `segment_id` is the segment (`src/Campaigns/CampaignAudience.php:32`):

```php
AudienceType::Segment => $campaign->segment === null
    ? $query->whereRaw('1 = 0')
    : $this->segmentQuery->apply($query, $campaign->segment->definition),
```

**Campaign approval**: columns `approved_at` and `approved_by`. `CampaignController::update()` withdraws them when `CONTENT_FIELDS` change (line 153); `segment_id` is among them, but the segment's own definition isn't. The checklist item (`src/Campaigns/CampaignChecklist.php:54`) blocks scheduling when the workspace requires approval and `approved_at` is null. `CampaignController::unschedule()` (line 310) returns a scheduled campaign to Draft with:

```php
Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Scheduled)
    ->update(['status' => CampaignStatus::Draft, 'scheduled_at' => null]);
```

Copy that query shape. `Campaign::query()->broadcasts()` is a scope (`src/Models/Campaign.php:166`). Automation step campaigns (`kind = automation`) have no audience of their own; skip them.

**Automations use a segment** when `trigger_type === AutomationTrigger::SegmentEntered` and `trigger_config['segment_id']` is the segment's id (see `src/Automations/ActivationChecklist.php:90`, which reads it as `$value('segment_id')`). `AutomationController::update()` already shows how a non-approver's change is handled:

```php
} elseif ($changed && ! $canApprove && $automation->approved_at !== null) {
    $automation->fill(['approved_at' => null, 'approved_by' => null]);
}

if ($changed && ! $canApprove && $workspace->requires_campaign_approval && $automation->status === AutomationStatus::Active) {
    // Hold everyone where they are; the new emails are not published until an approver resumes it.
    $automation->status = AutomationStatus::Paused;
    $pausedForApproval = true;
}

$automation->save();
$emails->handle($automation);
```

`$emails` is `SyncAutomationEmails`; calling `handle()` after a status change moves its step campaigns to Draft. Mirror this for each affected automation.

**`src/Jobs/PrepareCampaign.php::handle()`** claims a due campaign with:

```php
$claimed = Campaign::query()
    ->whereKey($campaign->id)
    ->where('status', CampaignStatus::Scheduled)
    ->where('scheduled_at', '<=', now())
    ->update(['status' => CampaignStatus::Sending, 'sending_started_at' => now()]) === 1;
```

It runs inside `$currentWorkspace->runAs(Workspace::findOrFail($this->workspaceId), function () use ($audience): void { ... })`.

**`CampaignController::approve()`** (line ~270):

```php
public function approve(Request $request, Campaign $campaign): RedirectResponse
{
    $this->ensureEditable($campaign);

    $campaign->update(['approved_at' => now(), 'approved_by' => $this->user($request)->getAuthIdentifier()]);
    ...
```

The frontend posts to `CampaignController.approve.url(campaign.id)` around `resources/js/pages/marketing/campaigns/edit.tsx:1758`, inside the approval panel (lines ~1746-1772). The campaign prop has `approved_at`; check whether it has `updated_at` (`grep -n "updated_at" resources/js/pages/marketing/campaigns/edit.tsx`).

**Tests**: `tests/Feature/SendingTest.php:131` "approval is required when the workspace asks for it, and edits withdraw it" is the pattern for campaigns; `tests/Feature/AutomationApprovalTest.php` for automations (its `approval...` helpers are global Pest functions; don't redeclare them). `$this->addMember($this->workspace, 'Editor')` makes an Editor. Segment definitions in tests: copy one from `tests/Feature/SegmentTest.php`.

## Commands

From `packages/core` (PowerShell: `php`/`composer`; Git Bash: `php.bat`/`composer.bat`), unless they say "root".

| Purpose         | Command                                                 | Expected                                      |
| --------------- | ------------------------------------------------------- | --------------------------------------------- |
| Core tests      | `vendor/bin/pest`                                       | all pass, except the 7 Windows-only SES tests |
| One file        | `vendor/bin/pest tests/Feature/SegmentApprovalTest.php` | all pass                                      |
| PHPStan (root)  | `vendor/bin/phpstan analyse --memory-limit=1G`          | 0 errors                                      |
| Pint (root)     | `vendor/bin/pint --test packages/core`                  | passes                                        |
| Frontend (root) | `npm run check` and `npm run types:check`               | pass                                          |

## Scope

**In scope:**

- `src/Segments/WithdrawSegmentApprovals.php` (new action class)
- `src/Http/Controllers/SegmentController.php` (`update()` only)
- `src/Http/Controllers/CampaignController.php` (`approve()` only)
- `src/Jobs/PrepareCampaign.php` (the backstop only)
- `resources/js/pages/marketing/campaigns/edit.tsx` (the approve call only)
- `resources/js/pages/marketing/segments/form.tsx` (one warning line, optional; see Step 4)
- `tests/Feature/SegmentApprovalTest.php` (new)

**Out of scope:**

- Deleting a segment. A campaign whose segment is gone sends to nobody (`whereRaw('1 = 0')`), and the trigger checklist refuses a missing segment.
- Segment renames. Only the `definition` matters.
- Campaigns that are already `Sending`. Their recipients were fixed when preparation started.
- Approval being switched on after things were approved or scheduled. Treated as policy going forward (round 6, rejected finding).

## Git workflow

- Branch off `dev`: `advisor/049-segment-edits-withdraw-approval`.
- Commits like `fix(core): editing a segment withdraws the approvals that use it`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: The action

Create `src/Segments/WithdrawSegmentApprovals.php`:

```php
/**
 * A segment decides who an approved campaign or "enters a segment" automation reaches, so changing its definition
 * withdraws those approvals unless the person changing it can approve. Scheduled campaigns go back to Draft and live
 * automations pause, in workspaces that require approval.
 */
class WithdrawSegmentApprovals
{
    public function __construct(private readonly SyncAutomationEmails $emails) {}

    /**
     * @return array{campaigns: int, automations: int} How many were affected, for the notice.
     */
    public function handle(Segment $segment, Workspace $workspace): array
```

Inside one `DB::transaction`:

1. **Campaigns**: `Campaign::query()->broadcasts()->where('audience_type', AudienceType::Segment)->where('segment_id', $segment->id)->whereIn('status', [CampaignStatus::Draft, CampaignStatus::Scheduled])->whereNotNull('approved_at')`. For each:
    - set `approved_at` and `approved_by` to null;
    - when `$workspace->requires_campaign_approval` and its status is `Scheduled`, also set `status` to `Draft` and `scheduled_at` to null. Use a guarded update (`->where('status', CampaignStatus::Scheduled)`) like `unschedule()`, so one that has just started sending isn't touched.
2. **Automations**: `Automation::query()->where('trigger_type', AutomationTrigger::SegmentEntered)->whereNotNull('approved_at')->get()`, then filter in PHP to `(int) ($automation->trigger_config['segment_id'] ?? 0) === $segment->id` (JSON queries differ between SQLite and MySQL; filtering in PHP avoids that). For each:
    - set `approved_at` and `approved_by` to null;
    - when the workspace requires approval and it's `AutomationStatus::Active`, set `status` to `Paused`;
    - `save()`, then `$this->emails->handle($automation)`, in that order (same as `AutomationController::update()`).

Return the counts of campaigns and automations whose approval was withdrawn.

Check every class name against its real namespace before using it (`grep -rn "^namespace\|^enum\|^class" src/Enums/AudienceType.php src/Enums/CampaignStatus.php src/Enums/AutomationStatus.php src/Enums/AutomationTrigger.php src/Automations/SyncAutomationEmails.php`).

**Verify**: PHPStan (root) → 0 errors.

### Step 2: Call it from `SegmentController::update()`

```php
public function update(Request $request, Segment $segment, CurrentWorkspace $currentWorkspace, WithdrawSegmentApprovals $withdraw): RedirectResponse
{
    $validated = $this->validated($request, $currentWorkspace, $segment);
    $workspace = $currentWorkspace->getOrFail();
    $canApprove = $this->user($request)->hasWorkspacePermission($workspace, Permission::CampaignsApprove);

    $segment->fill($validated);
    $definitionChanged = $segment->isDirty('definition');
    $segment->save();

    $withdrawn = $definitionChanged && ! $canApprove ? $withdraw->handle($segment, $workspace) : ['campaigns' => 0, 'automations' => 0];

    Inertia::flash('toast', ...);
```

- An approver's own edit keeps approvals, matching `AutomationController`.
- `isDirty('definition')` on a JSON-cast attribute compares the encoded value. Confirm it reports **false** when the same definition is re-saved (Step 5, test 4). If it reports true for an identical save, compare `json_encode` of the old and new arrays after sorting keys with the same approach as `AutomationApproval::sorted()` (make a small private helper here; don't make `sorted()` public).
- Toast: when anything was withdrawn, `['type' => 'warning', 'message' => __('Segment saved. Approval was withdrawn from :count campaigns or automations that use it.', ['count' => $withdrawn['campaigns'] + $withdrawn['automations']])]`. Otherwise keep `'Segment saved.'`. Don't use `trans_choice` (the `|` character in messages caused a bug in plan 036).

**Verify**: `vendor/bin/pest tests/Feature/SegmentTest.php` → all pass.

### Step 3: The send-time backstop

In `PrepareCampaign::handle()`, inside the `runAs` closure and **before** the claim, add:

```php
// A backstop for anything that withdrew approval after scheduling: such a campaign goes back to Draft unsent.
if ($workspace->requires_campaign_approval && $campaign->approved_at === null && $campaign->status === CampaignStatus::Scheduled) {
    Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Scheduled)
        ->update(['status' => CampaignStatus::Draft, 'scheduled_at' => null]);

    return;
}
```

You need the workspace inside the closure. Assign `$workspace = Workspace::findOrFail($this->workspaceId)` before `runAs` and pass it in with `use`.

A retry of a campaign that's already `Sending` is unaffected (the check requires `Scheduled`).

**Verify**: `vendor/bin/pest tests/Feature/SendingTest.php tests/Feature/ScheduleTest.php tests/Feature/RecoveryTest.php` → all pass.

### Step 4: Approve what the approver saw

1. `CampaignController::approve()`: validate `'version' => ['nullable', 'string']`. When it's given and differs from `$campaign->updated_at?->toIso8601String()`, throw `ValidationException::withMessages(['campaign' => __('This campaign changed after you opened it. Reload it and review it again.')])`. Without `version`, behave as today (so other callers keep working).
2. Make sure the campaign prop in `CampaignController::edit()` includes `updated_at` as an ISO string. If it doesn't, add `'updated_at' => $campaign->updated_at?->toIso8601String()` to that array (that's within `edit()`; allowed).
3. In `campaigns/edit.tsx`, send `{ version: campaign.updated_at }` with the approve post, and add `updated_at: string | null` to the campaign type if it's missing.

**Optional, only if it's one line**: in `segments/form.tsx`, under the save button, show "Saving changes to who matches withdraws approval from campaigns and automations that use this segment." only when `!can('campaigns.approve')`. Per the project's UI rule, explanations go in tooltips, not notes under fields; if the form has a tooltip pattern next to the save button, use that instead. If neither fits in a few lines, skip it and say so.

**Verify** (root): `npm run check` and `npm run types:check` → pass.

### Step 5: Tests

Create `tests/Feature/SegmentApprovalTest.php`. Set up an owner, an approval-required workspace with a postal address, a provider (copy from `AutomationApprovalTest.php`'s `beforeEach`), a segment, and an Editor. Prefix any helper functions with `segmentApproval` so they don't collide with other files' global helpers.

1. **An Editor's segment edit withdraws a draft's approval.** An approved draft campaign aimed at the segment. The Editor changes the definition. `approved_at` is null.
2. **…and returns a scheduled campaign to Draft.** Same, but scheduled for an hour ahead. Status is `draft`, `scheduled_at` is null. Then travel 2 hours and run `(new DispatchDueCampaigns)()` (see `SendingTest.php`). Nothing was sent (`$this->transports->sent` is empty).
3. **An approver's edit keeps approval.** The owner changes the definition. `approved_at` is unchanged and the campaign stays `scheduled`.
4. **Re-saving the same definition changes nothing.** The Editor saves the segment with only its name changed. Approval stays.
5. **Campaigns aimed at other segments, lists or everyone are untouched.**
6. **A live "enters a segment" automation pauses.** Create an approved, active automation with `trigger_type` `segment_entered` and `trigger_config` `['segment_id' => $segment->id]` (approve as owner and activate, as `approvalLive` does; or create it with `approved_at`, `approved_by` and `status` set directly). The Editor edits the segment. The automation is `paused` and unapproved.
7. **Without the workspace setting**, a scheduled campaign stays `scheduled` after an Editor's edit (approval may be withdrawn; assert only the status).
8. **Backstop.** A campaign scheduled in the past with `approved_at` null, in an approval workspace (set the columns directly). Run `PrepareCampaign::dispatchFor($campaign)` (sync queue in tests). Status is `draft`, and nothing was sent.
9. **Stale approval is refused.** The owner posts approve with a `version` that isn't the campaign's `updated_at` → `assertSessionHasErrors('campaign')` and `approved_at` stays null. With the right `version`, it's approved.

**Revert checks** (restore after each, commit nothing from them):

- Remove the `$withdraw->handle(...)` call: tests 1, 2 and 6 fail.
- Remove the backstop: test 8 fails.
- Remove the version check: test 9 fails.

**Verify**: `vendor/bin/pest` → all pass (apart from the 7 SES failures); PHPStan → 0 errors; Pint → passes; `npm run check` and `npm run types:check` → pass.

## Done criteria

- [ ] A non-approver's change to a segment's definition withdraws approval from draft and scheduled broadcasts aimed at it, and in approval workspaces returns scheduled ones to Draft
- [ ] It also withdraws approval from "enters a segment" automations using it, and pauses live ones in approval workspaces
- [ ] An approver's change, and a save without a definition change, keep approvals
- [ ] `PrepareCampaign` refuses an unapproved campaign in an approval workspace and returns it to Draft
- [ ] Approve refuses a stale `version`
- [ ] All 9 tests pass and the revert checks fail as described
- [ ] Pest, PHPStan, Pint, `npm run check` and `types:check` pass
- [ ] `plans/README.md` row updated

## STOP conditions

- The drift check shows changes the excerpts don't cover.
- Segment definitions turn out to be able to reference other segments (a `segment` field type appears in `SegmentFieldCatalog`). Then indirect users must be found too, and the plan needs another pass.
- Automation condition steps can reference a saved segment by id (`grep -rn "segment_id" src/Automations`). Then those automations depend on the segment too.
- `isDirty('definition')` can't tell an identical save from a change, even with the sorted comparison.
- The backstop breaks an existing test in `SendingTest`, `ScheduleTest` or `RecoveryTest`.

## Maintenance notes

- **Release note:** in approval workspaces, an Editor changing a segment sends campaigns and automations that use it back for approval.
- Anything else that later decides an audience by reference (for example "in list X" conditions on approved content, or a segment inside a segment) needs the same treatment.
- Reviewer focus: the guarded `Scheduled → Draft` update (never touches a campaign that has started sending), and that automations are saved before `SyncAutomationEmails::handle()`.
