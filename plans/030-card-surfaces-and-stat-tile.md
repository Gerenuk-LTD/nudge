# Plan 030: Every card uses the card surface; one shared `StatTile`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 2c44823..HEAD -- packages/core/resources/js/pages/marketing/campaigns/show.tsx packages/core/resources/js/pages/marketing/imports/show.tsx packages/core/resources/js/pages/marketing/providers/show.tsx packages/core/resources/js/pages/marketing/home.tsx packages/core/resources/js/pages/marketing/public/unsubscribe.tsx packages/core/resources/js/pages/marketing/campaigns/edit.tsx packages/core/resources/js/components/data-table.tsx resources/js/components/ui/card.tsx`
> Expected: no output. On a mismatch with the excerpts below, STOP.

## Status

- **Priority**: P1
- **Effort**: S–M
- **Risk**: LOW. Visual only; no behaviour change.
- **Depends on**: none (independent of 029)
- **Category**: design bug + reuse
- **Planned at**: commit `2c44823`, 2026-11-12

## Why this matters

The theme (`resources/css/app.css`) has an off-white page (`--background: oklch(0.985 0 0)`) and white cards (`--card: oklch(1 0 0)`); in dark mode the page is `0.13` and cards `0.17`. The app's card convention is `rounded-xl border bg-card shadow-xs` — about 60 places use it.

The maintainer has noticed cards with the wrong colour on desktop. These are the culprits:

1. **Campaign stats** (`campaigns/show.tsx`, the 7 tiles on a sent campaign) have no `bg-card` and no shadow, so they show the page grey while every card around them is white (and darker than their neighbours in dark mode).
2. **Unsubscribe page card** uses `bg-background` instead of `bg-card`.
3. **shadcn `Card`** (`resources/js/components/ui/card.tsx`) uses `shadow-sm`; everything else uses `shadow-xs`, so the Home page cards look heavier. `Card` is used only by `home.tsx` and `resources/js/layouts/auth/auth-card-layout.tsx`.
4. **Icon tiles** sometimes use `bg-background` (a grey patch inside a white card) where the convention is `bg-muted/40 text-muted-foreground`.

Separately, the same stat tile is written three times as a local `Stat` component (campaigns, imports, providers). A shared `StatTile` fixes #1 for good.

## Current state

`packages/core/resources/js/pages/marketing/campaigns/show.tsx` (around line 634):

```tsx
function Stat({
    label,
    value,
    detail,
}: {
    label: string;
    value: string;
    detail?: string;
}) {
    return (
        <div className="rounded-xl border p-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-2xl font-semibold tabular-nums">{value}</dd>
            {detail && (
                <dd className="text-xs text-muted-foreground">{detail}</dd>
            )}
        </div>
    );
}
```

Used 7 times inside `<dl className="grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-7">` (around line 350).

`packages/core/resources/js/pages/marketing/providers/show.tsx` (around line 535) — the correct exemplar:

```tsx
function Stat({
    label,
    value,
    hint,
    className,
}: {
    label: string;
    value: string;
    hint?: string;
    className?: string;
}) {
    return (
        <div className="rounded-xl border bg-card p-4 shadow-xs">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd
                className={cn(
                    'mt-1 text-2xl font-semibold tabular-nums',
                    className,
                )}
            >
                {value}
            </dd>
            {hint && <dd className="text-xs text-muted-foreground">{hint}</dd>}
        </div>
    );
}
```

Also used by `RateStat` (same file, ~line 563), which passes `className` for threshold colours.

`packages/core/resources/js/pages/marketing/imports/show.tsx` (around line 538):

```tsx
function Stat({
    icon: Icon,
    label,
    hint,
    value,
    problem = false,
}: {
    icon: LucideIcon;
    label: string;
    hint: string;
    value: number;
    problem?: boolean;
}) {
    return (
        <div className="rounded-xl border bg-card p-4 shadow-xs">
            <dt className="flex items-center gap-2 text-sm text-muted-foreground">
                <Icon
                    className={cn(
                        'size-4',
                        problem && 'text-red-600 dark:text-red-400',
                    )}
                />
                {label}
            </dt>
            <dd
                className={cn(
                    'mt-1 text-2xl font-semibold tabular-nums',
                    problem && 'text-red-700 dark:text-red-400',
                )}
            >
                {value.toLocaleString()}
            </dd>
            <dd className="text-xs text-muted-foreground">{hint}</dd>
        </div>
    );
}
```

**Leave alone** (different by design): `home.tsx`'s `Stat` (trend chip, children, built on `Card`) and `contacts/show.tsx`'s `Stat` (a cell in a divided grid, with a skeleton).

Other surfaces:

- `packages/core/resources/js/pages/marketing/public/unsubscribe.tsx:45`: `<div className="w-full max-w-md space-y-4 rounded-xl border bg-background p-6 text-center shadow-sm">`
- `resources/js/components/ui/card.tsx`: base class `"bg-card text-card-foreground flex flex-col gap-6 rounded-xl border py-6 shadow-sm"`
- Icon tiles with `bg-background`:
    - `packages/core/resources/js/pages/marketing/home.tsx:624`: `"flex size-8 shrink-0 items-center justify-center rounded-lg border bg-background"`
    - `packages/core/resources/js/pages/marketing/campaigns/edit.tsx:1275`: `'flex size-8 items-center justify-center rounded-lg border bg-background text-muted-foreground'` (its `selected` branch overrides with `bg-foreground` — keep that)
    - `packages/core/resources/js/components/data-table.tsx:214`: `"flex size-9 items-center justify-center rounded-lg border bg-background shadow-xs"`

