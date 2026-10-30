# Plan 008: Editors no longer lose work, and irreversible campaign actions ask first

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Precondition**: this plan edits pages that had uncommitted changes when it was written (`campaigns/show.tsx`, `contacts/show.tsx`, `imports/index.tsx`, `imports/show.tsx`). Run it only on a commit that includes that work.
>
> **Drift check (run first)**: compare each "Current state" excerpt below with the live file, by grepping the quoted lines. Line numbers may have moved by a few lines, which is fine. On a real mismatch, STOP.

## Status

- **Priority**: P1
- **Effort**: M (several small, independent fixes)
- **Risk**: LOW
- **Depends on**: the author committing their in-progress UI work (see Precondition)
- **Category**: bug
- **Planned at**: commit `f7bb714` plus the author's uncommitted working tree, 2026-10-30

## Why this matters

The campaign editor says "Draft · saves as you type", but it is the only editor with no unsaved-changes guard. Its autosave waits 1.2 seconds and cancels that timer when the page unmounts, so clicking away within 1.2 seconds of typing silently discards the edit. Inertia also cancels an in-flight save when any other visit starts, and nothing retries it.

Across five editors, a save's success handler calls `form.setDefaults()`. With no arguments, that marks the _current_ data as saved, so anything typed while the request was in flight is wrongly marked "Saved" and then lost on navigation.

Three smaller issues sit in the same files:

- **Variant B is easy to lose.** An A/B test's variant B content is wiped by one click on "Subject line".
- **Irreversible actions run on one click.** "Pick the winner now" (sends to the whole remaining audience) and "Stop sending" have no confirmation.
- **Polling never stops.** Import and campaign progress polling keeps running after the job finishes, because Inertia's `usePoll` reads `autoStart` only once.
- **Errors are invisible.** Validation errors on fields the campaign page doesn't show, such as `list_ids.0` after a list is deleted, make every autosave fail with no message.

## Current state

All paths below are relative to `packages/core/resources/js/`. The frontend is React 19 with Inertia v3 (`@inertiajs/react` 3.9.x).

**Guard hook**, `hooks/use-editor.ts`:

```ts
/**
 * Asks before leaving the page while there are unsaved changes, whether by a link in the app or by closing the tab.
 * Saves and partial reloads on the same page are let through.
 */
export function useUnsavedChangesGuard(dirty: boolean) { ... router.on('before', ...) ... beforeunload ... }
```

It's used by the providers, contacts, templates, segments and automations editors, but not by campaigns.

**Campaign autosave**, `pages/marketing/campaigns/edit.tsx` (around lines 350-381):

```tsx
function useAutosave(form: DraftForm, campaignId: number, disabled: boolean) {
    const serialized = JSON.stringify(form.data);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        if (disabled || !form.isDirty) {
            return;
        }

        const timer = setTimeout(() => {
            form.submit(CampaignController.update(campaignId), {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    form.setDefaults();
                    router.reload({ only: ['checklist', 'audienceCount'] });
                },
            });
        }, 1200);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [serialized, disabled]);
}
```

It's called as `useAutosave(form, campaign.id, scheduled);` (around line 227). The save badge comes from:

```tsx
const saveState: SaveState = form.processing
    ? 'saving'
    : form.hasErrors
      ? 'error'
      : form.isDirty
        ? 'unsaved'
        : 'saved';
```

**Inertia facts** (verified in `node_modules`):

- `form.setDefaults()` with no arguments copies the current data. `form.setDefaults(fields: Partial<TForm>)` sets specific values.
- Visit options include `async: boolean`. An `async` visit is not cancelled by other visits.
- `usePoll(interval, requestOptions, { autoStart })` reads `autoStart` once, on mount. It returns `{ start, stop, polling }`.

**Other `setDefaults()` sites:**

