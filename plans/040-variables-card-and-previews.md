# Plan 040: A compact Variables card, and previews that show the values

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 98cd925..HEAD -- packages/core/resources/js/pages/marketing/campaigns/edit.tsx packages/core/resources/js/lib/merge-tags.ts packages/core/resources/js/components/email-editors`
> Expected: no output. If a file changed, check the excerpts below still match, and STOP if one doesn't.

## Status

- **Priority**: P1
- **Effort**: S–M
- **Risk**: LOW. Frontend only. Previews change; what's saved and sent does not.
- **Depends on**: 037 (done), 039 (done)
- **Category**: UX + bug
- **Planned at**: commit `98cd925`, 2026-11-13

## Why this matters

Campaign variables (plan 037) let a campaign give a value to any tag that isn't a contact detail or a custom field, such as `{{ promo_code }}`. The server fills these in when it snapshots the campaign for sending and for test sends (`MergeTags::fill` in `PrepareCampaign` and `SendTestEmail`). The maintainer has two complaints:

1. **The Variables card looks poor and grows without limit.** Each variable is a full-width label with a full-width input underneath, so 15 variables make the Content step very long. The maintainer chose this layout:
    - One tight row per variable: the tag on the left, the input on the right.
    - A header that counts how many still need a value.
    - The card folds to one line once every variable has a value.
    - It scrolls inside itself past about 8 rows.
2. **Previews ignore the values.** The builder's Desktop/Mobile view, the HTML editor's live preview and the Review step all render the raw HTML. So `{{ promo_code }}` still shows as a tag even after a value is typed, and it looks as if the email will go out with tags in it. It won't: the send fills them. The previews must show what will be sent.

## Current state

`packages/core/resources/js/lib/merge-tags.ts`. The whole file:

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

Its tests are in `packages/core/resources/js/lib/merge-tags.test.ts` (Vitest, `describe` / `it` / `expect`, with `const builtIn = ['email', 'first_name', 'unsubscribe_url'];` at the top). Match that style.

The server's fill (`packages/core/src/Sending/MergeTags.php`), which the preview must mirror:

```php
    public static function fill(string $template, array $values, bool $escapeHtml): string
    {
        return (string) preg_replace_callback(self::PATTERN, function (array $match) use ($values, $escapeHtml): string {
            $tag = strtolower($match[1]);
            $value = $values[$tag] ?? null;

            if ($value === null || $value === '' || in_array($tag, self::BUILT_IN, true) || str_starts_with($tag, 'custom.')) {
                return $match[0];
            }

            return $escapeHtml ? e($value) : $value;
        }, $template);
    }