## Conventions

- Shared core components live in `packages/core/resources/js/components/<kebab-name>.tsx`, export named functions, and carry a one-line JSDoc. Exemplar: `packages/core/resources/js/components/status-badge.tsx`.
- Import as `@marketing/components/<name>`; `cn` from `@/lib/utils`.

## Commands you will need

`npm run check` · `npm run types:check` · `npm run test` · `npm run build` — all must exit 0.

## Scope

**In scope:** create `packages/core/resources/js/components/stat-tile.tsx`; edit `campaigns/show.tsx`, `imports/show.tsx`, `providers/show.tsx`, `public/unsubscribe.tsx`, `home.tsx` (line 624 only), `campaigns/edit.tsx` (line ~1275 only), `data-table.tsx` (line ~214 only), `resources/js/components/ui/card.tsx` (shadow only).

**Out of scope:** `home.tsx`'s `Stat`, `contacts/show.tsx`'s `Stat`, any layout/grid change, any status-colour change, anything under `email-editors/` (its `bg-white` is the email canvas, deliberately white).

## Git workflow

Branch `advisor/030-card-surfaces` from `dev`. Conventional commits (e.g. `fix(core): campaign stats use the card surface`). Do not push.

## Steps

### Step 1: Create `StatTile`

`packages/core/resources/js/components/stat-tile.tsx`:

```tsx
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * One figure in a row of stats: a label, a large value and an optional hint, on the card surface.
 * Render inside a `<dl>`.
 */
export function StatTile({
    label,
    value,
    hint,
    icon: Icon,
    problem = false,
    valueClassName,
}: {
    label: ReactNode;
    value: ReactNode;
    hint?: ReactNode;
    icon?: LucideIcon;
    /** Colours the icon and value red, for counts that need attention. */
    problem?: boolean;
    valueClassName?: string;
}) {
    return (
        <div className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
            <dt className="flex items-center gap-2 text-sm text-muted-foreground">
                {Icon && (
                    <Icon
                        aria-hidden
                        className={cn(
                            'size-4',
                            problem && 'text-red-600 dark:text-red-400',
                        )}
                    />
                )}
                {label}
            </dt>
            <dd
                className={cn(
                    'mt-1 text-2xl font-semibold tabular-nums',
                    problem && 'text-red-700 dark:text-red-400',
                    valueClassName,
                )}
            >
                {value}
            </dd>
            {hint && <dd className="text-xs text-muted-foreground">{hint}</dd>}
        </div>
    );
}
```

**Verify**: `npm run types:check` → exit 0.

### Step 2: Use it in the three pages

- `campaigns/show.tsx`: delete the local `Stat`; import `StatTile`; replace each `<Stat label=… value=… detail=…>` with `<StatTile label=… value=… hint=…>` (rename `detail` → `hint`). This adds `mt-1`, `bg-card` and `shadow-xs` — intended.
- `providers/show.tsx`: delete the local `Stat`; in `RateStat` and at the direct call sites replace `<Stat … className={x}>` with `<StatTile … valueClassName={x}>`.
- `imports/show.tsx`: delete the local `Stat`; replace calls with `<StatTile icon={…} label=… hint=… value={n.toLocaleString()} problem={…}>` (the old component called `toLocaleString()` itself — move that to the call site).
- Remove now-unused imports (`LucideIcon`, `cn`) only if nothing else in the file uses them; `npm run check` will flag unused ones.

**Verify**: `npm run check && npm run types:check` → exit 0. `grep -n "^function Stat" packages/core/resources/js/pages/marketing/{campaigns,imports,providers}/show.tsx` → no output.

### Step 3: Fix the other surfaces

- `public/unsubscribe.tsx:45`: `bg-background` → `bg-card`, `shadow-sm` → `shadow-xs`.
- `resources/js/components/ui/card.tsx`: in `Card`'s base class, `shadow-sm` → `shadow-xs`. Change nothing else in that file.
- `home.tsx:624`: `border bg-background` → `border bg-muted/40 text-muted-foreground`.
- `campaigns/edit.tsx:~1275`: `border bg-background text-muted-foreground` → `border bg-muted/40 text-muted-foreground` (leave the `selected &&` line as is).
- `data-table.tsx:~214`: `border bg-background shadow-xs` → `border bg-muted/40`.

**Verify (final)**: `npm run check && npm run types:check && npm run test && npm run build` → all exit 0.

## Test plan

No unit tests (presentational). Manual, if a browser is available: a sent campaign's page — the 7 stat tiles are white like the cards below them, in light and dark mode; an import's result page and a provider's page look unchanged; Home cards have the lighter shadow; `/unsubscribe/...` card is white on the grey page. Say in the report if this wasn't possible.

## Done criteria

- All four commands exit 0.
- `grep -rn "rounded-xl border p-4\"" packages/core/resources/js` → no output.
- `grep -n "shadow-sm" resources/js/components/ui/card.tsx` → no output.
- `grep -rln "StatTile" packages/core/resources/js/pages` → exactly the three show pages.
- `git diff --stat dev` lists only in-scope files.

## STOP conditions

- An excerpt doesn't match.
- A call site passes props the new component doesn't support (other than `detail`/`className` renamed above).

## Maintenance notes

- New stat rows should use `StatTile`, not a local component. `home.tsx`'s trend-chip stat could later grow into a `StatTile` variant; not now.
- Rule of thumb for review: a bordered, rounded box that sits on the page needs `bg-card` (or is an intentional muted inset like `bg-muted/40`); `bg-background` on a card is always wrong.
