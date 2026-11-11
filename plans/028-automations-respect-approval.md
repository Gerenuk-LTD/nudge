# Plan 028: Automations respect "require campaign approval"

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say otherwise. If anything in "STOP conditions" happens, stop
> and report. Don't improvise. When done, update this plan's row in
> `plans/README.md`, unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 727d2b1..HEAD -- packages/core/src/Http/Controllers/AutomationController.php packages/core/src/Automations/ActivationChecklist.php packages/core/src/Automations/SyncAutomationEmails.php packages/core/src/Actions/Automations/ActivateAutomation.php packages/core/src/Models/Automation.php packages/core/routes/web.php packages/core/resources/js/pages/marketing/automations/edit.tsx`
> If any of these files changed, compare them with the "Current state"
> excerpts. On a mismatch, STOP. Changes to `routes/web.php` elsewhere in
> the file are fine.

## Status

- **Priority**: P1. This closes a gap in a control the workspace explicitly switched on.
- **Effort**: S–M
- **Risk**: MEDIUM. It changes the live-edit workflow for automations in workspaces that require approval.
- **Depends on**: none
- **Category**: security / authorization (product rule)
- **Planned at**: commit `727d2b1` (branch `dev`), 2026-11-11

## Why this matters

A workspace can switch on "require campaign approval"
(`workspaces.requires_campaign_approval`). Broadcasts then can't be scheduled
or sent until someone with `campaigns.approve` approves them, and editing their
content withdraws that approval (`src/Http/Controllers/CampaignController.php:41`,
`:151-152`; `src/Campaigns/CampaignChecklist.php:41`).

Automations ignore the setting. The default **Editor** role has
`automations.manage` but not `campaigns.approve`
(`src/Actions/Workspaces/CreateWorkspace.php:70-80`). So an Editor can build
an automation, start it, or edit the emails of a live one, and those emails go
to real contacts without anyone approving them. The approval control can be
bypassed just by using an automation instead of a broadcast.

**Decided by the maintainer (2026-11-11):**
- Automations need approval to go live whenever the workspace requires campaign approval. The permission is the same, `campaigns.approve`.
- When someone **without** `campaigns.approve` changes what a live automation sends, the approval is withdrawn and the automation is **paused**. Contacts are held where they are until an approver approves it and resumes it.
- When someone **with** `campaigns.approve` makes the change, it counts as approved. The automation stays live, and the approval is re-stamped with that person.

## Current state

**`src/Http/Controllers/AutomationController.php`**:

- `update()` validates, then in one transaction it updates the automation and calls `SyncAutomationEmails::handle()`:
  ```php
  DB::transaction(function () use ($automation, $validated, $emails): void {
      $automation->update([
          'name' => $validated['name'],
          'trigger_type' => $validated['trigger_type'],
          'trigger_config' => $validated['trigger_config'],
          'allow_reentry' => $validated['allow_reentry'],
          'definition' => ['steps' => $validated['definition']['steps'] ?? []],
      ]);

      $emails->handle($automation);
  });

  return back();
  ```
- `activate()` calls `ActivateAutomation::handle($automation, $workspace)` and flashes "Automation is live.".
- `pause()` sets `status` to `Paused` when it's `Active`.
- `edit()` renders `marketing/automations/edit` with `automation`, `problems` (from `ActivationChecklist::problems`) and more. It doesn't pass approval information.

**`src/Actions/Automations/ActivateAutomation.php`**: throws a `ValidationException` (`['automation' => $problems]`) when `ActivationChecklist::problems()` returns anything. Otherwise it sets `status = Active` and syncs the emails.

**`src/Automations/ActivationChecklist.php`**: `problems(Automation, Workspace): list<string>` collects plain-language problems (steps, trigger, postal address, email fields, provider, conditions, webhooks). It doesn't check approval.

**`src/Automations/SyncAutomationEmails.php`**: each `send_email` step is backed by a hidden `Campaign`. When `$automation->status === Active`, the campaign is `Sending` and `publish()` replaces its `CampaignSnapshot`, which is what `StepRunner` sends from. When the automation isn't active, the campaigns are `Draft` and nothing is published.

**Pausing** holds runs where they are: "Pausing stops new contacts entering and holds everyone where they are until it is resumed" (docblock on `pause()`). Resuming goes through `activate()`.

**`src/Models/Automation.php`**: `#[Fillable(['workspace_id', 'name', 'status', 'trigger_type', 'trigger_config', 'definition', 'allow_reentry', 'activated_at'])]`, with casts on line ~50 and a `@property` docblock.

