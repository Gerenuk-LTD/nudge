# Plan 031: One `EmptyState` component instead of 12 copies

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `grep -rn "rounded-xl border border-dashed bg-muted/40" packages/core/resources/js | wc -l`
> Expected: `15`. If different, list the matches and compare with the table below; STOP if a listed site is missing.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW. Presentational; copy and actions are kept exactly.
- **Depends on**: none. If plan 030 has landed, nothing conflicts.
- **Category**: reuse
- **Planned at**: commit `2c44823`, 2026-11-12

## Why this matters

The "nothing here yet" block — a dashed box with a round icon, a title, a sentence and actions — is hand-written on 12 screens with drifting padding (`py-16`, `py-14`, `py-12`), gaps and icon styles. Every new screen copies it again. A component makes them consistent and gives the next screen one line to write.

## Current state

The canonical copy, `packages/core/resources/js/pages/marketing/contacts/index.tsx` (around line 236):

```tsx
<div className="flex flex-col items-center gap-3 rounded-xl border border-dashed bg-muted/40 px-6 py-16 text-center">
    <span className="flex size-10 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-xs">
        <Users className="size-5" />
    </span>
    <div className="space-y-1">
        <p className="font-medium">No contacts yet</p>
        <p className="max-w-sm text-sm text-muted-foreground">
            Import a CSV from another tool, or add people one at a time.
        </p>
    </div>
    <div className="flex gap-2 pt-1">{/* buttons */}</div>
</div>
```

A drifted copy, `packages/core/resources/js/pages/marketing/automations/index.tsx` (around line 63):

```tsx
<div className="flex flex-col items-center gap-2 rounded-xl border border-dashed bg-muted/40 px-6 py-14 text-center">
    <Workflow className="size-5 text-muted-foreground" />
    <p className="text-sm font-medium">No automations yet</p>
    <p className="max-w-sm text-13 text-muted-foreground">…</p>
</div>
```

**Sites to migrate** (all under `packages/core/resources/js/`):

| File                                      | ~Line | Notes                                            |
| ----------------------------------------- | ----- | ------------------------------------------------ |
| `pages/marketing/contacts/index.tsx`      | 236   | canonical                                        |
| `pages/marketing/custom-fields/index.tsx` | 248   |                                                  |
| `pages/marketing/imports/index.tsx`       | 90    |                                                  |
| `pages/marketing/imports/show.tsx`        | 105   |                                                  |
| `pages/marketing/lists/index.tsx`         | 107   |                                                  |
| `pages/marketing/providers/index.tsx`     | 60    |                                                  |
| `pages/marketing/segments/index.tsx`      | 115   |                                                  |
| `pages/marketing/suppressions/index.tsx`  | 241   |                                                  |
| `pages/marketing/tags/index.tsx`          | 149   |                                                  |
| `pages/marketing/automations/index.tsx`   | 63    | bare icon, py-14 → becomes canonical             |
| `pages/marketing/templates/index.tsx`     | 81    | message only, no title → pass `description` only |
| `components/segment-builder.tsx`          | 268   | py-14                                            |

**Do not migrate** (different jobs): `pages/marketing/imports/create.tsx:148` (a file drop zone `<button>` with drag states), `pages/marketing/campaigns/edit.tsx:~1358` `EmptyChoice`, `components/template-picker.tsx:135`, `pages/marketing/campaigns/index.tsx:293`, and `components/data-table.tsx`'s in-table empty row.

## Conventions

Shared core components: `packages/core/resources/js/components/<kebab>.tsx`, named exports, a JSDoc line. Exemplar: `components/status-badge.tsx`. Import as `@marketing/components/empty-state`.

## Commands you will need

`npm run check` · `npm run types:check` · `npm run test` · `npm run build` — all exit 0.

## Scope

**In scope:** create `packages/core/resources/js/components/empty-state.tsx`; edit the 12 files in the table above, only at the empty-state block (and imports).
**Out of scope:** the "do not migrate" list; any change to copy text, conditions, or buttons.

## Git workflow

Branch `advisor/031-empty-state` from `dev`. Commit the component, then the migrations (one commit is fine). Conventional commits, e.g. `refactor(core): share the empty state`. Do not push.

## Steps

### Step 1: Create the component

```tsx
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * What a screen shows before it has anything in it: an icon, a title, a sentence on what to do, and actions.
 */
export function EmptyState({
    icon: Icon,
    title,
    description,
    actions,
    className,
}: {
    icon?: LucideIcon;
    title?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col items-center gap-3 rounded-xl border border-dashed bg-muted/40 px-6 py-16 text-center',
                className,
            )}
        >
            {Icon && (
                <span className="flex size-10 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-xs">
                    <Icon aria-hidden className="size-5" />
                </span>
            )}
            {(title || description) && (
                <div className="space-y-1">
                    {title && <p className="font-medium">{title}</p>}
                    {description && (
                        <p className="max-w-sm text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
            )}
            {actions && (
                <div className="flex flex-wrap justify-center gap-2 pt-1">
                    {actions}
                </div>
            )}
        </div>
    );
}
```

**Verify**: `npm run types:check` → exit 0.

### Step 2: Migrate each site

For each row of the table: replace the block with `<EmptyState icon={…} title="…" description={…} actions={…} />`, moving the existing icon component, title text, description JSX and the children of the actions `div` across **unchanged**. Pass the actions `div`'s children as a fragment: `actions={<>…</>}`. If a site has no actions `div`, omit `actions`. Keep any surrounding condition (`{empty ? … : …}`) exactly as it was.

If a site has extra content that doesn't fit `icon/title/description/actions` (e.g. a list or a second paragraph), STOP for that site, leave it as is, and note it in your report — do not add props.

Remove imports made unused. **Verify** after every 3–4 files: `npm run types:check`.

**Verify (final)**: `npm run check && npm run types:check && npm run test && npm run build` → all exit 0.

## Test plan

No unit tests. Manual (if a browser is available): open a fresh workspace and visit Contacts, Lists, Segments, Tags, Custom fields, Imports, Providers, Suppressions, Automations, Templates; each empty screen shows the same box with its original copy and buttons, and the buttons still work. Automations and Templates gain the round icon tile — intended. Report if not possible.

## Done criteria

- All four commands exit 0.
- `grep -rn "rounded-xl border border-dashed bg-muted/40" packages/core/resources/js` → only `empty-state.tsx`, `imports/create.tsx`, `campaigns/edit.tsx`, `campaigns/index.tsx` (plus any site you reported as skipped).
- `grep -rln "EmptyState" packages/core/resources/js/pages packages/core/resources/js/components` → the 12 files plus `empty-state.tsx`.

## STOP conditions

- Drift-check count is lower than 15 and a table site is missing.
- A site needs a new prop (see Step 2).

## Maintenance notes

- New "empty" screens use `EmptyState`. A follow-up could let `DataTable`'s `emptyMessage` render it; not in this plan.
- Copy lives at call sites, so translations/wording changes stay local.
