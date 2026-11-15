# Plan 063: The settings tab bar has no vertical scrollbar

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say "root". If anything in "STOP conditions" happens, stop
> and report. Don't improvise. Don't edit `plans/README.md`; the reviewer
> maintains it.
>
> **Drift check (run first)**:
> `git diff --stat 5616559..HEAD -- packages/core/resources/js/components/settings-shell.tsx`
> If it changed, compare it with "Current state". On a mismatch, STOP.

## Status

- **Priority**: P3 (bug reported by the maintainer, 2026-11-15)
- **Effort**: S
- **Risk**: LOW. One component's classes.
- **Depends on**: none
- **Planned at**: commit `5616559`, 2026-11-15

## Why this matters

The tab bar on settings pages (Workspace settings: General, Members, Roles, Sending, API tokens, API log) shows a vertical scrollbar, which it shouldn't have.

**Cause:**

- The `<nav>` has `overflow-x-auto`, so it can scroll sideways on phones.
- CSS then computes its `overflow-y` as `auto` too, because a box can't scroll on one axis and stay `visible` on the other.
- Each tab link has `-mb-px`, so its 2px underline overlaps the nav's 1px bottom border. That pushes the content 1px past the nav's padding box, which is enough to give it a vertical scrollbar.

**Fix:**

- Draw the bar's bottom line as an inset shadow instead of a border.
- Drop the links' negative margin.

Nothing then overflows vertically, and the active tab's underline still sits on the line, because a child's border paints over its parent's box-shadow.

## Current state

`resources/js/components/settings-shell.tsx`:

```tsx
<nav
    aria-label={title}
    className="-mx-4 flex gap-1 overflow-x-auto border-b px-4 md:mx-0 md:px-0"
>
    {tabs.map((tab) => {
        ...
        <Link
            ...
            className={cn(
                '-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                active
                    ? 'border-foreground text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground',
            )}
        >
```

The theme has a `--border` CSS variable (`resources/css/app.css` at the root defines `--color-border: var(--border)`).

## Scope

**In scope:** `resources/js/components/settings-shell.tsx`, the nav's and links' classes only.

**Out of scope:** everything else, including the tab list, the layouts and `PageHeader`.

## Git workflow

- Branch off `dev`: `advisor/063-settings-tabs-scroll`.
- One commit, `fix(core): the settings tab bar no longer scrolls vertically`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: An inset line instead of a border

- **Nav:** replace `border-b` with `overflow-y-hidden shadow-[inset_0_-1px_0_var(--border)]`. Keep `-mx-4 flex gap-1 overflow-x-auto px-4 md:mx-0 md:px-0`. `overflow-y-hidden` is a safety net, so a stray pixel can never bring the scrollbar back.
- **Links:** remove `-mb-px`. Keep everything else, including `border-b-2` and the active and inactive colours.

Check that Tailwind accepts the arbitrary shadow: the class must appear in the built CSS. Run `npm run build` at the root, then `grep -c "inset 0 -1px 0 var(--border)" public/build/assets/*.css`; it should give at least one match. If the build output is somewhere else, find it in `vite.config.ts`. Don't commit build output (it's gitignored).

**Verify** (root): `npm run types:check`, `npm run check` (format with `npx vp fmt packages/core/resources/js/components/settings-shell.tsx` if needed), `npm run test` → pass. `git diff --stat dev` → exactly one file.

### Step 2: Look at it

If you can run the app, open Workspace settings and Account settings at desktop width and at phone width (375px):

- no vertical scrollbar on the tab bar;
- the grey line under the tabs is still there;
- the active tab's dark underline sits on it;
- the tabs still scroll sideways at phone width.

If you can't open a browser, say so in your report instead of claiming you checked.

## Done criteria

- [ ] The nav uses an inset-shadow line and `overflow-y-hidden`; the links have no `-mb-px`
- [ ] Only `settings-shell.tsx` changed; `npm run check`, `types:check` and `test` pass

## STOP conditions

- The drift check shows `settings-shell.tsx` changed since `5616559`.
- Other files turn out to render their own copy of this tab bar (`grep -rn "overflow-x-auto border-b" resources/js ../../resources/js`). Report them rather than editing them.

## Maintenance notes

Anything that adds a bottom border to the tab links must keep it inside the link (no negative margins), or the scrollbar comes back.
