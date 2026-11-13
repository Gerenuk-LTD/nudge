# Plan 041: Variables look like the rest of the Content step

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat db0c7c3..HEAD -- packages/core/resources/js/components/campaign-variables.tsx packages/core/resources/js/lib/merge-tags.ts packages/core/resources/js/lib/merge-tags.test.ts packages/core/resources/js/components/email-envelope.tsx`
> Expected: no output. Otherwise check the excerpts below still match, and STOP if one doesn't.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW. A presentational rewrite of one component. Props, saving and sending don't change.
- **Depends on**: 040 (done)
- **Category**: design
- **Planned at**: commit `db0c7c3`, 2026-11-13

## Why this matters

The maintainer says the Variables card "still looks bolted on, doesn't match the design we have and just looks confusing". They've given the advisor free rein. Here's what's wrong with it today:

- **It speaks code.** Each row is labelled `{{ promo_code }}` in monospace, with an amber dot and an "optional" tag. Nothing else on the page looks like that.
- **It's a third design language.** Its header is a fold-up toggle with a chevron and a status count. The rest of the Content step uses two patterns. One is the **envelope card** (From / Subject / Preview text): quiet labels in a fixed-width column on the left, with **borderless inputs** inline, in one divided card. The other is the **Options card**: a header with a title and a one-line muted description, then divided rows.
- **Folding hides the very thing the checklist then complains about.**

The new design reuses those two patterns exactly. Any tag that isn't a contact field becomes a row that reads like a form label, such as "Promo code", with a borderless input. A fallback shows as the placeholder, so the person can see what happens if they leave it empty.

```
┌──────────────────────────────────────────────────────────────────┐
│ Variables                                       2 to fill in     │  ← Options-style header
│ Filled in the same for everyone who gets this campaign.          │
├──────────────────────────────────────────────────────────────────┤
│ Promo code        SPRING25                                       │  ← envelope-style rows
│ Event date        Needed before sending                          │  ← placeholder, amber
│ Venue             TBC if left empty                              │  ← fallback as placeholder
│ Host name         Needed before sending                          │
└──────────────────────────────────────────────────────────────────┘
```

Hovering a label shows the tag it fills (`{{ event_date }}`), so the link to the email is one hover away and not in the person's face.

## Current state

`packages/core/resources/js/components/campaign-variables.tsx` (161 lines) exports:

```tsx
export function CampaignVariables({
    variables,
    values,
    onChange,
    errors,
}: {
    variables: { tag: string; optional: boolean }[];
    values: Record<string, string>;
    onChange: (values: Record<string, string>) => void;
    errors: Record<string, string | undefined>;
}) {
```

It's built on `Collapsible`, with a `CircleHelp` tooltip, an amber dot, and `Input` (bordered) rows. Its only caller is `pages/marketing/campaigns/edit.tsx`:

```tsx
{
    variables.length > 0 && (
        <CampaignVariables
            variables={variables}
            values={form.data.variables}
            onChange={(values) => form.setData('variables', values)}
            errors={errors}
        />
    );
}
```

`packages/core/resources/js/lib/merge-tags.ts`:

```ts
const PATTERN = /\{\{\s*([a-z][a-z0-9_.]*)\s*(?:\|\s*([^}]*?))?\s*\}\}/gi;

