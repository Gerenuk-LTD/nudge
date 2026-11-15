# Plan 048: Automation approval covers the whole flow; webhook and update-contact steps need contact permissions

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say otherwise. If anything in "STOP conditions" happens, stop
> and report. Don't improvise. When done, update this plan's row in
> `plans/README.md`, unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 6982215..HEAD -- packages/core/src/Automations/AutomationApproval.php packages/core/src/Automations/ActivationChecklist.php packages/core/src/Http/Controllers/AutomationController.php packages/core/src/Actions/Automations/ActivateAutomation.php packages/core/tests/Feature/AutomationApprovalTest.php`
> If any of these changed, compare them with the "Current state" excerpts.
> On a mismatch, STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: MED. More edits now pause live automations in workspaces that require approval.
- **Depends on**: none. Plan 049 builds on this plan's fingerprint, so run this one first.
- **Category**: security / authorization
- **Planned at**: commit `6982215`, 2026-11-15

## Why this matters

When a workspace requires approval, an approved, live automation is paused if a non-approver changes "what it sends or who it reaches". That rule is enforced by `AutomationApproval::fingerprint()`, which today hashes only the trigger and each email step's fields. So a non-approver (the default **Editor** role has `automations.manage` but not `campaigns.approve`) can, without losing approval:

- remove a condition, so an email meant for a few people goes to everyone;
- move an email into another branch;
- turn on `allow_reentry`, so contacts get the emails again;
- add a **webhook** step. `StepRunner::callWebhook()` posts each enrolled contact's email, names and custom fields to any URL. With a "segment entered" trigger that includes existing members, that sends the whole contact base to the attacker's server.

Separately, a webhook step exports contact data and an update-contact step changes contacts, but saving either needs only `automations.manage`. A custom role without `contacts.view` can read contacts through a webhook. This plan makes those steps need `contacts.view` and `contacts.manage`.

## Current state

**`src/Automations/AutomationApproval.php`** (whole class, 60 lines):

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
    {
        $emails = [];

        foreach ($automation->steps()->ofType(StepType::SendEmail) as $step) {
            ...only EMAIL_FIELDS from $step['config'], sorted by id...
        }

        return hash('sha256', (string) json_encode([
            'trigger' => $automation->trigger_type->value,
            'config' => self::sorted($automation->trigger_config ?? []),
            'emails' => $emails,
        ]));
    }

    /** @param array<mixed> $value  @return array<mixed> */
    private static function sorted(array $value): array { ...recursive ksort of non-list arrays... }
}
```

**The definition's shape** (`src/Automations/AutomationDefinition.php` docblock): `{steps: [{id, type, config, yes?: [...], no?: [...]}]}`. Condition steps hold `yes` and `no` branches. Steps have no name or canvas position; the frontend type (`resources/js/types.ts:184`) is `{ id, type, config, yes?, no? }`. Step types (`src/Automations/StepType.php`): `send_email`, `wait`, `condition`, `update_contact`, `webhook`, `exit`.

**`src/Http/Controllers/AutomationController.php::update()`** computes `$before = AutomationApproval::fingerprint($automation)`, fills `name`, `trigger_type`, `trigger_config`, `allow_reentry` and `definition`, then compares fingerprints. A change by a non-approver withdraws approval and, in approval workspaces, pauses a live automation. An approver's change re-stamps the approval. **Don't change that logic**; only the fingerprint changes. `update()` already has `$user = $this->user($request)` and `$workspace = $this->currentWorkspace->getOrFail()`, and validates the steps with `$validator->validate($validated['definition'])` (`AutomationDefinitionValidator`) before the transaction.

**`activate()`**: `$activate->handle($automation, $this->currentWorkspace->getOrFail())`. `ActivateAutomation` throws a `ValidationException` (`['automation' => $problems]`) when `ActivationChecklist::problems()` returns anything.

**`src/Automations/StepRunner.php:148-158`**, `callWebhook()`, posts:

```php
'contact' => [
    'email' => $contact->email,
    'first_name' => $contact->first_name,
    'last_name' => $contact->last_name,
    'custom_fields' => (object) ($contact->custom_fields ?? []),
],
```

**Permissions**: `Permission::ContactsView` (`contacts.view`) and `Permission::ContactsManage` (`contacts.manage`) in `src/Enums/Permission.php`. Check with `$user->hasWorkspacePermission($workspace, Permission::ContactsView)`. The default Editor has both, so only custom roles are affected by Step 2.

