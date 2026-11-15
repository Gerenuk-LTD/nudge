# Plan 059: Outlined status badges (grey outline, coloured dot)

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say "root". If anything in "STOP conditions" happens, stop
> and report. Don't improvise. When done, update this plan's row in
> `plans/README.md`, unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat f74e29d..HEAD -- packages/core/resources/js/components/status-badge.tsx`
> If it changed, compare it with "Current state". On a mismatch, STOP.

## Status

- **Priority**: P3 (design change requested by the maintainer, 2026-11-15)
- **Effort**: S
- **Risk**: LOW. One component changes, and the 18 files that use it pick it up without changes.
- **Depends on**: none
- **Category**: direction (UI)
- **Planned at**: commit `f74e29d`, 2026-11-15

## Why this matters

The maintainer wants status badges outlined instead of tinted. They chose the version where only the dot carries colour:

- no background fill;
- a thin neutral outline;
- the normal text colour;
- the status colour only on the dot.

It reads calmer in busy tables and looks the same in light and dark mode.

```
┌──────────────┐  ┌──────────────┐  ┌──────────┐
│ ● Sent       │  │ ● Scheduled  │  │ ● Failed │
└──────────────┘  └──────────────┘  └──────────┘
  outline: border token, text: foreground, dot: green / sky / red
```

## Current state

`resources/js/components/status-badge.tsx`:

- exports `StatusTone` (`neutral | info | success | warning | danger`);
- has a `tones` map of `{ badge, dot }` class strings per tone. `badge` is a tinted background plus a tinted text colour, such as `bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400`;
- exports `StatusBadge({ tone, live, children })`, which renders a `rounded-md px-2 py-0.5 text-xs font-medium` span, then the dot (plus a pinging copy when `live`), then the children.

Its docblock says "in the flat-with-dot style of Tailwind's own badges".

It's used through `campaign-status-badge.tsx`, `contact-status-badge.tsx`, `import-status-badge.tsx`, `contact-preview.tsx`, `automations/edit.tsx` and others (`grep -rln StatusBadge resources/js`). None of them pass classes; it takes no `className`.

The theme has a `border` colour token (`--color-border`, so `ring-border` and `border-border` work) and `text-foreground`.

## Commands

All from the root:

| Purpose  | Command                                                | Expected |
| -------- | ------------------------------------------------------ | -------- |
| Frontend | `npm run check`, `npm run types:check`, `npm run test` | pass     |

## Scope

**In scope:** `resources/js/components/status-badge.tsx` only.

**Out of scope:**

- The shadcn `Badge` (`resources/js/components/ui/badge.tsx`), which is used for other labels such as "Built-in" in plan 057.
- The API log's method badge and status dots.
- The callers of `StatusBadge`.

## Git workflow

- Branch off `dev`: `advisor/059-outlined-status-badges`.
- One commit, `style(core): outline the status badges`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Outline instead of tint

In `status-badge.tsx`:

- **The `tones` map** becomes dot colours only: `Record<StatusTone, string>`, keeping today's dot classes:

    | Tone      | Dot                                  |
    | --------- | ------------------------------------ |
    | `neutral` | `bg-zinc-400`                        |
    | `info`    | `bg-sky-500 dark:bg-sky-400`         |
    | `success` | `bg-emerald-500 dark:bg-emerald-400` |
    | `warning` | `bg-amber-500 dark:bg-amber-400`     |
    | `danger`  | `bg-red-500 dark:bg-red-400`         |

- **The outer span's classes:**
  `inline-flex w-fit shrink-0 items-center gap-x-1.5 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap text-foreground ring-1 ring-border ring-inset`.

    Use `ring-inset` rather than `border`, so the badge keeps its current size and table rows don't shift.

- **Both dot spans** (the pinging one and the solid one) use `tones[tone]` in place of `tones[tone].dot`.

- **The docblocks:** replace "Tinted background, text and dot per tone, in the flat-with-dot style of Tailwind's own badges" with "The dot's colour per tone. The badge itself is a neutral outline, so only the dot carries the status." Replace "A small tinted status label" with "A small outlined status label".

Keep the props, the `live` behaviour and the export names unchanged.

**Verify** (root): `npm run types:check` → passes; `npm run check` → passes (run `npx vp fmt packages/core/resources/js/components/status-badge.tsx` first if formatting fails); `npm run test` → passes.

### Step 2: Look at it

If you can run the app (`composer run dev` at the root), open the campaigns list and a contact page. The badges should be outlined with coloured dots, in both light and dark mode, and a sending campaign's dot should still pulse. If you can't open a browser, say so in your report instead of claiming you checked.

## Done criteria

- [ ] `StatusBadge` has no background fill, a `ring-1 ring-inset ring-border` outline, `text-foreground` text, and tone colour only on the dot
- [ ] Props, `live` and exports unchanged; no caller edited
- [ ] `npm run check`, `types:check` and `npm run test` pass
- [ ] `plans/README.md` row updated

## STOP conditions

- The drift check shows `status-badge.tsx` changed since `f74e29d`.
- A caller depends on the `tones` map's old shape (`grep -rn "tones\[" resources/js`), which would mean it's exported or imported somewhere else.

## Maintenance notes

- New tones only need a dot colour.
- If a badge ever needs more emphasis (an error that must stand out), add an explicit prop rather than bringing back tinted backgrounds for all of them.