**Routes**, in `routes/web.php:132-137`:
```php
Route::middleware('can:automations.manage')->group(function () {
    Route::post('automations', [AutomationController::class, 'store'])->name('automations.store');
    Route::put('automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
    ...
    Route::post('automations/{automation}/activate', [AutomationController::class, 'activate'])->name('automations.activate');
    Route::post('automations/{automation}/pause', [AutomationController::class, 'pause'])->name('automations.pause');
```
The campaign approve route, the pattern to copy, is at `routes/web.php:119`:
```php
Route::post('campaigns/{campaign}/approve', [CampaignController::class, 'approve'])->middleware('can:campaigns.approve')->name('campaigns.approve');
```

**Campaign approval, the pattern to mirror**, in `CampaignController.php`:
```php
public function approve(Request $request, Campaign $campaign): RedirectResponse
{
    $this->ensureEditable($campaign);

    $campaign->update(['approved_at' => now(), 'approved_by' => $this->user($request)->getAuthIdentifier()]);

    Inertia::flash('toast', ['type' => 'success', 'message' => __('Campaign approved.')]);

    return back();
}
```
The columns are `$table->timestamp('approved_at')->nullable()` and `$table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()` (`database/migrations/2026_10_29_000001_create_sending_tables.php:58,74`). The permission check in a controller is `$this->user($request)->hasWorkspacePermission($workspace, Permission::CampaignsApprove)` (see `SendingSettingsController.php:28`).

**Frontend**, `resources/js/pages/marketing/automations/edit.tsx` (618 lines):
- The props include `problems: string[]` (line ~59).
- `activate` (line ~139) saves, then posts to `AutomationController.activate.url(automation.id)`.
- The header buttons (lines ~217-243): Pause when active, otherwise Resume / Start automation.
- A live-edit warning (line ~248): `{automation.status === 'active' && dirty && (<p className="text-sm text-amber-700 dark:text-amber-400">This automation is live. Saved changes apply to contacts already in it; ...</p>)}`.
- A "Before it can start" problems box (line ~256), shown when it isn't active.

The campaign page's approval panel to copy is at `resources/js/pages/marketing/campaigns/edit.tsx:1635-1667`. It shows "Approved <date>. Editing the content withdraws the approval." when approved, an outline "Approve campaign" button when `canApprove`, and otherwise "Someone with permission to approve campaigns needs to approve this one.". Permissions come from `const can = useCan();` (`import { useCan } from '@marketing/hooks/use-workspace';`), and `can('campaigns.approve')`. The route helpers are generated by Wayfinder into the gitignored `resources/js/actions`, so `AutomationController.approve` appears once the Vite or Wayfinder generation runs.

**Tests**:
- `tests/Feature/AutomationTest.php` has helpers `emailStep($id, $subject, $providerId)` and `liveAutomation($test, $trigger, $triggerConfig, $steps, $reentry)`. Its `beforeEach` creates `$this->owner`, `$this->workspace` (with a postal address) and `$this->provider`.
- `tests/Feature/SendingTest.php:131` "approval is required when the workspace asks for it, and edits withdraw it" is the campaign equivalent.
- `$this->addMember($this->workspace, 'Editor')` (`tests/TestCase.php:64`) creates an Editor.

## Commands

From `packages/core` (PowerShell: `php`/`composer`; Git Bash: `php.bat`/`composer.bat`), unless they say "root".

| Purpose | Command | Expected |
|---|---|---|
| Core tests | `vendor/bin/pest` | all pass, except the 7 "Amazon SES through SNS" tests that fail on Windows (OpenSSL config) |
| One file | `vendor/bin/pest tests/Feature/AutomationApprovalTest.php` | all pass |
| PHPStan (root) | `vendor/bin/phpstan analyse --memory-limit=1G` | 0 errors |
| Pint (root) | `vendor/bin/pint --test packages/core` | passes |
| Frontend (root) | `npm run check` and `npm run types:check` | pass |