**Tests**, `tests/Feature/AutomationApprovalTest.php`: `beforeEach` sets up an owner, an approval-required workspace, a provider, `$this->list` and `$this->editor`. Helpers: `approvalEmail()`, `approvalDraft()`, `approvalPayload()`, `approvalLive()`, `approvalSubjectChange()`. The test at line 144, **"changes that do not alter what is sent leave the fingerprint and approval alone"**, asserts that adding a wait and a webhook, reordering steps, and changing a webhook URL keep the fingerprint. **This plan deliberately changes that rule**, so that test is rewritten in Step 3. A custom role is created like `Role::create(['workspace_id' => $this->workspace->id, 'name' => 'X', 'permissions' => [...], 'is_owner' => false])` (`tests/Feature/HomeTest.php:59`), then `$this->addMember($this->workspace, 'X')`.

## Commands

From `packages/core` (PowerShell: `php`/`composer`; Git Bash: `php.bat`/`composer.bat`), unless they say "root".

| Purpose        | Command                                                    | Expected                                            |
| -------------- | ---------------------------------------------------------- | --------------------------------------------------- |
| Core tests     | `vendor/bin/pest`                                          | all pass, except the 7 Windows-only SES tests       |
| One file       | `vendor/bin/pest tests/Feature/AutomationApprovalTest.php` | all pass                                            |
| PHPStan (root) | `vendor/bin/phpstan analyse --memory-limit=1G`             | 0 errors                                            |
| Pint (root)    | `vendor/bin/pint --test packages/core`                     | passes                                              |

## Scope

**In scope:**