/** Campaign variables in these texts, in first-seen order: tags that aren't built in or custom fields. */
export function campaignVariables(
    texts: string[],
    builtIn: string[],
): { tag: string; optional: boolean }[] {
    const found = new Map<string, boolean>();

    for (const text of texts) {
        for (const match of text.matchAll(PATTERN)) {
            const tag = match[1].toLowerCase();

            if (builtIn.includes(tag) || tag.startsWith('custom.')) {
                continue;
            }

            found.set(
                tag,
                (found.get(tag) ?? false) || (match[2] ?? '').trim() !== '',
            );
        }
    }

    return [...found].map(([tag, optional]) => ({ tag, optional }));
}
```

The file also has `fillVariables` (leave it alone). Tests are in `merge-tags.test.ts`. Some use `toEqual([{ tag: 'code', optional: false }])`, and those must be updated for the new `fallback` field.

**The two patterns to copy.** These are the exemplars, so read both files before writing anything.

1. `packages/core/resources/js/components/email-envelope.tsx`:
    - The card is `divide-y overflow-hidden rounded-xl border bg-card shadow-xs`.
    - Each row is `px-4 py-1 sm:px-5` containing `flex items-center gap-3`.
    - The label is `w-24 shrink-0 text-13 text-muted-foreground`.
    - The input uses the exported `envelopeInput` class (`h-10 min-w-0 bg-transparent text-sm outline-none placeholder:text-muted-foreground/60 …`) plus `flex-1 font-medium placeholder:font-normal`.
    - The error is `<InputError message={row.error} className="pb-2 sm:pl-27" />`.
2. The Options card in `edit.tsx` (`function CampaignOptions`):
    - `<section className="overflow-hidden rounded-xl border bg-card shadow-xs">`.
    - `<header className="space-y-1 border-b px-5 py-4 sm:px-6"><h2 className="text-base font-semibold">Options</h2><p className="text-sm text-muted-foreground">Tracking, testing and the plain-text version.</p></header>`.

## Conventions

- Prettier style through `npm run check:fix`. Never run bare `npx prettier`.
- Explain controls in tooltips, not notes under fields. That's the maintainer's preference.
- Import `envelopeInput` from `@marketing/components/email-envelope`, `InputError` from `@/components/input-error`, tooltip parts from `@/components/ui/tooltip`, and `cn` from `@/lib/utils`.

## Commands you will need

`npm run check` · `npm run types:check` · `npm run test` · `npm run build`. All must exit 0.

## Scope

**In scope:** `components/campaign-variables.tsx` (rewrite), `lib/merge-tags.ts` (`campaignVariables` only) and `lib/merge-tags.test.ts`, and in `edit.tsx` only the `<CampaignVariables … />` call if its props change.

**Out of scope:** `email-envelope.tsx` (import from it, don't edit it), `fillVariables`, any PHP, the checklist, the previews.

## Git workflow

Branch `advisor/041-variables-design` from `dev`. One commit, e.g. `style(core): variables read like the rest of the content step`. Do not push.

## Steps

### Step 1: Know each variable's fallback

Change `campaignVariables` to return `{ tag: string; optional: boolean; fallback: string | null }[]`.

- `fallback` is the first non-empty trimmed fallback text seen for that tag, else `null`.
- `optional` stays `fallback !== null`. Keep the field, because other code reads it.
- Keep first-seen order.

Update the existing tests' expected objects to include `fallback`, and add one test: `campaignVariables(['{{ venue | TBC }}', '{{ venue }}'], builtIn)` gives `[{ tag: 'venue', optional: true, fallback: 'TBC' }]`.

**Verify**: `npm run test` passes, and `npm run types:check` exits 0. If another caller breaks on the new field, it won't, because adding a field is compatible. STOP if it does.

### Step 2: Rewrite `CampaignVariables`

Same props as today, except `variables` is now the Step 1 shape. No `Collapsible`, no chevron, no amber dot and no "optional" tag.

```tsx
/** Turns a variable's tag into a label people read: promo_code → Promo code. */
function labelFor(tag: string): string {
    const words = tag.replace(/[._]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}
```

Structure:

```tsx
<section className="overflow-hidden rounded-xl border bg-card shadow-xs">
    <header className="flex items-start justify-between gap-4 border-b px-5 py-4 sm:px-6">
        <div className="space-y-1">
            <h2 className="text-base font-semibold">Variables</h2>
            <p className="text-sm text-muted-foreground">
                Filled in the same for everyone who gets this campaign.
            </p>
        </div>
        {missing.length > 0 && (
            <span className="shrink-0 pt-0.5 text-13 font-medium text-amber-700 dark:text-amber-400">
                {missing.length} to fill in
            </span>
        )}
    </header>
    <div className="max-h-[30rem] divide-y overflow-y-auto">
        {variables.map(…row…)}
    </div>
    {errors.variables && (
        <InputError message={errors.variables} className="border-t px-5 py-2.5 sm:px-6" />
    )}
</section>
```

`missing` means required (no fallback) with an empty trimmed value.

Each row copies the envelope line exactly, with a wider label column:

```tsx
<div key={tag} className="px-4 py-1 sm:px-6">
    <div className="flex items-center gap-3">
        <Tooltip>
            <TooltipTrigger asChild>
                <label
                    htmlFor={`variable-${tag}`}
                    className="w-32 shrink-0 truncate text-13 text-muted-foreground sm:w-40"
                >
                    {labelFor(tag)}
                </label>
            </TooltipTrigger>
            <TooltipContent
                side="top"
                className="font-mono text-xs"
            >{`{{ ${tag} }}`}</TooltipContent>
        </Tooltip>
        <input
            id={`variable-${tag}`}
            value={values[tag] ?? ''}
            placeholder={
                fallback !== null
                    ? `${fallback} if left empty`
                    : 'Needed before sending'
            }
            aria-invalid={errors[`variables.${tag}`] ? true : undefined}
            onChange={(event) =>
                onChange({ ...values, [tag]: event.target.value })
            }
            className={cn(
                envelopeInput,
                'flex-1 font-medium placeholder:font-normal',
                fallback === null &&
                    'placeholder:text-amber-700/70 dark:placeholder:text-amber-400/70',
            )}
        />
    </div>
    <InputError
        message={errors[`variables.${tag}`]}
        className="pb-2 pl-35 sm:pl-43"
    />
</div>
```

The error indent should line up with the input: label width plus the gap. Use `pl-35 sm:pl-43`, which works out to 8.75rem and 10.75rem at the label's two widths. If that doesn't line up neatly, use `pl-[8.75rem] sm:pl-[10.75rem]`. Keep it simple, and say what you chose.

**Verify**: `npm run check:fix`, then `npm run check && npm run types:check && npm run test && npm run build`. All exit 0. `grep -n "Collapsible\|font-mono text-13\|rounded-full bg-amber" packages/core/resources/js/components/campaign-variables.tsx` gives no output.

## Test plan

- Unit tests: the Step 1 tests.
- Manual, if a browser is available. On a draft with `{{ promo_code }}`, `{{ venue | TBC }}` and `{{ event_date }}`:
    - The card reads as a sibling of the envelope and Options cards.
    - Labels read "Promo code", "Venue" and "Event date". Hovering one shows its tag.
    - The empty required rows show "Needed before sending", and the header says "2 to fill in".
    - Venue shows "TBC if left empty".
- Say in the report if this wasn't possible.

## Done criteria

- All four commands exit 0.
- `git diff --stat dev` lists only in-scope files.
- `grep -n "envelopeInput" packages/core/resources/js/components/campaign-variables.tsx` finds a match.

## STOP conditions

- An excerpt doesn't match.
- `envelopeInput` isn't exported from `email-envelope.tsx`.

## Maintenance notes

- Variables now share the envelope's look. If the envelope's row style changes, change this card to match.
- `labelFor` is display-only. The tag is still the key everywhere.