## Scope

**In scope:**
- New migration `database/migrations/2026_11_11_000002_add_approval_to_automations_table.php`
- `src/Models/Automation.php`
- `src/Automations/ActivationChecklist.php`
- `src/Automations/AutomationApproval.php` (new, a small helper class)
- `src/Http/Controllers/AutomationController.php`
- `routes/web.php` (one new route)
- `resources/js/pages/marketing/automations/edit.tsx`
- `tests/Feature/AutomationApprovalTest.php` (new)

**Out of scope:**
- `SyncAutomationEmails` and `ActivateAutomation`. Their behaviour is already right once the status is `Paused` or the checklist blocks.
- `CampaignChecklist`, campaign approval and the sending-settings page copy. "Campaigns now need approval" also covers automations now, but changing the wording isn't needed here.
- Retroactively pausing automations that are already live. They keep running until they're next edited or paused (see Maintenance notes).
- Making webhook-step or condition-step edits withdraw approval. Only what is sent and who it's sent to count (Step 2).

## Git workflow

- Branch off `dev`: `advisor/028-automation-approval`.
- Stage only your files, by path. Don't touch the maintainer's uncommitted work in `plans/`.
- Commits like `feat: automations need approval when the workspace requires it`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Columns

Create `database/migrations/2026_11_11_000002_add_approval_to_automations_table.php`, adding to `automations`:
- `$table->timestamp('approved_at')->nullable();`
- `$table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();`

`down()` drops the foreign key (`dropConstrainedForeignId('approved_by')`), then `approved_at`.

In `Automation`: add both to `#[Fillable]`, cast `approved_at` as `datetime`, and add `@property CarbonInterface|null $approved_at` and `@property int|null $approved_by`.

**Verify**: `vendor/bin/pest tests/Feature/AutomationTest.php` → all pass.

### Step 2: What counts as a change that needs approval

Create `src/Automations/AutomationApproval.php`:

```php
/**
 * What an approver signs off on for an automation: who it reaches (the trigger) and what it sends
 * (each email step). Delays, conditions, webhooks and the name don't change what recipients see.
 */
class AutomationApproval
{
    /** Email step settings that change what recipients see. */
    private const EMAIL_FIELDS = ['subject', 'preheader', 'from_name', 'from_email', 'reply_to', 'html', 'provider_id'];

    public static function fingerprint(Automation $automation): string
    { ... }
}
```

`fingerprint()` returns `hash('sha256', json_encode([...]))` over:
- `trigger_type->value`;
- `trigger_config`, with keys sorted recursively (`ksort`), so the same config in a different key order hashes the same;
- the list of `send_email` steps from `$automation->steps()->ofType(StepType::SendEmail)`, each reduced to `['id' => (string) $step['id'], ...only EMAIL_FIELDS from $step['config']]`, with missing fields as `null` and the list sorted by `id`.

Adding or removing an email step changes the fingerprint. So does changing any of those fields or the trigger. Moving steps around or editing a delay doesn't.

`AutomationDefinition::ofType()` already walks branches; check it in `src/Automations/AutomationDefinition.php`. If it doesn't include email steps inside condition branches, STOP and report.