```

The whole tag, including any `| fallback`, is replaced by the value. A tag with no value is left alone, so sending fills its fallback per recipient.

`packages/core/resources/js/pages/marketing/campaigns/edit.tsx`, in the Content step (around line 479):

```tsx
const variables = campaignVariables(
    [
        form.data.subject,
        form.data.preheader,
        form.data.html,
        ...(form.data.ab_enabled
            ? [form.data.ab_subject_b, form.data.ab_html_b]
            : []),
    ],
    props.builtInTags,
);
```

The card, around line 694. It's the whole `{variables.length > 0 && ( <div className="rounded-xl border bg-card shadow-xs"> … )}` block. It contains:

- a header row with "Variables" and a `CircleHelp` tooltip ("Values that are the same for everyone in this campaign. Any tag that isn't a contact detail or custom field becomes a variable."),
- one `<div className="grid gap-1.5">` per variable, with a `<Label>` showing `{{ tag }}` plus "optional", and an `<Input id={`variable-${tag}`} value={form.data.variables[tag] ?? ''} onChange={… form.setData('variables', { ...form.data.variables, [tag]: event.target.value })} />`,
- `<InputError message={errors.variables} />`.

The Content step's `EmailContentEditor` (around line 604) and the A/B version B one (around line 1007) are both passed `content`, `mergeTags`, `onChange`, and optionally `actions`.

Review step (around line 1545 and 1615): the Subject row shows `form.data.subject`, the Preview text row shows `form.data.preheader`, and the preview is `<iframe title="Email preview" sandbox="" srcDoc={form.data.html} … />`.

Errors: `SHOWN_ERROR_KEYS` (around line 405) contains `'variables'`. `hiddenErrors` (around line 245) lists every error whose key isn't in that set, so a per-value error like `variables.promo_code` (a value over 1000 characters) currently shows in the generic alert at the top.

Editors:

- `packages/core/resources/js/components/email-editors/types.ts`: `EmailEditorProps` has `html`, `state`, `mergeTags`, `onChange(html, state, initial?)` and `syncOnMount?`.
- `packages/core/resources/js/components/email-editors/blocks/block-editor.tsx` (around line 332): `const rendered = useMemo(() => (mode === 'edit' ? '' : renderEmail(document)), [mode, document]);`. That's used as `srcDoc={rendered}` on the preview iframe (around line 476).
- `packages/core/resources/js/components/email-editors/html-editor.tsx` (around line 61): the live preview iframe uses `srcDoc={html}`.
- `packages/core/resources/js/components/email-editors/content-editor.tsx`: `EmailContentEditor` renders `<Editor key={editorKey} html=… state=… syncOnMount=… mergeTags=… onChange=… />`.

Available UI: `@/components/ui/collapsible` (`Collapsible`, `CollapsibleTrigger`, `CollapsibleContent`, Radix), `@/components/ui/tooltip`, `@/components/ui/input`, and `ChevronDown` and `CircleHelp` from `lucide-react`.

## Conventions

- Prettier style through `npm run check:fix`. Never run bare `npx prettier`.
- Core components live in `packages/core/resources/js/components/<kebab-name>.tsx`, export named functions, and carry a one-line JSDoc. Siblings are imported as `@marketing/components/<name>`, and `@/…` resolves to the host app (`@/components/ui/*`, `@/lib/utils`).
- Explain controls in tooltips, not notes under fields. The maintainer's standing preference.
- Cards: `rounded-xl border bg-card shadow-xs`; header row `border-b px-5 py-3.5`; rows `divide-y`; muted text `text-muted-foreground`; small text `text-13`.

## Commands you will need

`npm run check` · `npm run types:check` · `npm run test` · `npm run build`. All must exit 0.

## Scope

**In scope:** `lib/merge-tags.ts` and its test, new `components/campaign-variables.tsx`, `pages/marketing/campaigns/edit.tsx` (the Variables card, the `hiddenErrors` filter, both `EmailContentEditor` usages, and the Review step's Subject, Preview text and iframe), `email-editors/types.ts`, `email-editors/content-editor.tsx`, `email-editors/blocks/block-editor.tsx` (the `rendered` memo only), `email-editors/html-editor.tsx` (the preview `srcDoc` only).

**Out of scope:** any PHP; what's saved or sent (`onChange` output must stay the raw HTML with tags); `templates/form.tsx` (templates have no variables); contact tags like `{{ first_name }}`, which keep showing as tags in previews because they differ per person.

## Git workflow

Branch `advisor/040-variables-card` from `dev`. Conventional commits, e.g. `feat(core): compact Variables card` and `fix(core): previews show campaign variable values`. Do not push.

## Steps

### Step 1: A preview fill that matches the server

Add to `lib/merge-tags.ts`:

```ts
const ESCAPES: Record<string, string> = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
};

/**
 * Puts campaign variable values into a text the way sending does, for previews. Tags without a value are left as they
 * are, and so are built-in and custom field tags, which differ per person.
 */
