# Plan 029: Pages work on a 375px phone — nothing is cut off or unreachable

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 2c44823..HEAD -- packages/core/resources/js/components/page-header.tsx packages/core/resources/js/components/editor-bar.tsx packages/core/resources/js/components/settings-list.tsx packages/core/resources/js/components/automation/flow-canvas.tsx packages/core/resources/js/components/email-editors/blocks/block-editor.tsx packages/core/resources/js/pages/marketing/campaigns/edit.tsx packages/core/resources/js/pages/marketing/imports/show.tsx packages/core/resources/js/pages/marketing/workspace/members.tsx resources/js/components/two-factor-recovery-codes.tsx resources/js/components/breadcrumbs.tsx`
> Expected: no output. If any file changed, compare it with the excerpts below; if an excerpt no longer matches, STOP.

## Status

- **Priority**: P1
- **Effort**: S–M
- **Risk**: LOW. Class-name changes only; every change is a no-op at `sm` (640px) and wider unless stated.
- **Depends on**: none
- **Category**: design bug (mobile)
- **Planned at**: commit `2c44823`, 2026-11-12

## Why this matters

The maintainer reports layout bugs on mobile. The app layout clips horizontal overflow (`resources/js/layouts/app/app-sidebar-layout.tsx:14`: `<AppContent variant="sidebar" className="min-w-0 overflow-x-clip">`). That is deliberate (it stops the page scrolling sideways), but it means anything wider than the screen is **silently cut off and unreachable** — there is no scrollbar. At 375px the page has `px-4`, so content gets 343px. Several rows are wider than that.

**Do not remove `overflow-x-clip`.** Fix the rows that overflow instead.

## Conventions

- Tailwind v4, mobile-first: unprefixed classes apply on phones; `sm:` = ≥640px, `md:` ≥768px, `lg:` ≥1024px.
- Classes are merged with `cn()` from `@/lib/utils`.
- Core UI lives in `packages/core/resources/js/` (imported as `@marketing/...`); host UI in `resources/js/` (imported as `@/...`).
- Formatting is enforced by `npm run check` (vite-plus / oxlint + oxfmt). Run `npm run check:fix` if it reports formatting only.

## Commands you will need

| Purpose             | Command               | Expected |
| ------------------- | --------------------- | -------- |
| Lint + format check | `npm run check`       | exit 0   |
| Typecheck           | `npm run types:check` | exit 0   |
| Unit tests          | `npm run test`        | all pass |
| Build               | `npm run build`       | exit 0   |

## Scope

**In scope (only these files):**

- `packages/core/resources/js/components/page-header.tsx`
- `packages/core/resources/js/components/editor-bar.tsx`
- `packages/core/resources/js/components/settings-list.tsx`
- `packages/core/resources/js/components/automation/flow-canvas.tsx`
- `packages/core/resources/js/components/email-editors/blocks/block-editor.tsx`
- `packages/core/resources/js/pages/marketing/campaigns/edit.tsx`
- `packages/core/resources/js/pages/marketing/imports/show.tsx`
- `packages/core/resources/js/pages/marketing/workspace/members.tsx`
- `resources/js/components/two-factor-recovery-codes.tsx`
- `resources/js/components/breadcrumbs.tsx`

**Out of scope:** `resources/js/layouts/**` (keep `overflow-x-clip`), `resources/js/components/ui/**` (shadcn primitives), any colour/background change (plan 030 does those), any PHP.

## Git workflow

Branch `advisor/029-mobile-layout` from `dev`. One commit per step is fine, conventional-commit style matching history (e.g. `fix(core): page header actions wrap on narrow screens`). Do not push.

## Steps

### Step 1: Page header actions wrap

`packages/core/resources/js/components/page-header.tsx` currently:

```tsx
<div className="flex flex-wrap items-end justify-between gap-4">
    <div className="space-y-1">
        <h1 className="text-2xl font-semibold">{title}</h1>
        ...
    </div>
    {actions && <div className="flex gap-2">{actions}</div>}
</div>
```

The outer row wraps, but the actions row itself never does. On a sent campaign (`campaigns/show.tsx`, four actions) it is ~480px wide and the last buttons are clipped.

Change the actions div to `className="flex flex-wrap items-center gap-2"`. Also add `min-w-0` to the title's `space-y-1` div and `break-words` to the `h1` so long campaign names wrap.

**Verify**: `npm run types:check` → exit 0.

### Step 2: Editor step bar fits

`packages/core/resources/js/components/editor-bar.tsx`, the stepper `<ol>` (around line 145):

```tsx
        <ol className="flex items-center gap-2" aria-label="Steps">
```

Each step label is `'text-sm whitespace-nowrap'` (around line 174). "Content / Audience / Review and send" is ~410px.

Make two changes:

1. The `<ol>`: `className="flex items-center gap-2 overflow-x-auto"`.
2. The label `<span>` inside `marker`: hide labels of non-active steps below `sm`. Change its `cn(...)` so the first argument stays `'text-sm whitespace-nowrap'` and the non-active branch becomes `'hidden text-muted-foreground sm:inline'` (the active branch stays `'font-medium text-foreground'`). The numbered circle stays visible for every step, so steps remain clickable. Because the label is now hidden for inactive steps on mobile, add `aria-label={step.label}` to the `<button>` rendered when `onSelect` is set (around line 196) **only if** the button has no other accessible text — it always contains the number, so add `aria-label={`${index + 1}. ${step.label}`}`.

**Verify**: `npm run types:check && npm run check` → exit 0.

### Step 3: 2FA recovery codes readable

`resources/js/components/two-factor-recovery-codes.tsx` around line 116:

```tsx
className =
    'grid grid-cols-2 gap-x-6 gap-y-1 rounded-md border bg-background p-4 font-mono text-sm';
```

Change `grid-cols-2` to `grid-cols-1 sm:grid-cols-2`. (The parent's `overflow-hidden` is for the show/hide animation; leave it.)

### Step 4: Mobile email preview isn't cropped

Two iframes set a fixed 375px width inside a padded panel narrower than that:

`packages/core/resources/js/pages/marketing/campaigns/edit.tsx` (around line 1520):

```tsx
                            className="h-[36rem] rounded-md border bg-white transition-[width]"
                            style={{
                                width: device === 'mobile' ? 375 : '100%',
                            }}
```

`packages/core/resources/js/components/email-editors/blocks/block-editor.tsx` (around line 477):

```tsx
                        className="h-[40rem] rounded-lg border bg-white shadow-sm transition-[width]"
                        style={{ width: mode === 'mobile' ? 375 : '100%' }}
```

Add `max-w-full` to both `className`s. Do not change `bg-white` — the email canvas is intentionally white because emails render on white.

### Step 5: Automation "remove step" button usable on touch

`packages/core/resources/js/components/automation/flow-canvas.tsx` around line 379:

```tsx
className =
    'absolute top-2 right-2 size-7 text-muted-foreground opacity-0 group-hover:opacity-100 focus-visible:opacity-100';
```

Tailwind v4's `hover:`/`group-hover:` only apply on devices that can hover, so phones never see this button. Replace `opacity-0` with `opacity-100 pointer-fine:opacity-0` (Tailwind v4 has the `pointer-fine` variant built in). Keep `group-hover:opacity-100 focus-visible:opacity-100`.

**Verify**: `npm run check` → exit 0. If `pointer-fine` is reported as an unknown class, STOP and report.

### Step 6: Member and invitation rows don't crush the name

`packages/core/resources/js/components/settings-list.tsx` (around line 72):

```tsx
        <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 sm:px-6">
            {leading}
            <div className="min-w-0 flex-1">
            ...
            {children && (
                <div className="flex shrink-0 items-center gap-1">
                    {children}
```

The text column has `flex-1` with basis 0, so it shrinks to ~60px instead of the controls wrapping. Change the text column to `className="min-w-0 flex-1 basis-40"` — rows then wrap their controls onto the next line when there's less than 160px for the text.

`packages/core/resources/js/pages/marketing/workspace/members.tsx` (around line 143), the invitation email:

```tsx
<span className="text-muted-foreground">{invitation.email}</span>
```

Change to `className="min-w-0 truncate text-muted-foreground"`.

**Verify**: `npm run types:check` → exit 0.

### Step 7: Import column mapping fits

`packages/core/resources/js/pages/marketing/imports/show.tsx`, the `columns` array (around lines 189–245). `DataTable` applies `meta.className` to both the header and every cell of a column.

- `target` column: change `meta: { className: 'w-64' }` to `meta: { className: 'sm:w-64' }`.
- `matched` column: change `meta: { className: 'w-44' }` to `meta: { className: 'hidden w-44 sm:table-cell' }`.
- `column` column: change the cell's `"grid max-w-xs leading-tight"` to `"grid max-w-40 leading-tight sm:max-w-xs"`.

### Step 8: Long breadcrumbs don't overflow the 64px header

`resources/js/components/breadcrumbs.tsx` renders the last crumb as `<BreadcrumbPage>{item.title}</BreadcrumbPage>`. The last crumb is often a contact's email or a campaign name.

Change it to `<BreadcrumbPage className="block max-w-[12rem] truncate sm:max-w-xs">`. For non-last crumbs, add `className="hidden sm:inline-flex"` to their `<BreadcrumbItem>` and to the `<BreadcrumbSeparator />` that follows them **only when** `breadcrumbs.length > 2` — i.e. on phones show just the first and last crumb when the trail is long. If `BreadcrumbItem`/`BreadcrumbSeparator` don't accept `className` (check `resources/js/components/ui/breadcrumb.tsx`), STOP and report.

**Verify (final)**: `npm run check && npm run types:check && npm run test && npm run build` → all exit 0.

## Test plan

No unit tests — these are class changes. Manual check, if a browser is available: `composer dev` (or Herd), open Chrome devtools device mode at 375×667 and look at: a sent campaign page (all header buttons visible), campaign editor (step bar fits), an import's mapping step (dropdown visible), Settings → Two-factor recovery codes, Workspace → Members, an automation (trash icon visible), the email preview "Mobile" toggle. Then at 1280px confirm nothing changed visually. If no browser is available, say so in the report.

## Done criteria

- `npm run check`, `npm run types:check`, `npm run test`, `npm run build` all exit 0.
- `git diff --stat dev` lists only files from the in-scope list.
- `grep -n "overflow-x-clip" resources/js/layouts/app/app-sidebar-layout.tsx` still matches.
- `grep -n 'flex flex-wrap items-center gap-2' packages/core/resources/js/components/page-header.tsx` matches.
- `grep -n 'grid-cols-1 sm:grid-cols-2' resources/js/components/two-factor-recovery-codes.tsx` matches.
- `grep -c 'max-w-full' packages/core/resources/js/pages/marketing/campaigns/edit.tsx packages/core/resources/js/components/email-editors/blocks/block-editor.tsx` → at least 1 each.

## STOP conditions

- An excerpt above doesn't match the file.
- A Tailwind variant used here (`pointer-fine`, `basis-40`, `max-w-40`) is reported unknown by the build.
- Fixing a step would require touching a file outside scope.

## Maintenance notes

- Any new header-style row of buttons must use `flex-wrap`; the layout's `overflow-x-clip` hides overflow without a scrollbar.
- Plan 030 restyles cards and plan 031/032 change empty states and dialogs; none touch the lines here, but rebase if they land first.
- Known and deliberately left: the sticky editor bar is ~110px tall on phones; the automation canvas `min-h-[34rem]` is large on short screens. Both need a product decision.
