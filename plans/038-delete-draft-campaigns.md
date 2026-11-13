# Plan 038: Draft and cancelled campaigns can be deleted from the UI

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Precondition**: plan 032 (`ConfirmDialog`) is merged. Check: `test -f packages/core/resources/js/components/confirm-dialog.tsx && echo ok` → `ok`. If it isn't, STOP.
>
> **Drift check (run first)**: `git diff --stat 2ff0f57..HEAD -- packages/core/src/Http/Controllers/CampaignController.php packages/core/routes/web.php`
> Expected: no output, or only plans 037's changes (which don't touch `destroy`). `campaigns/edit.tsx` and `campaigns/show.tsx` will have changed (plans 029–037). Re-read the excerpts below in the current files.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW. The backend already exists and is guarded. This plan adds the buttons and the tests the backend never had.
- **Depends on**: 032
- **Category**: missing UI
- **Planned at**: commit `2ff0f57`, 2026-11-13

## Why this matters

The maintainer can't delete a draft campaign: no screen has a delete button. The backend supports it already, but nothing calls it, and nothing tests it.

## Current state

`packages/core/routes/web.php` (around line 105), inside the `can:campaigns.manage` group:

```php
        Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
```

`packages/core/src/Http/Controllers/CampaignController.php` (around line 160):

```php
    public function destroy(Campaign $campaign): RedirectResponse
    {
        abort_if($campaign->kind === CampaignKind::Automation, 404);

        abort_unless(in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Cancelled], true), 403, __('Only drafts and cancelled campaigns can be deleted.'));

        $campaign->delete();

        return to_route('marketing.campaigns.index');
    }
```

Child tables (snapshot, links, recipients) cascade on delete (`database/migrations/2026_10_29_000001_create_sending_tables.php`). The only existing test is `AutomationTest.php:370`, which checks that an automation's campaign returns 404.

**Editor**, `packages/core/resources/js/pages/marketing/campaigns/edit.tsx`: the `<EditorBar … actions={…}>` (around line 282) shows an "Unschedule" button when `scheduled`, otherwise the "Next: …" button. `CampaignController` (the Wayfinder actions import) and `router` are already imported. The page only renders for editable campaigns: drafts, and scheduled campaigns shown read-only.

**Report page**, `packages/core/resources/js/pages/marketing/campaigns/show.tsx`: the `PageHeader` `actions` (around line 278) holds a status badge, then buttons guarded by `can('campaigns.manage') && campaign.kind === 'broadcast'` ("Duplicate"; "Resend to non-openers" when `status === 'sent'`). This page has an existing "Stop sending" confirmation. After plan 032 it uses `<ConfirmDialog open=… onOpenChange=… title=… description=… confirmLabel=… onConfirm=… />`. Copy that usage.

`ConfirmDialog` (`@marketing/components/confirm-dialog`) props: `open`, `onOpenChange`, `title`, `description?`, `children?`, `confirmLabel`, `cancelLabel = 'Cancel'`, `destructive = true`, `processing?`, `onConfirm`.

## Commands you will need

| Purpose    | Command                                                                 | Expected                                                                                         |
| ---------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Core tests | `cd packages/core && vendor/bin/pest`                                   | all pass                                                                                         |
| PHP style  | `composer lint:check`                                                   | exit 0                                                                                           |
| Frontend   | `npm run check && npm run types:check && npm run test && npm run build` | exit 0. If `npm run check` flags only formatting in `plans/*.md`, that failure was already there |

## Scope

**In scope:** `campaigns/edit.tsx` (header actions plus one confirm dialog), `campaigns/show.tsx` (one button plus one confirm dialog), and new tests in `packages/core/tests/Feature/SendingTest.php`, or in a campaign test file if one exists (grep `marketing.campaigns.update` in `packages/core/tests/Feature` and use that file).
**Out of scope:** `CampaignController` (no backend change), the campaigns index cards (they are whole-card links; deleting from them is a later idea), and soft-deletes.

## Git workflow

Branch `advisor/038-delete-campaigns` from `dev`. Conventional commits (e.g. `feat(core): delete draft campaigns from the editor`). Do not push.

