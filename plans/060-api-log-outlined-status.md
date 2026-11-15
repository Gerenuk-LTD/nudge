# Plan 060: The API log's status column becomes an outlined badge

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say "root". If anything in "STOP conditions" happens, stop
> and report. Don't improvise. Don't edit `plans/README.md`; the reviewer
> maintains it.
>
> **Drift check (run first)**:
> `git diff --stat 519a6a2..HEAD -- packages/core/resources/js/pages/marketing/workspace/api-log.tsx`
> If it changed, compare it with "Current state". On a mismatch, STOP.

## Status

- **Priority**: P3 (requested by the maintainer, 2026-11-15)
- **Effort**: S
- **Risk**: LOW. One cell in one page.
- **Depends on**: 058 (done)
- **Category**: direction (UI)
- **Planned at**: commit `519a6a2`, 2026-11-15

## Why this matters

The maintainer wants the **API log's** status shown as an outlined badge:

- a thin neutral outline;
- normal text;
- the colour only on the dot.

This is for the API log only. Plan 059 wrongly applied the style to the shared `StatusBadge`, which every page uses, and it has been reverted (`519a6a2`). **Don't touch `components/status-badge.tsx`.**

```
┌───────┐  ┌───────┐  ┌───────┐
│ ● 200 │  │ ● 422 │  │ ● 500 │     outline: border token, text: foreground
└───────┘  └───────┘  └───────┘     dot: green / amber / red (statusDot)
```

## Current state

`resources/js/pages/marketing/workspace/api-log.tsx`, the `status` column (around line 160):

```tsx
cell: ({ row }) => (
    <Tooltip>
        <TooltipTrigger asChild>
            <span tabIndex={0} className="flex items-center gap-2">
                <span
                    aria-hidden
                    className={cn('size-1.5 rounded-full', statusDot(row.original.status))}
                />
                {row.original.status}
            </span>
        </TooltipTrigger>
        <TooltipContent>
            {row.original.status} · {statusText(row.original.status)}
        </TooltipContent>
    </Tooltip>
),
```

`statusDot(status)` returns the green, amber or red dot classes.

## Scope

**In scope:** the status cell in `api-log.tsx` only.

**Out of scope:** `components/status-badge.tsx` and every other page; the method badge; the tooltip text.

## Git workflow

- Branch off `dev`: `advisor/060-api-log-outlined-status`.
- One commit, `style(core): outline the API log's status`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: The outlined status

Change only the trigger span's classes, from `flex items-center gap-2` to:

`inline-flex w-fit items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium tabular-nums text-foreground ring-1 ring-border ring-inset`

Keep:

- `tabIndex={0}`;
- the tooltip;
- the dot (`size-1.5 rounded-full` + `statusDot(...)`, `aria-hidden`);
- the code as the text.

**Verify** (root):

- `npm run types:check` → passes;
- `npm run check` → passes (format the file with `npx vp fmt packages/core/resources/js/pages/marketing/workspace/api-log.tsx` if needed);
- `npm run test` → passes;
- `git diff --stat dev` → exactly one file, `api-log.tsx`.

## Done criteria

- [ ] The API log status cell is an outlined badge with the coloured dot and the code
- [ ] Only `api-log.tsx` changed; `status-badge.tsx` untouched
- [ ] `npm run check`, `types:check` and `test` pass

## STOP conditions

- The drift check shows `api-log.tsx` changed since `519a6a2`.
- The change seems to need edits to any other file.

## Maintenance notes

If other pages later want this outlined look, make it an explicit variant (such as `<StatusBadge variant="outline">`) rather than changing the default.