- `pages/marketing/templates/form.tsx` (around line 83): `onSuccess: () => form.setDefaults(),`
- `pages/marketing/contacts/show.tsx` (around line 163): `onSuccess: () => form.setDefaults(),`. This form uses `form.transform((data) => ({ ...data, status: contact.status }))` before submitting.
- `pages/marketing/segments/form.tsx` (around line 122): `form.setDefaults(); setSavedDefinition(JSON.stringify(definition));`

**Automations**, `pages/marketing/automations/edit.tsx` (around lines 100-128). It keeps its own `dirty` boolean. `save()` calls `router.put(...)` with `onSuccess: () => { setDirty(false); setErrors({}); then?.(); }`, and the guard is `useUnsavedChangesGuard(dirty && !saving);`.

**A/B panel**, `campaigns/edit.tsx` `AbTestPanel` (around lines 760-800). It works out the mode as `const testsContent = form.data.ab_html_b !== '';`. The "Subject line" and "Content" toggle buttons call:

```tsx
onClick={() =>
    form.setData((data) => ({
        ...data,
        // Version B's content starts as a copy of A, ready to change.
        ab_html_b: content ? data.html : '',
        ab_editor_state_b: content ? data.editor_state : '',
    }))
}
```

**One-click actions**, `pages/marketing/campaigns/show.tsx`:

- around line 292, `Stop sending`: `onClick={() => router.post(CampaignController.cancel.url(campaign.id))}`
- around line 378, `Pick the winner now`: `onClick={() => router.post(CampaignController.pickWinner.url(campaign.id))}`

**Confirmation pattern to copy.** The "Send now" dialog in `campaigns/edit.tsx` (around line 1724):

```tsx
<Dialog open={confirming} onOpenChange={setConfirming}>
    <DialogContent>
        <DialogTitle>Send to {people} now?</DialogTitle>
        <DialogDescription>
            It starts going out straight away and can't be called back once
            delivered.
        </DialogDescription>
        <DialogFooter className="gap-2">
            <DialogClose asChild>
                <Button variant="secondary">Not yet</Button>
            </DialogClose>
            <Button disabled={processing} onClick={submit}>
                {processing && <Spinner />}Send now
            </Button>
        </DialogFooter>
    </DialogContent>
</Dialog>
```

`components/confirm-delete.tsx` is a similar reusable component, but it's hard-wired to `router.delete`.

**Polling sites:**

- `pages/marketing/imports/show.tsx:341`: `usePoll(2000, { only: ['import', 'failedRows'] }, { autoStart: running });`
- `pages/marketing/imports/index.tsx:48`: `usePoll(3000, { only: ['imports'] }, { autoStart: running });`
- `pages/marketing/campaigns/show.tsx:136`: `usePoll(4000, { only: ['campaign', 'links'] }, { autoStart: active });`

**Errors.** The campaign editor shows only these top-level keys:

`ab_metric, ab_sample_percent, ab_subject_b, ab_wait_minutes, campaign, from_email, from_name, html, list_ids, name, preheader, provider_id, reply_to, segment_id, send_at, subject`

The server can also return `list_ids.N`, `text`, `ab_html_b`, `editor_state` and `ab_editor_state_b`. In `automations/edit.tsx`, `problems` (around line 94) lists only `errors.automation`, `errors.name` and `errors.definition`, so `trigger_config.*` errors never appear.

**Conventions.** TypeScript with 4-space indentation, Prettier through `vp check`, and shadcn UI from `@/components/ui/*`. Core may import only `@/components/ui/*`, `@/lib/utils`, `@/components/input-error`, `@/hooks/use-clipboard`, `@/hooks/use-current-url` and Wayfinder `@/routes`/`@/actions` from the host. Don't add any other host import.

## Commands you will need

Run these from the repo root.

| Purpose                 | Command                                         | Expected                                   |
| ----------------------- | ----------------------------------------------- | ------------------------------------------ |
| Types                   | `npm run types:check`                           | exit 0                                     |
| Lint and format         | `npm run check`                                 | exit 0 (use `npm run check:fix` to format) |
| Build                   | `npm run build`                                 | exit 0                                     |
| Core PHP suite (sanity) | `cd packages/core && vendor/bin/pest --compact` | all pass (`PagesTest` renders these pages) |

