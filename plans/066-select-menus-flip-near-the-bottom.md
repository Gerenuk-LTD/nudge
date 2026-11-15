# Plan 066: Select menus open upwards when there's no room below

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving on. If
> anything in "STOP conditions" happens, stop and report. Don't improvise.
> When done, update this plan's row in `plans/README.md`, unless a reviewer
> dispatched you and told you they maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 54821b2..HEAD -- resources/js/components/ui/select.tsx`
> If it changed, compare it with the "Current state" excerpt. On a mismatch, STOP.

## Status

- **Priority**: P2 (visible UI bug on every paginated table)
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug (frontend)
- **Planned at**: commit `54821b2`, 2026-11-15

## Why this matters

The "Rows per page" picker in the shared pagination bar (`packages/core/resources/js/components/pagination.tsx`) sits at the bottom of the API log, campaigns, campaign report, contacts and suppressions pages. When the bar is near the bottom of the window, the options are cut off by the bottom of the page instead of opening upwards. The maintainer reported it on the API log page.

The cause is the shared shadcn `SelectContent` in the host app. It always opens below (`side = "bottom"`) **and** turns off Radix's collision handling (`avoidCollisions={false}`). So Radix never flips the menu above the trigger. Its `max-h-(--radix-select-content-available-height)` then squeezes it into whatever space is left below. This has been in the file since the starter kit (`72fd445`), and no caller sets `avoidCollisions`.

## Current state

`resources/js/components/ui/select.tsx:51-76`:

```tsx
function SelectContent({
  className,
  children,
  position = "popper",
  side = "bottom",
  sideOffset = 4,
  align = "center",
  ...props
}: React.ComponentProps<typeof SelectPrimitive.Content>) {
  return (
    <SelectPrimitive.Portal>
      <SelectPrimitive.Content
        ...
        position={position}
        side={side}
        sideOffset={sideOffset}
        avoidCollisions={false}
        align={align}
        {...props}
      >
```

The only `SelectContent` user is `pagination.tsx` (`grep -rln "SelectContent" resources/js packages/core/resources/js` → `select.tsx` and `pagination.tsx`). Other forms use `native-select.tsx`, which isn't affected.

This file uses double quotes and no semicolons (shadcn style), unlike the app's own files. Keep the file's style.

## Commands

From the repo root.

| Purpose  | Command               | Expected   |
| -------- | --------------------- | ---------- |
| Lint     | `npm run check`       | passes     |
| Types    | `npm run types:check` | no errors  |
| JS tests | `npm test`            | all pass   |

## Scope

**In scope:** `resources/js/components/ui/select.tsx` (the `SelectContent` props only).

**Out of scope:** `pagination.tsx`, `native-select.tsx`, `dropdown-menu.tsx` and the other `ui/` components. Also out of scope: changing `side`, `position` or the classes.

## Git workflow

- Branch off `dev`: `advisor/066-select-menus-flip`.
- One commit: `fix: select menus open upwards when there's no room below`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Let Radix avoid collisions

In `SelectContent`, add `avoidCollisions = true,` to the destructured props, after `align = "center",`. Replace `avoidCollisions={false}` with `avoidCollisions={avoidCollisions}`. Keep the prop *before* `{...props}`, as it is now. Callers can still turn it off, and the default flips the menu.

**Verify**: `grep -n "avoidCollisions" resources/js/components/ui/select.tsx` → two lines, the default `avoidCollisions = true,` and `avoidCollisions={avoidCollisions}`. No `{false}`.

### Step 2: Checks

Run Lint, Types and JS tests.

**Verify**: all pass as in the Commands table.

## Test plan

No automated test: the JS tests run without a browser layout engine, so Radix's collision handling can't be exercised there. The reviewer checks it by hand: on the API log page with more than 10 requests, scroll so the pagination bar is at the bottom of the window and open "Rows per page". All four options (10, 25, 50, 100) show, above the trigger.

## Done criteria

- [ ] `avoidCollisions` defaults to `true` in `SelectContent`, and `{false}` is gone
- [ ] `npm run check`, `npm run types:check` and `npm test` pass
- [ ] `git show --stat HEAD` lists only `resources/js/components/ui/select.tsx`
- [ ] `plans/README.md` row updated

## STOP conditions

- The drift check shows `select.tsx` changed.
- Another `SelectContent` caller appears that relies on the menu always opening below.

## Maintenance notes

- Re-adding shadcn's `select` with the CLI would overwrite this file. Keep the `avoidCollisions` default if that happens.
- New `Select` uses get the flip for free. Pass `avoidCollisions={false}` only where a menu must stay below.
