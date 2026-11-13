# Plan 043: Stat cards laid out like a dashboard: label and icon on top, number, then footer

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 520f193..HEAD -- packages/core/resources/js/components/stat-tile.tsx packages/core/resources/js/pages/marketing/home.tsx`
> Expected: no output. Otherwise check the excerpts below still match, and STOP if one doesn't.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW. Presentational only.
- **Depends on**: 042 (done)
- **Category**: design
- **Planned at**: commit `520f193`, 2026-11-13

## Why this matters

Plan 042 added icons to the stat cards. The maintainer looked at the result and said "the sizing and distribution is all wrong". The advisor screenshotted the Home page at 1440px and confirmed it:

1. **The icon squares look clunky.** `StatIcon` draws a 28px bordered tile holding a 14px glyph. The border is heavier than the glyph, and the tile pushes the label sideways.
2. **The content bunches at the top.** The label, number and detail sit in the top third of each Home card, with dead space below:
    - The first two cards reserve a 36px `Sparkline`, which draws nothing when every day is 0.
    - The other two stretch to the same row height.
3. **The headline number is undersized** (`text-2xl`), and the trend badge competes with the label in the header row.

The target is the common dashboard card:

```
┌────────────────────────────────┐
│ Subscribed contacts         👥  │  label left, plain muted icon right
│ 1,284                          │  text-3xl, tight
│                                │  (flexible space)
│ ▁▂▃▅▆▇                         │  sparkline, only when it has data
│ ↗12%  +96 added                │  footer: trend chip, then detail
└────────────────────────────────┘
```

The footer sits at the bottom of every card, so cards in a row line up whether or not they have a sparkline.

## Current state

`packages/core/resources/js/components/stat-tile.tsx`:

```tsx
export function StatTile({ label, value, hint, icon: Icon, problem = false, valueClassName }: { … }) {
    return (
        <div className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
            <dt className="flex items-center gap-2.5 text-sm text-muted-foreground">
                {Icon && <StatIcon icon={Icon} problem={problem} />}
                {label}
            </dt>
            <dd className={cn('mt-1 text-2xl font-semibold tabular-nums', problem && 'text-red-700 dark:text-red-400', valueClassName)}>
                {value}
            </dd>
            {hint && <dd className="text-xs text-muted-foreground">{hint}</dd>}
        </div>
    );
}

/** The small square an icon sits in beside a stat's label. */
export function StatIcon({ icon: Icon, problem = false }: { icon: LucideIcon; problem?: boolean }) {
    return (
        <span aria-hidden className={cn('flex size-7 shrink-0 items-center justify-center rounded-md border bg-muted/40 text-muted-foreground', problem && 'border-red-200 bg-red-50 text-red-600 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-400')}>
            <Icon className="size-3.5" />
        </span>
    );
}
```

`StatTile` is used inside `<dl>` grids on `campaigns/show.tsx` (7 per row at `lg`), `providers/show.tsx` and `imports/show.tsx`.

`packages/core/resources/js/pages/marketing/home.tsx`:

- `function Stat` (around line 481) takes `icon, label, value, detail, trend, trendLabel, points, children`.
- It renders `<Card className="gap-3 py-4"><CardContent className="space-y-1 px-5">`. Inside are a header row (`StatIcon` plus the label on the left, the trend chip `<span title=… className=…>` with the arrow and `shown` on the right), then `<p className="text-2xl font-semibold tabular-nums">{value}</p>`, then `<p className="text-xs text-muted-foreground">{detail}{delta !== null && ' · vs previous period'}</p>`.
- After `CardContent` comes `{children && <div className="px-5">{children}</div>}`.
- Callers (around lines 180–245) pass `<Sparkline data={audience.added} …/>` and `<Sparkline data={sending.sent} …/>` as children for the first two cards. `total(series)` (around line 79) sums a series.
- The four cards sit in `<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">`, whose items stretch to equal height.

`Card` (`resources/js/components/ui/card.tsx`) is `bg-card … flex flex-col gap-6 rounded-xl border py-6 shadow-xs`.

## Conventions

- Prettier style through `npm run check:fix`. Never run bare `npx prettier`.
- Icons are decorative (`aria-hidden`).