**Verify**: write tests 6 and 7 from Step 5 first (they're pure unit-style checks on `fingerprint`), then `vendor/bin/pest tests/Feature/AutomationApprovalTest.php` → pass.

### Step 3: Server rules

1. **`ActivationChecklist::problems()`**: after the existing checks, add:
   ```php
   if ($workspace->requires_campaign_approval && $automation->approved_at === null) {
       $problems[] = __('This workspace requires automations to be approved before they go live.');
   }
   ```
   `ActivateAutomation` already refuses to start with any problem, so this covers both "Start automation" and "Resume".

2. **`AutomationController::approve(Request $request, Automation $automation)`**: sets `approved_at = now()` and `approved_by = $this->user($request)->getAuthIdentifier()`, flashes `__('Automation approved.')`, and returns `back()`. Route, inside the `can:automations.manage` group, next to `activate`:
   ```php
   Route::post('automations/{automation}/approve', [AutomationController::class, 'approve'])->middleware('can:campaigns.approve')->name('automations.approve');
   ```
   Check that a route inside a group can add its own `->middleware('can:...')`, which it can in Laravel. Then the approver needs both `automations.manage` and `campaigns.approve`.

3. **`AutomationController::update()`**: `$request` is already an `AutomationRequest`, which extends `Request`, so `$this->user($request)` works without injecting anything else. Replace the `$automation->update([...])` call inside the existing transaction with:
   ```php
   $before = AutomationApproval::fingerprint($automation);

   $automation->fill([...same fields as today...]);

   $changed = AutomationApproval::fingerprint($automation) !== $before;
   $canApprove = $this->user($request)->hasWorkspacePermission($workspace, Permission::CampaignsApprove);
   $pausedForApproval = false;

   if ($changed && $canApprove && $automation->approved_at !== null) {
       // An approver's own edit stays approved, signed by them.
       $automation->fill(['approved_at' => now(), 'approved_by' => $this->user($request)->getAuthIdentifier()]);
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
   Set the status **before** `$emails->handle()`. `SyncAutomationEmails` then marks the step campaigns `Draft` and doesn't publish the unapproved content.

   Note on the first branch: an approver's edit to an automation that was never approved (for example one already live before this release) doesn't approve it silently. They can click Approve. Keep it that way.

   After the transaction, when `$pausedForApproval`, flash `['type' => 'warning', 'message' => __('Saved. The automation is paused until someone approves the changes.')]`. Check that `'warning'` is a toast type the frontend handles (`grep -rn "'warning'\|\"warning\"" resources/js`). If it isn't, use `'success'`.

   `$workspace` is `$this->currentWorkspace->getOrFail()`.

4. **`AutomationController::edit()`**: add these props:
   - `'requiresApproval' => $workspace->requires_campaign_approval`;
   - `'approved_at' => $automation->approved_at?->toIso8601String()` inside the `automation` array.

**Verify**: `vendor/bin/pest` → all pass. Some existing `AutomationTest` tests may now fail if they turn approval on; none are expected to. If one does, STOP and report it rather than editing that test.

### Step 4: Frontend

In `resources/js/pages/marketing/automations/edit.tsx`:

1. Add `approved_at: string | null` to the automation prop type, and `requiresApproval: boolean` to the page props.
2. Add `const can = useCan();` (import from `@marketing/hooks/use-workspace`, as in `campaigns/edit.tsx:58`), and `const canApprove = can('campaigns.approve');`.
3. Add an approval panel when `props.requiresApproval`, styled like `campaigns/edit.tsx:1635-1667` (`rounded-xl border bg-card p-4 shadow-xs`, `h2` "Approval"):
   - if approved: "Approved <formatted date>. Changing its emails or trigger withdraws the approval." Use the same date formatter the campaign page uses, if one is importable; otherwise `new Date(...).toLocaleString()`.
   - else if `canApprove`: an outline button "Approve automation" that posts to `AutomationController.approve.url(automation.id)` with `{ preserveScroll: true }`. It's disabled while `saving` or `dirty`, so approvers approve what's saved, not unsaved edits. Add the helper text "Save your changes first." when it's dirty.
   - else: "Someone with permission to approve campaigns needs to approve this automation before it goes live."

   Put it next to the "Before it can start" box.
4. Live-edit warning: when `automation.status === 'active' && dirty && props.requiresApproval && !canApprove`, replace the existing text with "This automation is live. Saving changes to its emails or trigger pauses it until someone approves them." Otherwise keep the existing text.

The checklist problem from Step 3.1 already shows in the "Before it can start" box, so nothing else is needed there.

**Verify** (root): `npm run check` and `npm run types:check` → pass. If `AutomationController.approve` is missing from the generated actions, run the Wayfinder generation the repo uses (`php artisan wayfinder:generate` at the root, if that command exists) and re-run. Don't commit the generated files; they're gitignored.

### Step 5: Tests

Create `tests/Feature/AutomationApprovalTest.php`. Copy `AutomationTest.php`'s `beforeEach` (owner, workspace with postal address, capturing transports, provider). The helper functions in `AutomationTest.php` are global Pest functions, so **don't redeclare** `emailStep`/`liveAutomation`. Give any new helpers unique names (prefix `approval...`), and confirm the full suite still runs without "Cannot redeclare".

Set `requires_campaign_approval = true` on the workspace unless a test says otherwise.

1. **An unapproved automation can't start.** A complete draft automation with an email step. An Editor (`addMember(..., 'Editor')`) posts `automations.activate` and gets `assertSessionHasErrors('automation')`; the status stays `draft`.
2. **Approval lets it start.** The owner posts `automations.approve`, then an Editor activates it. The status is `active` and `approved_by` is the owner's id.
3. **Editors can't approve.** An Editor posting `automations.approve` gets 403, and `approved_at` stays null.
4. **An Editor's email edit pauses a live, approved automation.**
   - Approve and activate it.
   - As an Editor, `put automations.update` with the email subject changed (send the full payload the request validates; copy the shape from an existing update test in `AutomationTest.php`).
   - Expect: `status` is `paused`, `approved_at` is null, and the step's campaign (`Campaign::where('automation_id', ...)`) is `Draft` with its snapshot still holding the **old** subject.
   - Then the owner approves and activates. The status is `active`, and the snapshot has the new subject.
5. **An approver's edit stays live.** Same as 4, but the owner edits. The status stays `active`, `approved_at` is refreshed, and the snapshot has the new subject.
6. **Fingerprint ignores what isn't sent.** Changing the name, adding a delay step, or changing a webhook URL keeps `fingerprint()` the same. An Editor's save with only those changes leaves a live automation `active` and approved.
7. **Fingerprint catches what is sent.** Changing an email subject, from address, html or provider, adding an email step, or changing the trigger config (for example another list id) each changes `fingerprint()`. Key order in `trigger_config` doesn't.
8. **Without the setting, nothing changes.** With `requires_campaign_approval = false`, an Editor can activate without approval, and an Editor's email edit leaves it `active`. Approval being withdrawn is fine here; assert only the status.

Revert checks (restore after each, commit nothing from them):
- Remove the checklist problem from Step 3.1: tests 1 and 2's "can't start" part fail.
- Remove the `Paused` branch from Step 3.3: test 4 fails.
- Return a constant from `fingerprint()`: tests 4 and 7 fail.

**Verify**: `vendor/bin/pest` → all pass (apart from the 7 known SES failures); PHPStan → 0 errors; Pint → passes; `npm run check` and `npm run types:check` → pass.

## Done criteria

- [ ] `automations.approved_at` / `approved_by` exist (migration runs on SQLite; MySQL is checked by CI)
- [ ] With approval required, an unapproved automation can't start or resume
- [ ] `automations.approve` needs `campaigns.approve` (Editors get 403)
- [ ] A non-approver's change to emails or trigger on a live automation withdraws approval and pauses it, and the old snapshot keeps being the published one
- [ ] An approver's change stays live and re-stamps the approval
- [ ] Edits that don't change what's sent, or to whom, don't affect approval
- [ ] Without the workspace setting, activation and live edits behave as before
- [ ] The edit page shows the approval panel and the right live-edit warning
- [ ] All 8 tests pass, and the revert checks fail as described
- [ ] Pest, PHPStan, Pint, `npm run check` and `types:check` pass

## STOP conditions

- The drift check shows changes to the in-scope files that the excerpts don't cover.
- `AutomationDefinition::ofType()` doesn't return email steps nested in condition branches. Then the fingerprint would miss them, and the plan needs a different walk.
- Pausing a live automation in `update()` breaks an existing `AutomationTest` test, which would mean some flow relies on edits never changing status.
- The Editor role in the test setup turns out to have `campaigns.approve` (check `CreateWorkspace::defaultRoles()`).

## Maintenance notes

- **Release note:** in workspaces that require approval, automations now need approval to start or resume. Automations that are **already live** keep running until they're next paused, or until a non-approver changes their emails or trigger (that pauses them, as designed). If the maintainer wants existing live automations re-reviewed straight away, that's a one-off: pause them, or set `approved_at` with a data migration. It's deliberately not done here.
- Switching approval on later doesn't pause anything that's already live. Same note.
- If more step types start sending to recipients (SMS, for example), add their visible fields to `AutomationApproval::fingerprint()`.
- **Reviewer focus:** in `update()`, the status changes **before** `SyncAutomationEmails::handle()`, and the unapproved content is never published (test 4's old-snapshot assertion).
- **Run `php artisan migrate`** after merging.
