# Plan 032: One `ConfirmDialog` for every "Are you sure?"

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 2c44823..HEAD -- packages/core/resources/js/components/confirm-delete.tsx packages/core/resources/js/pages/marketing/lists/index.tsx packages/core/resources/js/pages/marketing/segments/index.tsx packages/core/resources/js/pages/marketing/imports/index.tsx packages/core/resources/js/pages/marketing/providers/show.tsx packages/core/resources/js/pages/marketing/templates/form.tsx packages/core/resources/js/pages/marketing/workspace packages/core/resources/js/pages/marketing/campaigns/show.tsx packages/core/resources/js/pages/marketing/campaigns/edit.tsx packages/core/resources/js/pages/marketing/suppressions/index.tsx`
> Expected: no output. If a file changed (plans 029–031 touch some of these), re-check that the dialog excerpts below still match; STOP if one doesn't.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED-LOW. These dialogs guard deletes and "stop sending"; the request each one makes must stay exactly the same.
- **Depends on**: none. Run after 029–031 if they're in flight, to avoid rebase conflicts in `campaigns/*`, `imports/*`.
- **Category**: reuse + consistency
- **Planned at**: commit `2c44823`, 2026-11-12

## Why this matters

About a dozen confirm dialogs are hand-written with the same structure. They have drifted: Cancel is `ghost` in most, `secondary` in others (`templates/form.tsx`, `campaigns/show.tsx`, `campaigns/edit.tsx`, `ConfirmDelete`), and the footer has `gap-2` in some. The existing `ConfirmDelete` can't be reused because it owns its own trigger button, while almost every page opens its dialog from a dropdown menu item via state.

## Current state

The common shape — `packages/core/resources/js/pages/marketing/lists/index.tsx` (around line 323):

```tsx
<Dialog open={deleting} onOpenChange={setDeleting}>
    <DialogContent>
        <DialogTitle>Delete {list.name}?</DialogTitle>
        <DialogDescription>
            The list is removed. Its contacts are kept, and stay on any other
            lists they are on.
        </DialogDescription>
        <DialogFooter>
            <DialogClose asChild>
                <Button variant="ghost">Cancel</Button>
            </DialogClose>
            <Button
                variant="destructive"
                onClick={() =>
                    router.delete(ContactListController.destroy.url(list.id), {
                        preserveScroll: true,
                    })
                }
            >
                Delete list
            </Button>
        </DialogFooter>
    </DialogContent>
</Dialog>
```

A variant with a non-destructive confirm and custom cancel label — `packages/core/resources/js/pages/marketing/campaigns/edit.tsx` (around line 986):

```tsx
<Dialog open={discarding} onOpenChange={setDiscarding}>
    <DialogContent>
        <DialogTitle>Discard version B's content?</DialogTitle>
        <DialogDescription>…</DialogDescription>
        <DialogFooter className="gap-2">
            <DialogClose asChild>
                <Button variant="secondary">Keep it</Button>
            </DialogClose>
            <Button onClick={discardContent}>Discard</Button>
        </DialogFooter>
    </DialogContent>
</Dialog>
```

`campaigns/show.tsx` (around line 602) — "Stop sending": cancel label "Not yet", confirm calls `setConfirmingStop(false)` then `router.post(...)`.

The existing component, `packages/core/resources/js/components/confirm-delete.tsx`: props `{ url, title, description, trigger = 'Delete' }`, uncontrolled `<Dialog>` with a `DialogTrigger` ghost `sm` button, Cancel `secondary`, confirm `destructive` "Delete" calling `router.delete(url, { preserveScroll: true })`. Used by `automations/edit.tsx` and `templates/index.tsx`.

**Sites to migrate** (under `packages/core/resources/js/pages/marketing/`):

| File                       | ~Line                 | Confirm label | Notes                                                          |
| -------------------------- | --------------------- | ------------- | -------------------------------------------------------------- |
| `lists/index.tsx`          | 323                   | Delete list   |                                                                |
| `segments/index.tsx`       | 286                   | (keep)        |                                                                |
| `imports/index.tsx`        | 237                   | (keep)        | title spans lines                                              |
| `providers/show.tsx`       | 339                   | (keep)        | not the `testing` dialog at ~328                               |
| `templates/form.tsx`       | 246                   | (keep)        | Cancel was `secondary`                                         |
| `workspace/members.tsx`    | 187                   | (keep)        | `open={… !== null}`                                            |
| `workspace/roles.tsx`      | 197                   | (keep)        | not the edit dialog at ~181                                    |
| `workspace/api-tokens.tsx` | 196                   | (keep)        | not the create dialog at ~185                                  |
| `campaigns/show.tsx`       | 602                   | Stop sending  | cancel "Not yet"                                               |
| `campaigns/edit.tsx`       | 986                   | Discard       | non-destructive, cancel "Keep it"; **not** the dialog at ~1824 |
| `suppressions/index.tsx`   | 440 (`RemoveConfirm`) | (keep)        | has a `Shout` → pass as `children`                             |

**Do not migrate:** `tags/index.tsx` and `custom-fields/index.tsx` `DeleteConfirm` (rendered inside a shared multi-mode dialog), `resources/js/components/delete-user.tsx` (password form), `resources/js/components/passkey-item.tsx` (host app), any dialog containing form inputs.

## Conventions

Core components in `packages/core/resources/js/components/<kebab>.tsx`, named exports, JSDoc line; dialog primitives from `@/components/ui/dialog`, `Button` from `@/components/ui/button`.

## Commands you will need

`npm run check` · `npm run types:check` · `npm run test` · `npm run build` — all exit 0. Backend feature tests don't cover these React dialogs.

## Scope

**In scope:** create `packages/core/resources/js/components/confirm-dialog.tsx`; rewrite `confirm-delete.tsx` on top of it (same public props); edit the 11 sites in the table, only the dialog block and imports.
**Out of scope:** the "do not migrate" list; changing any URL, HTTP method, options object (`preserveScroll`, `onSuccess`…), title or description text.

## Git workflow

Branch `advisor/032-confirm-dialog` from `dev`. Conventional commits, e.g. `refactor(core): share the confirm dialog`. Do not push.

## Steps

### Step 1: Create `ConfirmDialog`

```tsx
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Asks before doing something hard to undo. Controlled, so it can open from a menu item.
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    children,
    confirmLabel,
    cancelLabel = 'Cancel',
    destructive = true,
    processing = false,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    description?: ReactNode;
    /** Extra content between the description and the buttons, such as a warning. */
    children?: ReactNode;
    confirmLabel: ReactNode;
    cancelLabel?: ReactNode;
    destructive?: boolean;
    processing?: boolean;
    onConfirm: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                {description && (
                    <DialogDescription>{description}</DialogDescription>
                )}
                {children}
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="ghost">{cancelLabel}</Button>
                    </DialogClose>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={processing}
                        onClick={onConfirm}
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
```

**Verify**: `npm run types:check` → exit 0.

### Step 2: Rebuild `ConfirmDelete` on it

Keep the exported signature `{ url, title, description, trigger = 'Delete' }`. Inside: `const [open, setOpen] = useState(false);`, render the same trigger `<Button variant="ghost" size="sm" onClick={() => setOpen(true)}>{trigger}</Button>` followed by `<ConfirmDialog open={open} onOpenChange={setOpen} title={title} description={description} confirmLabel="Delete" onConfirm={() => { setOpen(false); router.delete(url, { preserveScroll: true }); }} />`.

**Verify**: `npm run types:check` → exit 0; `automations/edit.tsx` and `templates/index.tsx` are unchanged.

### Step 3: Migrate the sites

For each table row: replace the `<Dialog>…</Dialog>` block with `<ConfirmDialog>`, mapping:

- `open` / `onOpenChange` → the same expressions the `<Dialog>` had (copy exactly, including `open={x !== null}` and `onOpenChange={(open) => !open && setX(null)}` forms).
- `<DialogTitle>` children → `title`; `<DialogDescription>` children → `description`; anything else between description and footer → `children`.
- Confirm button's text → `confirmLabel`; its `onClick` body → `onConfirm` (copy verbatim — same URL, method and options).
- If the Cancel button text isn't "Cancel", pass `cancelLabel`.
- If the confirm button has no `variant="destructive"`, pass `destructive={false}`.
- If the confirm button had `disabled={…}`, pass that expression as `processing`.

After each 3 files: `npm run types:check`. Remove unused `Dialog*`/`DialogClose` imports (`npm run check` flags them) — but keep them if the file still has other dialogs.

If a site has a structure not covered by this mapping, leave it and note it in your report.

**Verify (final)**: `npm run check && npm run types:check && npm run test && npm run build` → all exit 0.

## Test plan

No unit test infrastructure for components exists (Vitest runs pure-logic tests only, e.g. `components/email-editors/blocks/model.test.ts`). Do not add React Testing Library. Manual, if a browser is available: for at least lists, segments, providers, a member, a role, an API token, and "Stop sending" on a sending campaign — open the dialog from its menu, Cancel closes it with no request, Confirm performs the same action as before. Report which you checked.

## Done criteria

- All four commands exit 0.
- `grep -rln "ConfirmDialog" packages/core/resources/js` → `confirm-dialog.tsx`, `confirm-delete.tsx`, and the 11 site files.
- `grep -rn 'variant="secondary">Cancel' packages/core/resources/js` → no output.
- For each migrated site, `git diff` shows the same `router.*(...)` call text inside `onConfirm` as was removed.

## STOP conditions

- An excerpt doesn't match, or a site's `onClick` does more than the table suggests in a way you can't copy verbatim.
- Type errors in `ConfirmDelete` call sites after Step 2.

## Maintenance notes

- New confirmations use `ConfirmDialog`; keep request logic at the call site.
- Cancel is now `ghost` everywhere — a deliberate consistency choice.
- Follow-ups not done here: `tags`/`custom-fields` `DeleteConfirm` and host-side `passkey-item`/`delete-user`.