## Commands you will need

`npm run check` · `npm run types:check` · `npm run test` · `npm run build`. All must exit 0.

## Scope

**In scope:** `components/stat-tile.tsx`, `pages/marketing/home.tsx` (`Stat` and its four calls only).

**Out of scope:** the grids and column counts, icon choices, trend maths, `charts.tsx`, other pages. They pick up the `StatTile` change automatically and need no edits.

## Git workflow

Branch `advisor/043-stat-layout` from `dev`. One commit, e.g. `style(core): stat cards put the icon top right and the footer at the bottom`. Do not push.

## Steps

### Step 1: A plain icon, top right, in `StatTile`

`StatIcon` becomes a plain glyph, with no tile:

```tsx
/** A stat's icon, shown quietly at the top right of its card. */
export function StatIcon({
    icon: Icon,
    problem = false,
}: {
    icon: LucideIcon;
    problem?: boolean;
}) {
    return (
        <Icon
            aria-hidden
            className={cn(
                'size-4 shrink-0 text-muted-foreground/70',
                problem && 'text-red-600 dark:text-red-400',
            )}
        />
    );
}
```

`StatTile` becomes a vertical flex card whose label row holds the icon on the right:

```tsx
<div className="flex h-full flex-col rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
    <dt className="flex items-start justify-between gap-2 text-13 text-muted-foreground">
        <span className="min-w-0">{label}</span>
        {Icon && <StatIcon icon={Icon} problem={problem} />}
    </dt>
    <dd
        className={cn(
            'mt-2 text-2xl font-semibold tracking-tight tabular-nums',
            problem && 'text-red-700 dark:text-red-400',
            valueClassName,
        )}
    >
        {value}
    </dd>
    {hint && (
        <dd className="mt-auto pt-1 text-xs text-muted-foreground">{hint}</dd>
    )}
</div>
```

The label is allowed to wrap. In the 7-column report row, "Unsubscribed" must not be cut off. `mt-auto` on the hint pins it to the bottom, so tiles in a row line up.

**Verify**: `npm run types:check` exits 0.

### Step 2: Home `Stat` layout

Rewrite the body of `Stat`. Keep its props and the trend maths (`delta` and `shown`).

```tsx
        <Card className="h-full gap-0 py-5">
            <CardContent className="flex h-full flex-col px-5">
                <div className="flex items-start justify-between gap-2">
                    <p className="text-13 text-muted-foreground">{label}</p>
                    <StatIcon icon={icon} />
                </div>
                <p className="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{value}</p>
                <div className="mt-auto space-y-3 pt-4">
                    {children}
                    <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        {…the existing trend chip <span>, unchanged, rendered when shown !== null && delta !== null…}
                        <span>{detail}</span>
                    </p>
                </div>
            </CardContent>
        </Card>
```

- Remove the old `{children && <div className="px-5">…}` after `CardContent`, because `children` now renders inside the footer.
- Drop the `' · vs previous period'` suffix. The chip's existing `title` already says what it compares with.

At the two call sites, render each `Sparkline` only when its series has data:

- `{total(audience.added) > 0 && <Sparkline … />}`
- `{total(sending.sent) > 0 && <Sparkline … />}`

**Verify**: `npm run check:fix`, then `npm run check && npm run types:check && npm run test && npm run build`. All exit 0. `grep -n "size-7" packages/core/resources/js/components/stat-tile.tsx` gives no output.

## Test plan

No unit tests (presentational). The reviewer will screenshot Home at 1440px and 900px with headless Edge after merging. If you have a browser, check Home, a sent campaign's report at `lg` with all 7 tiles fitting, and an import with failures (a red icon and value).

## Done criteria

- All four commands exit 0.
- `git diff --stat dev` lists only the two in-scope files.

## STOP conditions

- An excerpt doesn't match.
- Removing the tile breaks a `StatIcon` caller other than `StatTile` and `home.tsx`. Check with `grep -rn "StatIcon" packages/core/resources/js`.

## Maintenance notes

- The stat card pattern is now: label and icon at the top, a big number, and a footer pinned to the bottom. New stat cards should follow it.