export function fillVariables(
    text: string,
    values: Record<string, string>,
    { escapeHtml }: { escapeHtml: boolean },
): string {
    return text.replace(PATTERN, (whole, name: string) => {
        const tag = name.toLowerCase();
        const value = values[tag];

        if (!value || tag.startsWith('custom.')) {
            return whole;
        }

        return escapeHtml
            ? value.replace(/[&<>"']/g, (c) => ESCAPES[c])
            : value;
    });
}
```

`PATTERN` has the `g` flag, so `replace` replaces every match. Built-in tags can never be keys in `values`, because the server rejects them.

Add tests in `merge-tags.test.ts`, in a `describe('fillVariables', …)`:

- `fillVariables('Use {{ promo_code }} now', { promo_code: 'SPRING' }, { escapeHtml: false })` gives `'Use SPRING now'`.
- A tag with a fallback is replaced whole: `'{{ code | none }}'` with `{ code: 'X' }` gives `'X'`.
- A missing or empty value leaves the tag: `'{{ code }}'` with `{}` and with `{ code: '' }` both give `'{{ code }}'`.
- `{{ first_name }}` with `{}` is left alone.
- Escaping: `'<p>{{ v }}</p>'` with `{ v: '<b>&' }` and `escapeHtml: true` gives `'<p>&lt;b&gt;&amp;</p>'`.
- Case: `'{{ Promo_Code }}'` with `{ promo_code: 'A' }` gives `'A'`.

**Verify**: `npm run test` shows the new tests passing.

### Step 2: Editors can preview with values

1. `types.ts`: add to `EmailEditorProps`:

    ```ts
        /** Campaign variable values to show in the preview only. What the editor saves keeps the tags. */
        previewValues?: Record<string, string>;
    ```

2. `block-editor.tsx`: take `previewValues` in the props. Change the memo to:

    ```ts
    const rendered = useMemo(
        () =>
            mode === 'edit'
                ? ''
                : fillVariables(renderEmail(document), previewValues ?? {}, {
                      escapeHtml: true,
                  }),
        [mode, document, previewValues],
    );
    ```

    Import `fillVariables` from `@marketing/lib/merge-tags`. Change nothing else. `commit`, `onChange` and the mount sync keep using `renderEmail(...)` unfilled.

3. `html-editor.tsx`: take `previewValues`, and change only the preview iframe to `srcDoc={fillVariables(html, previewValues ?? {}, { escapeHtml: true })}`.

4. `content-editor.tsx`: add `previewValues?: Record<string, string>` to the props (with the same one-line comment), and pass `previewValues={previewValues}` to `<Editor …>`.

**Verify**: `npm run types:check` exits 0. `git diff -- packages/core/resources/js/components/email-editors | grep "^[-+].*onChange"` shows only prop-type context, if anything, and no change to what `onChange` is called with.

### Step 3: Campaign previews pass the values

In `edit.tsx`:

1. In the Content step, right after `variables`, add:

    ```ts
    // Only the variables this email uses, so stale values from deleted tags never show.
    const previewValues = Object.fromEntries(
        variables
            .map(({ tag }) => [tag, form.data.variables[tag] ?? ''] as const)
            .filter(([, value]) => value !== ''),
    );
    ```

    Pass `previewValues={previewValues}` to both `EmailContentEditor`s: the main one and the A/B version B one. For version B, compute it in that component the same way, or pass it down as a prop. Use whichever is smaller. If that component can't reach `variables` and `form.data.variables` without a big change, pass `form.data.variables` straight through and say so in the report.

2. In the Review step:
    - Compute the same `previewValues`. `campaignVariables` with the same texts and `props.builtInTags` is fine.
    - Use `fillVariables(form.data.subject, previewValues, { escapeHtml: false })` in the Subject row, and the same for Preview text. Keep the existing `|| <Missing>…` fallbacks, applied to the raw value.
    - Set the iframe to `srcDoc={fillVariables(form.data.html, previewValues, { escapeHtml: true })}`.

**Verify**: `npm run types:check` exits 0. `grep -n "srcDoc={form.data.html}" packages/core/resources/js/pages/marketing/campaigns/edit.tsx` gives no output.

### Step 4: The compact Variables card

Create `packages/core/resources/js/components/campaign-variables.tsx`, exporting `CampaignVariables`:

```tsx
/** The campaign's own tags and their values, as compact rows that fold away once all are filled. */
export function CampaignVariables({
    variables, // { tag: string; optional: boolean }[]
    values, // Record<string, string>
    onChange, // (values: Record<string, string>) => void
    errors, // Record<string, string | undefined>
}) { … }
```

Behaviour:

- `missing` is the variables with `!optional && !(values[tag] ?? '').trim()`.
- `const [open, setOpen] = useState(missing.length > 0)`. Add a `useEffect` that calls `setOpen(true)` whenever `missing.length > 0` becomes true: depend on the boolean `missing.length > 0`, not on the count. It never auto-closes, so the card doesn't jump while someone types.
- Wrap it in `<Collapsible open={open} onOpenChange={setOpen}>` inside `<section className="overflow-hidden rounded-xl border bg-card shadow-xs">`.
- **Header**, `flex items-center justify-between gap-3 px-5 py-3`, with `border-b` only when open:
    - Left: "Variables" (`text-sm font-semibold`) and a `CircleHelp` tooltip. Keep the existing tooltip text. Make the tooltip trigger its own `<button type="button" aria-label="About variables">`; don't nest it inside the collapsible trigger.
    - Right: a `CollapsibleTrigger asChild` with a `<button type="button">` holding the status and a `ChevronDown` that rotates when open (`transition-transform`, `rotate-180` when open).
    - Status text: when `missing.length > 0`, `"{n} of {total} need a value"` in `text-13 font-medium text-amber-700 dark:text-amber-400`. Otherwise `"{total} set"`, or `"1 set"`, in `text-13 text-muted-foreground`.
- **Body**: `<CollapsibleContent>` containing `<div className="max-h-[26rem] divide-y overflow-y-auto">`. That's about 8 rows before it scrolls.
- **Each row**: `<div className="grid items-center gap-x-4 gap-y-1.5 px-5 py-2.5 sm:grid-cols-[minmax(0,13rem)_minmax(0,1fr)]">`.
    - Left: `<label htmlFor={`variable-${tag}`} className="flex min-w-0 items-center gap-2">` holding `<span className="truncate font-mono text-13" title={`{{ ${tag} }}`}>{`{{ ${tag} }}`}</span>`.
    - After the tag, either `<span className="text-xs text-muted-foreground">optional</span>` when optional, or, when this tag is missing, `<span className="size-1.5 shrink-0 rounded-full bg-amber-500" aria-hidden />` plus `<span className="sr-only">needs a value</span>`.
    - Right: `<Input id={`variable-${tag}`} className="h-8 text-13" value={values[tag] ?? ''} aria-invalid={Boolean(errors[`variables.${tag}`])} onChange={(e) => onChange({ ...values, [tag]: e.target.value })} />`, then `<InputError message={errors[`variables.${tag}`]} />` (the InputError import is `@/components/input-error`; it renders nothing without a message).
- Below the scroll area, inside `CollapsibleContent`: `{errors.variables && <div className="border-t px-5 py-2.5"><InputError message={errors.variables} /></div>}`.
- On phones the grid is one column, so the tag sits above its input.

In `edit.tsx`, replace the whole old card block with:

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

Remove imports that are no longer used. `npm run check` will flag them.

Change `hiddenErrors` so per-value variable errors aren't repeated in the top alert: add `&& !entry[0].startsWith('variables.')` to its filter.

**Verify**: `npm run check:fix`, then `npm run check && npm run types:check && npm run test && npm run build`. All exit 0.

## Test plan

- The unit tests from Step 1.
- Manual, if a browser is available. Give a draft campaign `{{ promo_code }}`, `{{ venue | TBC }}` and 10 more made-up tags.
    1. The card shows compact rows, says "11 of 12 need a value", and scrolls inside itself.
    2. Fill them all. The header reads "12 set". Fold it, reload, and it opens folded.
    3. Type a new `{{ extra }}` in the subject. The card opens and says 1 needs a value.
    4. In the builder, switch to Desktop. The values show in the email, and `{{ first_name }}` still shows as a tag.
    5. The Review step shows the values in the Subject and Preview text rows and in the email preview.
    6. A test send arrives with the values filled in (unchanged behaviour).
- Say in the report what wasn't possible.

## Done criteria

- All four commands exit 0, and the new `fillVariables` tests pass.
- `grep -n "srcDoc={form.data.html}" packages/core/resources/js/pages/marketing/campaigns/edit.tsx` gives no output.
- `grep -rn "fillVariables" packages/core/resources/js` matches in `merge-tags.ts`, `block-editor.tsx`, `html-editor.tsx` and `edit.tsx`.
- `git diff --stat dev -- packages/core/src packages/core/database` gives no output.

## STOP conditions

- An excerpt doesn't match.
- Passing `previewValues` would mean changing what an editor's `onChange` emits.
- `Collapsible` isn't exported from `@/components/ui/collapsible`.

## Maintenance notes

- `fillVariables` must stay in step with `MergeTags::fill` in PHP: same pattern, same "whole tag replaced, empty value left alone", and the same escaping in HTML. If one changes, change both.
- Previews show campaign variables filled, but contact tags stay raw. A later "preview as a contact" feature would fill those too.
