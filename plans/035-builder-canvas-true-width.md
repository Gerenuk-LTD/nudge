# Plan 035: The email builder's canvas shows the email at its real width; the Width setting explains itself in a tooltip

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 2ff0f57..HEAD -- packages/core/resources/js/components/email-editors/blocks/block-editor.tsx packages/core/resources/js/components/email-editors/blocks/inspector.tsx`
> Expected: no output, or only plan 033's changes. Check that the excerpts below still match, and STOP if one doesn't.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW. Editor display only. The rendered email HTML (`render.ts`) is not touched.
- **Depends on**: none
- **Category**: UX bug
- **Planned at**: commit `2ff0f57`, 2026-11-12

## Why this matters

The drag-and-drop builder has an email-level **Width** slider (480–720px, default 600). It sets the email's maximum width on large screens. In the sent email, narrower screens get a 100% width and stacked columns through a media query in `blocks/render.ts`.

In the editing view, the canvas sits between two side panels. The canvas only uses the width as a **maximum**, so when the middle column is narrower than the chosen width (about 600px on a typical laptop), moving the slider changes nothing. The maintainer saw this and assumed the template "doesn't update". The canvas should draw the email at exactly the chosen width and scroll sideways when there isn't room.

The maintainer also wants the slider explained, **in a tooltip, not as text under the field**.

## Current state

`packages/core/resources/js/components/email-editors/blocks/block-editor.tsx`, the edit canvas (around lines 397–425):

```tsx
                            <div
                                className="overflow-x-auto px-4 py-8"
                                style={{
                                    background: document.settings.backdrop,
                                }}
                                onMouseDown={(event) => {
                                    if (event.target === event.currentTarget) {
                                        setSelectedId(null);
                                    }
                                }}
                            >
                                <div
                                    className="mx-auto rounded-lg py-4 shadow-xs"
                                    style={{
                                        maxWidth: document.settings.width,
                                        background: document.settings.canvas,
                                    }}
                                >
                                    <BlockList … />
                                </div>
                            </div>
```

`packages/core/resources/js/components/email-editors/blocks/inspector.tsx`:

- The email settings panel (around line 74):
    ```tsx
    <RangeField
        label="Width"
        value={document.settings.width}
        min={480}
        max={720}
        step={10}
        unit="px"
        onChange={(width) => onSettingsChange({ width })}
    />
    ```
- `Field` (around line 348) takes `{ label, htmlFor?, hint?, children }` and renders `<Label className="text-13">{label}</Label>`, then the children, then the optional hint `<p>`.
- `RangeField` (around line 402) takes `{ label, value, min, max, step, unit, onChange }` and wraps its range input in `<Field label={label}>`.

The tooltip pattern used in core, from `packages/core/resources/js/components/sending-status.tsx`:

```tsx
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
…
            <Tooltip>
                <TooltipTrigger asChild>
                    …trigger element…
                </TooltipTrigger>
                <TooltipContent side="right">{issue.title}</TooltipContent>
            </Tooltip>
```

The app root already wraps everything in `<TooltipProvider delayDuration={0}>` (`resources/js/app.tsx`).

## Commands you will need

`npm run check && npm run types:check && npm run test && npm run build`, all exit 0. If `npm run check` flags only formatting in `plans/*.md`, that failure was already there; note it and carry on.

## Scope

**In scope:** `block-editor.tsx` (the canvas inner `div` only) and `inspector.tsx` (`Field`, `RangeField`, and the email Width `RangeField` call).
**Out of scope:** `render.ts` and everything else that affects the sent HTML; the Desktop/Mobile preview iframes; other inspector fields (don't add tips to them).

## Git workflow

Branch `advisor/035-builder-width` from `dev`, one commit per step, conventional commits (e.g. `fix(core): builder canvas shows the email at its real width`). Do not push.

## Steps

### Step 1: The canvas uses the real width

In `block-editor.tsx`, on the inner canvas `div`, replace `maxWidth: document.settings.width,` with `width: document.settings.width,`. Leave everything else as it is. The outer `div` already has `overflow-x-auto`, so a canvas wider than its column scrolls instead of squashing.

Check that the image sizing still uses `available={contentWidth(document.settings)}`. It does, so it now matches what's drawn.

**Verify**: `npm run types:check` → exit 0.

### Step 2: An optional tooltip on `Field` and `RangeField`

1. Add `tip?: ReactNode` to both prop types. `RangeField` passes it through to `Field`.
2. In `Field`, when `tip` is set, render the label inside a row: `<div className="flex items-center gap-1.5">`, then the existing `<Label>`, then:

```tsx
<Tooltip>
    <TooltipTrigger asChild>
        <button
            type="button"
            aria-label={`About ${label.toLowerCase()}`}
            className="rounded-full text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            <CircleHelp className="size-3.5" />
        </button>
    </TooltipTrigger>
    <TooltipContent side="top" className="max-w-60">
        {tip}
    </TooltipContent>
</Tooltip>
```

Import `CircleHelp` from `lucide-react` (add it to the existing import), and `Tooltip`, `TooltipContent`, `TooltipTrigger` from `@/components/ui/tooltip`. Without `tip`, `Field` renders exactly as before. 3. On the email Width `RangeField` call, add:
`tip="The widest the email gets, on large screens. On phones it shrinks to fit and columns stack. Use Desktop and Mobile above to see the result."`

**Verify (final)**: `npm run check && npm run types:check && npm run test && npm run build` → exit 0.

## Test plan

There are no component tests, and none should be added. Manual check, if a browser is available: open a template in the builder at about 1280px wide. Drag Width from 480 to 720. The canvas visibly grows the whole way, and above the space available it scrolls sideways. Hover or keyboard-focus the `?` next to Width and the tooltip appears. Desktop and Mobile previews look the same as before. Say in the report if you couldn't do this check.

## Done criteria

- All gates exit 0.
- `grep -n "maxWidth: document.settings.width" packages/core/resources/js/components/email-editors/blocks/block-editor.tsx` → no output.
- `grep -n "width: document.settings.width" packages/core/resources/js/components/email-editors/blocks/block-editor.tsx` → one match.
- `grep -c "tip=" packages/core/resources/js/components/email-editors/blocks/inspector.tsx` → 1 (only the Width call site).
- `git diff --stat dev` lists only the two in-scope files.

## STOP conditions

- An excerpt doesn't match.
- `@/components/ui/tooltip` doesn't export `Tooltip`, `TooltipContent` and `TooltipTrigger`.

## Maintenance notes

- On phones and narrow windows, the builder now scrolls the canvas sideways instead of squeezing it. That's deliberate, because the Mobile preview is where to check the phone layout.
- Radix tooltips don't open on tap on touch screens. On phones the tip is reachable by keyboard focus only. If the maintainer wants tips readable on touch, a click-to-open popover is the follow-up.
- `Field`'s `tip` is now how inspector controls explain themselves, instead of text under the field.