There are no JS unit tests for page components, so verification here is types, lint, build and the review checklist below. Do not add a test framework in this plan; plan 010 does that.

## Scope

**In scope:**

- `pages/marketing/campaigns/edit.tsx`
- `pages/marketing/campaigns/show.tsx`
- `pages/marketing/templates/form.tsx`
- `pages/marketing/contacts/show.tsx`
- `pages/marketing/segments/form.tsx`
- `pages/marketing/automations/edit.tsx`
- `pages/marketing/imports/show.tsx`
- `pages/marketing/imports/index.tsx`
- `hooks/use-editor.ts`, only if Step 1 needs it

**Out of scope:**

- PHP controllers. Don't change `CampaignController::update`'s response.
- The block editor and content editor (plan 010).
- Performance refactors.
- Changing how autosave serialises data.

## Git workflow

- Branch: `advisor/008-editor-data-loss`.
- One commit per step is fine. Use the prefix `fix:`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Guard the campaign editor and make autosave robust

In `campaigns/edit.tsx`:

1. After `useAutosave(...)`, add `useUnsavedChangesGuard(!scheduled && (form.isDirty || form.processing));`. Import it from where the other editors import it (copy the import line from `templates/form.tsx`).
2. In `useAutosave`:
    - Snapshot what is sent with `const sent = structuredClone(form.data);` just before `form.submit`.
    - Pass `async: true` in the submit options, so navigation and partial reloads don't cancel the save.
    - Replace `form.setDefaults()` with `form.setDefaults(sent)`, so edits made during the request stay dirty and get saved by the next run of the effect.
    - Add `onCancel` and `onError` handlers that re-arm a save. Add a `const [retry, setRetry] = useState(0);` counter inside the hook, include `retry` in the effect's dependency array, and call `setRetry((n) => n + 1)` from `onCancel` only. Don't retry on `onError`: validation errors need the user to act, and Step 6 makes them visible.

**Verify**: `npm run types:check && npm run check` → both exit 0

### Step 2: Mark only what was sent as saved, in the other editors

- `templates/form.tsx`: snapshot `const sent = structuredClone(form.data);` before `form.submit`, then use `onSuccess: () => form.setDefaults(sent)`.
- `contacts/show.tsx`: same, with `onSuccess: () => form.setDefaults(sent)`. The `transform` changes only the payload, not `form.data`, so snapshot `form.data`.
- `segments/form.tsx`: same for the form fields. Keep the existing `setSavedDefinition(JSON.stringify(definition))`, which already snapshots correctly.
- `automations/edit.tsx`: replace the `dirty` boolean reset with a version counter.
    - Add `const version = useRef(0);`, and increment it wherever `setDirty(true)` is called (the `change(...)` helper around line 88).
    - In `save`, capture `const savedVersion = version.current;`.
    - In `onSuccess`, call `setDirty(version.current !== savedVersion)` instead of `setDirty(false)`.
    - Change the guard to `useUnsavedChangesGuard(dirty || saving);`.

**Verify**: `npm run types:check && npm run check` → both exit 0

### Step 3: Make the A/B mode explicit, and confirm before discarding variant B

In `AbTestPanel`:

- Replace the derived `testsContent` with state: `const [testsContent, setTestsContent] = useState(form.data.ab_html_b !== '');`.
- Clicking "Content" sets it true, and copies A into B only if B is empty.
- Clicking "Subject line" while B has content opens a confirmation dialog, following the "Send now" pattern: title "Discard version B's content?", description "Testing the subject line sends version A's content to everyone. Version B's content will be deleted.", buttons "Keep it" and "Discard". Only on "Discard" does it clear `ab_html_b` and `ab_editor_state_b` and set `testsContent` false. If B is already empty, switch straight away.
- Make the B editor's visibility depend on `testsContent`, not on `ab_html_b !== ''`, so deleting all of B's HTML doesn't unmount the editor. Find that condition around lines 826-853.