- `src/Automations/AutomationApproval.php`
- `src/Automations/ActivationChecklist.php` (Step 2's activation check only, if you put it there; see Step 2)
- `src/Http/Controllers/AutomationController.php` (`update()` and `activate()` only)
- `tests/Feature/AutomationApprovalTest.php`
- `tests/Feature/AutomationStepPermissionsTest.php` (new)

**Out of scope:**

- `StepRunner`. Its payload stays the same.
- The frontend. Errors come back as validation errors on the existing form; the page already shows `errors`.
- Re-checking permissions for automations that are already live (see Maintenance notes).
- Segment edits. That's plan 049.

## Git workflow

- Branch off `dev`: `advisor/048-automation-approval-whole-flow`.
- Commits like `fix(core): automation approval covers conditions, branches and webhooks`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Fingerprint the whole definition

Rewrite `fingerprint()` to hash:

- `trigger_type->value`;
- `trigger_config`, through `self::sorted()`;
- `allow_reentry` as a bool;
- the **whole** step tree, `$automation->definition['steps'] ?? []`, through `self::sorted()`. Keep step lists in their order (order is structure: what runs after what, and which branch). `sorted()` already leaves lists alone and sorts only keyed arrays, so each step's keys and config keys are order-independent.

Leave out only the automation's `name`. Steps have no names or positions to leave out; if you find a step key that is purely visual (grep the edit page for keys written into steps, e.g. `grep -n "config: {" resources/js/pages/marketing/automations/edit.tsx`), STOP and report it rather than guessing.

Drop `EMAIL_FIELDS` and the email loop. Update the class docblock:

```php
/**
 * What an approver signs off on for an automation: everything that decides who it reaches and what happens to them
 * (the trigger, re-entry, and every step in order, including conditions, branches, webhooks and contact updates).
 * Only the automation's name is left out.
 */
```

Remove the now-unused `StepType` import if PHPStan or Pint says so.

**Verify**: `vendor/bin/pest tests/Feature/AutomationApprovalTest.php` → only the test at line 144 fails (it encodes the old rule). Every other test passes.

### Step 2: Webhook and update-contact steps need contact permissions

Add a private method to `AutomationController`:

```php
/**
 * Webhook steps send contact data out, and update-contact steps change contacts, so they need the matching
 * contact permission on top of managing automations.
 *
 * @param  array<string, mixed>  $definition
 */
private function ensureCanUseSteps(array $definition, MarketingUser $user, Workspace $workspace): void
```

It builds the definition with `AutomationDefinition::from($definition)`, and throws `ValidationException::withMessages(['definition' => __('...')])`:

- when `ofType(StepType::Webhook)` isn't empty and the user lacks `Permission::ContactsView`: "Webhook steps send contact details, so they need permission to view contacts."
- when `ofType(StepType::UpdateContact)` isn't empty and the user lacks `Permission::ContactsManage`: "Update contact steps need permission to edit contacts."

Call it:

1. In `update()`, after `$validator->validate(...)` and before the transaction, with `$validated['definition']`.
2. In `activate()`, before `$activate->handle(...)`, with `$automation->definition` and `$this->user($request)`. `activate()` doesn't take a `Request` today; add `Request $request` as its first parameter (Laravel injects it).

Check the exact type names before writing: `MarketingUser` is `Gerenuk\Marketing\Contracts\MarketingUser`, `Workspace` is `Gerenuk\Marketing\Models\Workspace`. Match the import style already in the controller.

**Verify**: `vendor/bin/pest tests/Feature/AutomationTest.php tests/Feature/AutomationApprovalTest.php` → the same single failure as Step 1, nothing new. (The owner and Editor have both permissions.)

### Step 3: Tests

1. **Rewrite** the test at line 144 as **"renaming leaves the fingerprint and approval alone"**: only the `name` changes. Keep its second half (an Editor's save leaves it `active` and approved), with a payload that changes only the name.

2. Add to `AutomationApprovalTest.php`, **"structure changes change the fingerprint"**. Start from an approved automation with a condition step whose `yes` branch holds the email (`['id' => 'c1', 'type' => 'condition', 'config' => [...], 'yes' => [approvalEmail(...)], 'no' => []]`). Copy a valid condition config from `tests/Feature/AutomationTest.php` (`grep -n "'condition'" tests/Feature/AutomationTest.php`). Each of these changes the fingerprint:
    - removing the condition and keeping the email at the top level;
    - moving the email from `yes` to `no`;
    - setting `allow_reentry` to true;
    - adding a webhook step;
    - changing a webhook step's URL;
    - adding a wait step, or changing its amount;
    - reordering two steps.

    And reordering keys inside a step's `config` doesn't.

3. Add **"an editor adding a webhook pauses a live approved automation"**: `approvalLive($this)`, then as `$this->editor` put a payload with a webhook step added. Expect `status` `paused` and `approved_at` null.

4. Create `tests/Feature/AutomationStepPermissionsTest.php`. Its `beforeEach` follows `AutomationApprovalTest.php`'s (owner, workspace with postal address, provider, list), **without** `requires_campaign_approval`. Don't redeclare the `approval...` helpers; they're global Pest functions you can call. Make a custom role `Automations only` with `automations.view` and `automations.manage`, and a member with it.
    - Saving a definition with a webhook step as that member → `assertSessionHasErrors('definition')`, and the automation's definition is unchanged.
    - Saving one with an update-contact step (copy a valid config from `AutomationTest.php`) → same.
    - Saving an email-only definition → no errors.
    - Activating an automation (created directly with a webhook step) as that member → `assertSessionHasErrors('definition')`; status stays `draft`.
    - With `contacts.view` added to the role, the webhook save succeeds; with `contacts.manage` added, the update-contact save succeeds.

**Revert checks** (restore after each, commit nothing from them):

- Restore the old `fingerprint()` body: tests 2 and 3 fail.
- Remove the `ensureCanUseSteps` call in `update()`: the first two tests in `AutomationStepPermissionsTest` fail.
- Remove it in `activate()`: the activation test fails.

**Verify**: `vendor/bin/pest` → all pass (apart from the 7 SES failures); PHPStan → 0 errors; Pint → passes.

## Done criteria

- [ ] `grep -n "EMAIL_FIELDS" src/Automations/AutomationApproval.php` → no match
- [ ] A non-approver's change to conditions, branches, order, waits, webhooks, update-contact steps or `allow_reentry` withdraws approval and pauses a live automation in approval workspaces
- [ ] Renaming doesn't
- [ ] Saving or starting an automation with a webhook step needs `contacts.view`; with an update-contact step, `contacts.manage`
- [ ] New tests pass and the revert checks fail as described
- [ ] Pest, PHPStan and Pint pass
- [ ] `plans/README.md` row updated

## STOP conditions

- The drift check shows changes the excerpts don't cover.
- Steps carry a purely visual key (Step 1).
- Any existing `AutomationTest` test fails after Step 1 or Step 2. That would mean a flow relies on edits never changing status or on these permissions.
- `activate()` is called from somewhere other than its route (`grep -rn "->activate(" src`), so adding `Request` would break a caller.

## Maintenance notes

- **Release note:** in approval workspaces, any change to an automation's flow by a non-approver now pauses it until it's approved. Only renaming doesn't. Custom roles need `contacts.view` for webhook steps and `contacts.manage` for update-contact steps.
- The fingerprint is computed on the fly before and after each save, so changing it doesn't affect stored data or pause anything at deploy.
- Automations that are live with a webhook step created by someone without `contacts.view` keep running. A one-off check could list them (`definition` containing `"type":"webhook"`); not done here.
- New step types are covered automatically, because the whole tree is hashed. If a step ever gains a purely visual field (a label, a canvas position), exclude it in `fingerprint()`, or renaming will start pausing automations.