## Steps

### Step 1: Tests for the existing endpoint (characterise before touching the UI)

Add these, following the existing `actingAs($this->owner)->…` style:

1. A draft is deleted: `delete(route('marketing.campaigns.destroy', $draft))` redirects to `marketing.campaigns.index`, and `Campaign::find($draft->id)` is null.
2. A cancelled campaign is deleted, and its `CampaignRecipient` rows go with it. Create one through the factory, or set its status, following how other tests do it.
3. A sent campaign (`status` `Sent`) and a scheduled one each return 403 and still exist.
4. A member without `campaigns.manage` gets 403. Follow how `MembersAndRolesTest.php` or other tests build a limited member. If there's no helper for a limited role, skip this test and say so in the report.

**Verify**: `cd packages/core && vendor/bin/pest --filter=<your test names>` → passes without any production change.

### Step 2: Delete from the editor

In `edit.tsx`:

1. Add `const [deleting, setDeleting] = useState(false);` in the page component, **above** the `useAutosave(...)` call so it's defined when that call uses it.
2. In `EditorBar` `actions`, when **not** `scheduled`, put a ghost button **before** the Next button. Wrap both in a fragment:
    ```tsx
    <Button
        variant="ghost"
        className="text-muted-foreground"
        onClick={() => setDeleting(true)}
    >
        <Trash2 />
        <span className="sr-only sm:not-sr-only">Delete</span>
    </Button>
    ```
    Import `Trash2` from `lucide-react`. Don't add it when `scheduled`, because a scheduled campaign must be unscheduled first, and the backend returns 403.
3. Render:
    ```tsx
    <ConfirmDialog
        open={deleting}
        onOpenChange={setDeleting}
        title={`Delete ${form.data.name || 'this draft'}?`}
        description="The draft and its content are removed. This can't be undone."
        confirmLabel="Delete draft"
        onConfirm={() =>
            router.delete(CampaignController.destroy.url(campaign.id))
        }
    />
    ```
4. Autosave must not fire after deletion. `useAutosave(form, campaign.id, disabled)` already takes a `disabled` flag. The call is `useAutosave(form, campaign.id, scheduled);` (around line 228); change it to `useAutosave(form, campaign.id, scheduled || deleting);`.

### Step 3: Delete a cancelled campaign from its report

In `show.tsx`, in `PageHeader` `actions`, add a ghost "Delete" button after "Duplicate". Show it only when `can('campaigns.manage') && campaign.kind === 'broadcast' && campaign.status === 'cancelled'`. Give it a `ConfirmDialog` like the one in Step 2: title `Delete ${campaign.name}?`, description "The campaign and its delivery history are removed. This can't be undone.", confirmLabel "Delete campaign", and onConfirm `router.delete(CampaignController.destroy.url(campaign.id))`. Use a new `useState` flag.

**Verify (final)**: run every command in the table.

## Test plan

The Step 1 tests cover the server. Manual check, if a browser is available:

- In a draft's editor, Delete → confirm → you land on Campaigns and the draft is gone.
- A scheduled campaign's editor shows no Delete button.
- A cancelled campaign's report shows Delete. A sent campaign's report doesn't.

## Done criteria

- All commands pass.
- `grep -n "CampaignController.destroy" packages/core/resources/js/pages/marketing/campaigns/edit.tsx packages/core/resources/js/pages/marketing/campaigns/show.tsx` → one match in each.
- `git diff --stat dev -- packages/core/src` → no output (no backend change).

## STOP conditions

- Plan 032 isn't merged.
- A Step 1 test fails against the current backend, meaning the endpoint doesn't behave as described. Report it, and don't change the controller.
- `useAutosave` has no way to be disabled.

## Maintenance notes

- Campaign deletion is a hard delete, and children cascade. Segment conditions can reference a campaign by id (`campaign` field). Deleting a cancelled campaign that a segment refers to leaves that condition matching nobody. That's existing behaviour; a follow-up could warn about it in the dialog.
- If deleting from the index cards is wanted later, a card needs a menu button outside the `<Link>`.