**Verify**: `npm run types:check && npm run check` → exit 0

### Step 4: Confirm "Pick the winner now" and "Stop sending"

In `campaigns/show.tsx`, put both buttons behind dialogs that follow the "Send now" pattern, each with its own `useState` open flag:

- **Pick the winner:** title "Send the leading version to everyone else now?", description "The remaining audience gets the version that's ahead right now. This can't be undone.", confirm button "Pick the winner".
- **Stop sending:** title "Stop sending this campaign?", description "Anyone who hasn't been sent it yet won't get it. Emails already sent can't be recalled.", destructive confirm button "Stop sending".

The confirm buttons call the same `router.post(...)` as today.

**Verify**: `npm run types:check && npm run check` → exit 0

### Step 5: Make polling follow its condition

At each of the three `usePoll` sites, use:

```tsx
const { start, stop } = usePoll(
    2000,
    { only: ['import', 'failedRows'] },
    { autoStart: false },
);

useEffect(() => {
    if (running) {
        start();
    } else {
        stop();
    }
}, [running, start, stop]);
```

Keep each site's own interval, `only` list and condition variable (`running` or `active`).

**Verify**: `npm run types:check && npm run check` → exit 0

### Step 6: Show every validation error

- **`campaigns/edit.tsx`:** below the step content, list every error key not shown inline. Use a list near the Review step, or an alert at the top when `form.hasErrors`. Keep a `const SHOWN = new Set([... the 16 keys above ...])`, and render `Object.entries(form.errors).filter(([key]) => !SHOWN.has(key))` (dotted keys like `list_ids.0` are never in `SHOWN`, so they always appear) as "Can't save: <message>". Also map any `list_ids.N` message onto the audience step by showing it next to the existing `errors.list_ids` message.
- **`automations/edit.tsx`:** add every `trigger_config.*` error message to `problems`.

**Verify**: `npm run types:check && npm run check && npm run build` → all exit 0
**Verify**: `cd packages/core && vendor/bin/pest --compact` → all pass

## Test plan

There's no JS test harness for pages yet (plan 010 adds Vitest for pure modules only), so this plan's checks are types, lint, build and the PHP page-render tests. In your report, list the manual checks a reviewer should run:

1. Type in the campaign subject, then click a sidebar link within a second. A "Leave without saving?" prompt appears; after you cancel, the save completes.
2. Type continuously through a save. The badge ends on "Saved" only once the last keystroke is saved.
3. Build variant B content, then click "Subject line". The confirmation appears; "Keep it" preserves B.
4. "Pick the winner now" and "Stop sending" both ask first.
5. An import page stops polling once the import completes. Check the network tab.
6. With a deleted list still selected in a draft, the page shows a clear "Can't save" message.

## Done criteria

- [ ] `grep -n "useUnsavedChangesGuard" packages/core/resources/js/pages/marketing/campaigns/edit.tsx` returns a match.
- [ ] `grep -rn "setDefaults()" packages/core/resources/js/pages` returns no matches.
- [ ] `grep -rn "autoStart: running\|autoStart: active" packages/core/resources/js/pages` returns no matches.
- [ ] `npm run types:check`, `npm run check` and `npm run build` all exit 0.
- [ ] The core Pest suite passes.
- [ ] Only in-scope files are changed.

## STOP conditions

- `async: true` on `form.submit` fails to type-check, or Inertia rejects it at build time. Report it rather than switching to `useHttp` unasked.
- An excerpt doesn't match, for example because the precondition wasn't met and the uncommitted UI work is missing.
- A change appears to need a PHP or controller change.

## Maintenance notes

- Any new editor must call `useUnsavedChangesGuard`, and must pass the submitted snapshot to `setDefaults`, never call it with no arguments. Reviewers should grep for `setDefaults()`.
- `async: true` on autosave means navigation won't cancel the save. If the server later responds with a redirect to a different page, revisit this.
- Deferred:
    - reducing the 4 requests each autosave makes (return a lightweight response or JSON from autosave);
    - block-editor typing performance.
