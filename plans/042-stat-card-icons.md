# Plan 042: Stat cards get icons

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat d971c4b..HEAD -- packages/core/resources/js/components/stat-tile.tsx packages/core/resources/js/pages/marketing/home.tsx packages/core/resources/js/pages/marketing/campaigns/show.tsx packages/core/resources/js/pages/marketing/providers/show.tsx packages/core/resources/js/pages/marketing/imports/show.tsx`
> Expected: no output. Otherwise check the excerpts below still match, and STOP if one doesn't.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW. Presentational only.
- **Depends on**: 030 (done)
- **Category**: design
- **Planned at**: commit `d971c4b`, 2026-11-13

## Why this matters

The maintainer wants the common dashboard pattern: each stat card shows a small icon beside its label (Subscribed contacts, Emails sent, …). Today only the import results have icons, drawn as a bare `size-4` glyph. The Home dashboard cards, the campaign report's 7 tiles and the provider page's tiles have none.

All of them should use one look: a small **icon tile** before the label. That's the app's existing tile convention, `rounded-md border bg-muted/40 text-muted-foreground`, the same as the icon tiles plan 030 settled on.

```
┌──────────────────────────────┐
│ [👥] Subscribed contacts  ↗12% │
│ 1,284                         │
│ +96 added · vs previous period│
│ ▁▂▃▅▆▇ (sparkline)            │
└──────────────────────────────┘
```

## Current state

`packages/core/resources/js/components/stat-tile.tsx`. The `dt` renders the optional icon inline:

```tsx
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
```

Props include `icon?: LucideIcon` and `problem?: boolean`.

`packages/core/resources/js/pages/marketing/home.tsx`. Its local `Stat` (around line 471) takes `label, value, detail, trend, trendLabel, points, children`. Its header row is:

```tsx
                <div className="flex items-start justify-between gap-2">
                    <p className="text-13 text-muted-foreground">{label}</p>
                    {shown !== null && delta !== null && (
                        <span … trend chip …>
```

It's called four times (around lines 182–240) with `label="Subscribed contacts"`, `"Emails sent"`, `"Open rate"` and `"Click rate"`.

`StatTile` callers:

- `campaigns/show.tsx` (around line 369): `Recipients`, `Delivered`, `Clicked`, `Opened`, `Unsubscribed`, `Bounced`, `Complaints`.
- `providers/show.tsx`: `<StatTile label="Sent, last 7 days" …>` (around line 245), plus `RateStat`, a local wrapper around `StatTile` (around line 510) called with `label="Delivered"`, `"Bounced"` and `"Complaints"`.
- `imports/show.tsx`: already passes `icon={UserPlus | RefreshCw | CircleAlert}` and `problem`.

lucide-react is 0.475.0. Every icon named below exists in it: `Users`, `Send`, `MailOpen`, `MousePointerClick`, `MailCheck`, `UserMinus`, `MailX` and `ShieldAlert`.

## Conventions

- Prettier style through `npm run check:fix`. Never run bare `npx prettier`.
- Icons are decorative: `aria-hidden`. The label carries the meaning.

## Commands you will need

`npm run check` · `npm run types:check` · `npm run test` · `npm run build`. All must exit 0.

## Scope

**In scope:** `components/stat-tile.tsx`, `pages/marketing/home.tsx` (its `Stat` and its 4 calls only), and `campaigns/show.tsx`, `providers/show.tsx` and `imports/show.tsx` (icon props on stat calls only).

**Out of scope:** `contacts/show.tsx`'s `Stat` (a cell in a divided grid, different by design), layouts and grids, the trend chip, sparklines, any colours other than the `problem` red.

## Git workflow

Branch `advisor/042-stat-icons` from `dev`. One commit, e.g. `style(core): stat cards show an icon by their label`. Do not push.

## Steps

### Step 1: One icon tile style in `StatTile`

Export a small helper from `stat-tile.tsx`, so `home.tsx` can reuse it:

```tsx
/** The small square an icon sits in beside a stat's label. */
export function StatIcon({
    icon: Icon,
    problem = false,
}: {
    icon: LucideIcon;
    problem?: boolean;
}) {
    return (
        <span
            aria-hidden
            className={cn(
                'flex size-7 shrink-0 items-center justify-center rounded-md border bg-muted/40 text-muted-foreground',
                problem &&
                    'border-red-200 bg-red-50 text-red-600 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-400',
            )}
        >
            <Icon className="size-3.5" />
        </span>
    );
}
```

In `StatTile`, replace the inline `<Icon …/>` with `{Icon && <StatIcon icon={Icon} problem={problem} />}`. Change the `dt` gap to `gap-2.5`, and keep everything else.

**Verify**: `npm run types:check` exits 0.

### Step 2: Home dashboard cards

In `home.tsx`'s `Stat`:

- Add `icon: LucideIcon`, required, since all four callers get one.
- Import `StatIcon` from `@marketing/components/stat-tile`, and `LucideIcon` as a type from `lucide-react`.
- Change the label line to `<div className="flex min-w-0 items-center gap-2.5"><StatIcon icon={icon} /><p className="truncate text-13 text-muted-foreground">{label}</p></div>`.
- Change the header row's `items-start` to `items-center`, so the trend chip lines up with the tile.

Pass the icons:

- Subscribed contacts: `Users`
- Emails sent: `Send`
- Open rate: `MailOpen`
- Click rate: `MousePointerClick`

**Verify**: `npm run types:check` exits 0.

### Step 3: Report and provider tiles

`campaigns/show.tsx`:

- Recipients: `Users`
- Delivered: `MailCheck`
- Clicked: `MousePointerClick`
- Opened: `MailOpen`
- Unsubscribed: `UserMinus`
- Bounced: `MailX`
- Complaints: `ShieldAlert`

`providers/show.tsx`:

- Add an `icon: LucideIcon` prop to `RateStat` and pass it to both `StatTile`s inside it.
- Callers: Delivered gets `MailCheck`, Bounced gets `MailX`, Complaints gets `ShieldAlert`.
- The direct "Sent, last 7 days" tile gets `Send`.

`imports/show.tsx`: no change needed. It already passes icons, and it picks up the tile style automatically. Leave its `problem` prop as is.

**Verify (final)**: `npm run check:fix`, then `npm run check && npm run types:check && npm run test && npm run build`. All exit 0.

## Test plan

No unit tests (presentational). Manual, if a browser is available:

- Home: the 4 cards each show an icon tile before the label, with the trend chip still on the right.
- A sent campaign's 7 tiles each have an icon, and they still fit at `lg` (7 columns).
- The provider page's 4 tiles.
- The import results, where Failed shows the red tile when failures exist.

Check light and dark mode. Say in the report if this wasn't possible.

## Done criteria

- All four commands exit 0.
- `grep -c "icon={" packages/core/resources/js/pages/marketing/campaigns/show.tsx` is at least 7.
- `git diff --stat dev` lists only in-scope files.

## STOP conditions

- An excerpt doesn't match.
- An icon named here isn't exported by the installed lucide-react.

## Maintenance notes

- New stat cards should pass an `icon`. `StatIcon` is the one place the tile look lives.
