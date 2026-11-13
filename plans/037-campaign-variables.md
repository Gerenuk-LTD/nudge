# Plan 037: Campaign variables — set a value per campaign for the template's own tags

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Precondition**: plan 036 is merged. Check: `grep -n "function unfilledIn" packages/core/src/Sending/MergeTags.php` → one match. If it isn't merged, STOP.
>
> **Drift check (run first)**: `git diff --stat 2ff0f57..HEAD -- packages/core/src/Jobs/PrepareCampaign.php packages/core/src/Actions/Campaigns/SendTestEmail.php packages/core/src/Http/Requests/CampaignRequest.php packages/core/src/Http/Controllers/CampaignController.php packages/core/src/Models/Campaign.php`
> Expected: no output. Plan 036 changes `MergeTags.php`, `CampaignChecklist.php` and `edit.tsx`, which is expected. If an excerpt below doesn't match, STOP.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED. It changes the content that gets snapshotted at send time. The tests below pin the behaviour.
- **Depends on**: 036
- **Category**: feature
- **Planned at**: commit `2ff0f57`, 2026-11-13. Adds a migration.

## Why this matters

Merge tags fill in **per recipient** from contact data. Users also want values that are **the same for every recipient but change each campaign**, such as this month's promo code, an event date, or a link. They write `{{ promo_code }}` in a template and expect to fill it in on the campaign. Today it silently comes out blank. Plan 036 makes the checklist warn about this. This plan lets them set the value.

**Design, decided by the advisor; keep it as is:**

- **No new syntax.** Any tag in a campaign that isn't built in and isn't `custom.*` is a **campaign variable**. That's exactly what `MergeTags::unfilledIn` reports, plus tags with a fallback. So `{{ promo_code }}` in an existing template just works. `custom.*` tags stay contact data, even when the field doesn't exist.
- Values are stored on the campaign, in a `variables` JSON column mapping tag to value.
- Values are **substituted when the snapshot is taken** (in `PrepareCampaign`, and in test sends). Sending, links, open tracking and per-recipient merge tags are untouched. Later edits can't change an email that's already going out, because the snapshot already holds the values.
- In HTML, values are HTML-escaped. In the subject, preheader and plain text they're inserted as they are.
- A variable with **no value and no fallback blocks sending** (a new blocking checklist item). With a fallback (`{{ promo_code | none }}`), the value is optional and the fallback is used when it's empty.
- Automation emails are out of scope. Their tags behave as today.

## Current state

### `packages/core/src/Sending/MergeTags.php` (after plan 036)

Has `PATTERN` (group 1 is the tag name, group 2 the fallback), `render()`, `tagsIn()`, `BUILT_IN`, and `unfilledIn(string $template, array $customKeys): array` (unknown tags **without** a fallback).

### `packages/core/src/Jobs/PrepareCampaign.php::snapshot` (around line 81)

```php
            $html = (string) $campaign->html;
            $htmlB = $campaign->ab_enabled && filled($campaign->ab_html_b) ? (string) $campaign->ab_html_b : null;

            CampaignSnapshot::create([
                'campaign_id' => $campaign->id,
                'subject' => (string) $campaign->subject,
                'subject_b' => $campaign->ab_enabled && filled($campaign->ab_subject_b) ? $campaign->ab_subject_b : null,
                'preheader' => $campaign->preheader,
                'from_name' => …, 'from_email' => …, 'reply_to' => …,
                'html' => $html,
                'html_b' => $htmlB,
                'text' => filled($campaign->text) ? (string) $campaign->text : HtmlToText::convert($html),
                'text_b' => $htmlB === null ? null : HtmlToText::convert($htmlB),
            ]);

            if ($campaign->track_clicks) {
                foreach (array_unique([...LinkExtractor::urls($html), ...LinkExtractor::urls((string) $htmlB)]) as $url) { … CampaignLink::firstOrCreate … }
            }
```

### `packages/core/src/Actions/Campaigns/SendTestEmail.php` (around line 52)

```php
        $snapshot = new CampaignSnapshot([
            'subject' => '[Test] '.$campaign->subject,
            'preheader' => $campaign->preheader,
            …
            'html' => (string) $campaign->html,
            'text' => filled($campaign->text) ? (string) $campaign->text : HtmlToText::convert((string) $campaign->html),
        ]);
```

### Model, request, controller

- `packages/core/src/Models/Campaign.php`: `#[Fillable([...])]` attribute (around line 72) and `casts()` (around line 99, e.g. `'ab_results' => 'array'`).
- `packages/core/src/Http/Requests/CampaignRequest.php`: rules all use `'sometimes'`, e.g. `'ab_editor_state_b' => ['sometimes', 'nullable', 'array'],`.
- `packages/core/src/Http/Controllers/CampaignController.php`:
    - `private const CONTENT_FIELDS = ['subject', 'preheader', …, 'ab_html_b'];` (around line 42). Changing any of these withdraws approval in `update()`.
    - `details()` (around line 402) returns `$campaign->only([...])` plus extras. This is the `campaign` prop of the editor.
    - `edit()` passes `'mergeTags' => MergeTagOptions::all()` among its props.
    - `copyOf()` uses `$campaign->replicate([...])`, so new columns are copied automatically.
- Migration pattern, from `packages/core/database/migrations/2026_11_03_000001_add_ab_results_to_campaigns_table.php`:

```php
        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('ab_results')->nullable()->after('ab_decided_at');
        });
        // down(): $table->dropColumn('ab_results');
```

### Checklist (after plan 036)

`CampaignChecklist::for` builds `$personalised` (subject, preheader, html and the B variants) and a non-blocking `merge_tags` item from `MergeTags::unfilledIn`.

### Editor: `packages/core/resources/js/pages/marketing/campaigns/edit.tsx`

- `type Campaign` (around line 62) is the prop shape, and `type Draft` (around line 117) is the form shape. `useForm<Draft>({...})` (around line 185) seeds the form from `campaign`. Autosave (`useAutosave`, around line 390) PUTs the whole `form.data` 1.2s after a change, then reloads `checklist`.
- `ContentStep` (around line 426) renders `<EmailEnvelope …>` (from, subject, preheader) and the content editor.
- `stepChecks.Content` lists the checklist keys owned by the Content step.
- Tooltips: `Tooltip`, `TooltipContent` and `TooltipTrigger` from `@/components/ui/tooltip`. **The maintainer wants explanations in tooltips, not as text under fields.**
- Pure helpers with Vitest tests live next to their code, e.g. `packages/core/resources/js/components/email-editors/blocks/model.test.ts`. `packages/core/resources/js/lib/format.ts` is an example lib module.

### Tests

`packages/core/tests/Feature/SendingTest.php`. `sendNow($this, $campaign)`, and `$this->transports->sent[i]` holds the captured `Email`. Campaign factory subject: `'Hello {{ first_name | there }}'`.

## Commands you will need

| Purpose    | Command                                                                 | Expected                                                                                         |
| ---------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Core tests | `cd packages/core && vendor/bin/pest`                                   | all pass                                                                                         |
| PHP style  | `composer lint:check`                                                   | exit 0                                                                                           |
| PHPStan    | `composer types:check`                                                  | no new errors                                                                                    |
| Frontend   | `npm run check && npm run types:check && npm run test && npm run build` | exit 0. If `npm run check` flags only formatting in `plans/*.md`, that failure was already there |

## Scope

**In scope:**

- A new migration
- `Campaign.php`
- `CampaignRequest.php`
- `CampaignController.php` (`CONTENT_FIELDS`, `details`, `edit` props)
- `MergeTags.php`
- `CampaignChecklist.php`
- `PrepareCampaign.php` (`snapshot`)
- `SendTestEmail.php`
- `edit.tsx`
- A new `packages/core/resources/js/lib/merge-tags.ts` and its `merge-tags.test.ts`
- Tests in `SendingTest.php`

**Out of scope:**

- Automations and `SyncAutomationEmails`
- Templates (no values stored on templates)
- The public API
- `MergeTagOptions.php`
- The campaign show/report page

## Git workflow

Branch `advisor/037-campaign-variables` from `dev`. One commit per step group, conventional commits (e.g. `feat(core): campaigns can set values for their own tags`). Do not push.

## Steps

### Step 1: Storage and validation

1. Migration `packages/core/database/migrations/2026_11_13_000001_add_variables_to_campaigns_table.php`: `$table->json('variables')->nullable()->after('text');` and drop it in `down()`.
2. `Campaign`: add `'variables'` to `Fillable`, and `'variables' => 'array'` to `casts()`.
3. `CampaignRequest` rules:
    ```php
    'variables' => ['sometimes', 'nullable', 'array', 'max:50'],
    'variables.*' => ['nullable', 'string', 'max:1000'],
    ```
    Also reject bad keys: add a closure rule to `'variables'` that fails when any key doesn't match `/^[a-z][a-z0-9_]*$/` or is in `MergeTags::BUILT_IN`. Message: "Variable names use lowercase letters, numbers and underscores." Match how other closure rules are written in `packages/core/src/Http/Requests` (grep `function (string $attribute`). If there are none, use `function (string $attribute, mixed $value, \Closure $fail)`.
4. `CampaignController`: add `'variables'` to `CONTENT_FIELDS`, and add `'variables' => (object) ($campaign->variables ?? [])` to `details()`, so an empty map serialises as `{}`, not `[]`.

**Verify**: `cd packages/core && vendor/bin/pest --filter=Campaign` → passes, and `composer types:check` → no new errors.

### Step 2: `MergeTags` fills variables

Add to `MergeTags`:

```php
    /**
     * Campaign variables used in the template: tags that aren't built in or custom fields, with whether each has a fallback.
     *
     * @param  list<string>  $customKeys
     * @return array<string, bool>  tag => has a fallback
     */
    public static function variablesIn(string $template, array $customKeys): array
    {
        preg_match_all(self::PATTERN, $template, $matches, PREG_SET_ORDER);
        $variables = [];

        foreach ($matches as $match) {
            $tag = strtolower($match[1]);

            if (in_array($tag, self::BUILT_IN, true) || str_starts_with($tag, 'custom.')) {
                continue;
            }

            $variables[$tag] = ($variables[$tag] ?? false) || trim($match[2] ?? '') !== '';
        }

        return $variables;
    }

    /**
     * Puts the campaign's values in for its variables, leaving every other tag for sending to fill per recipient.
     *
     * @param  array<string, string|null>  $values
     */
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

Unfilled variables keep their tag, so at send time `render()` applies the fallback, or a blank.

### Step 3: Fill at snapshot time

In `PrepareCampaign::snapshot`:

```php
            $values = $campaign->variables ?? [];
            $html = MergeTags::fill((string) $campaign->html, $values, escapeHtml: true);
            $htmlB = $campaign->ab_enabled && filled($campaign->ab_html_b) ? MergeTags::fill((string) $campaign->ab_html_b, $values, escapeHtml: true) : null;
```

Then fill `subject`, `subject_b`, `preheader` and a stored `text` with `escapeHtml: false`. `HtmlToText::convert($html)` now receives the filled HTML. Link extraction already uses `$html`/`$htmlB`, so a variable used as a link URL gets tracked. Add `use Gerenuk\Marketing\Sending\MergeTags;` if it's missing.

Do the same in `SendTestEmail` for `subject`, `preheader`, `html` and `text`. Keep the `'[Test] '` prefix outside the fill.

### Step 4: Checklist

In `CampaignChecklist::for`, using plan 036's `$personalised` and custom keys:

1. `$variables = MergeTags::variablesIn($personalised, $customKeys);`
   `$missing = array_keys(array_filter($variables, fn (bool $hasFallback, string $tag) => ! $hasFallback && blank(($campaign->variables ?? [])[$tag] ?? null), ARRAY_FILTER_USE_BOTH));`
2. Add a **blocking** item before `'unsubscribe'`:
   `new ChecklistItem('variables', 'Variables have values', $missing === [], true, $missing === [] ? null : __('Give these a value in Variables: :tags.', ['tags' => implode(', ', array_map(fn ($t) => "{{ {$t} }}", $missing))]))`
3. Narrow plan 036's `merge_tags` item to `custom.*` tags only, since variables now cover the rest: filter `$unfilled` to tags starting with `custom.`. Change its message to: "These custom fields don't exist, so they will be blank: :tags. Create the field or fix the tag."

### Step 5: Editor

1. **`lib/merge-tags.ts`**: write a pure helper that mirrors `variablesIn`:

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
            if (builtIn.includes(tag) || tag.startsWith('custom.')) continue;
            found.set(
                tag,
                (found.get(tag) ?? false) || (match[2] ?? '').trim() !== '',
            );
        }
    }
    return [...found].map(([tag, optional]) => ({ tag, optional }));
}
```

Then `lib/merge-tags.test.ts`: a known tag and a custom tag are ignored, a duplicate counts once, a fallback marks it optional, and order is first-seen.

2. **Server prop**: in `CampaignController::edit`, add `'builtInTags' => MergeTags::BUILT_IN`. Add `builtInTags: string[]` to `Props`, `variables: Record<string, string>` to `type Campaign` and `type Draft`, and seed `variables: campaign.variables ?? {}` in `useForm`.

3. **Variables card**: in `ContentStep`, below the content editor, compute
   `const variables = campaignVariables([form.data.subject, form.data.preheader, form.data.html, ...(form.data.ab_enabled ? [form.data.ab_subject_b, form.data.ab_html_b] : [])], props.builtInTags);`
   When it's non-empty, render a card. Use the panel convention `rounded-xl border bg-card shadow-xs`, with a header row `border-b px-5 py-3.5 text-sm font-semibold` reading "Variables". Next to that heading, put a `?` tooltip (`CircleHelp`, `size-3.5`) with the text: "Values that are the same for everyone in this campaign. Any tag that isn't a contact detail or custom field becomes a variable."
   Inside, one row per variable: a `<Label>` showing the tag in `font-mono text-13` (`{{ promo_code }}`), plus "optional" in muted text when `optional`, and an `<Input>` bound to `form.data.variables[tag] ?? ''`. On change it calls `form.setData('variables', { ...form.data.variables, [tag]: value })`. Show `errors['variables']` under the card with the existing `InputError`. Autosave already sends `form.data`.
4. Add `'variables'` and `'merge_tags'` to `stepChecks.Content` (if 036 already added `merge_tags`, add only `'variables'`).

**Verify (final)**: run every command in the table.

## Test plan

Add these to `SendingTest.php`:

1. **Values reach the email**: a campaign with subject `'Code {{ promo_code }}'`, html containing `<p>Use {{ promo_code }}</p>`, preheader `'Ends {{ ends }}'`, and `'variables' => ['promo_code' => 'NOV<10>', 'ends' => 'Friday']`. After `sendNow`:
    - The subject is `Code NOV<10>`.
    - The HTML contains `Use NOV&lt;10&gt;` and `Ends Friday`, and doesn't contain `{{ promo_code }}`.
2. **Contact tags still personalise**: in the same campaign, the factory `{{ first_name | there }}` still becomes the contact's name.
3. **Missing value blocks**: a campaign with `{{ promo_code }}` and no variables. `sendNow` throws `ValidationException`, following the "blocking checklist failures stop the send" test.
4. **A fallback makes it optional**: `{{ promo_code | none }}` with no value sends, and the HTML contains `none`.
5. **The snapshot is frozen**: after sending, update `variables` and check that the `CampaignSnapshot` `html` still has the old value.
6. **Validation**: a PUT to `marketing.campaigns.update` with `variables: {'Bad-Key': 'x'}` → session error on `variables`. With `variables: {'first_name': 'x'}` → also an error.
7. **Approval is withdrawn**: following the existing approval test, an approved campaign whose `variables` change has `approved_at` null.

Plus the Vitest file from Step 5.1.

Revert check: comment out the `fill` calls in `PrepareCampaign` and confirm test 1 fails, then restore them. Report this.

Manual check, if a browser is available: write `{{ promo_code }}` in a campaign. The Variables card appears with a box for it, Review shows "Variables have values" as blocking until it's filled, and a test send shows the value.

## Done criteria

- Every command in the table passes.
- `grep -n "function fill\|function variablesIn" packages/core/src/Sending/MergeTags.php` → 2 matches.
- `grep -n "MergeTags::fill" packages/core/src/Jobs/PrepareCampaign.php packages/core/src/Actions/Campaigns/SendTestEmail.php` → matches in both.
- `git diff --stat dev` lists only in-scope files.

## STOP conditions

- Plan 036 isn't merged.
- An existing test fails because content now contains a value where a raw tag was expected. Report it.
- `PrepareCampaign::snapshot` or `SendTestEmail` is shaped differently from the excerpts (e.g. the snapshot is built elsewhere).

## Maintenance notes

- **Duplicating a sent campaign** copies the snapshot's HTML, which already has the values in it, so the copy starts with the old values written into the content. It also gets a copy of `variables`, which no longer matches any tags. That's acceptable, but worth knowing.
- **Automation emails** don't support variables. Their unknown tags still come out blank.
- **New tags:** any new built-in tag must be added to `MergeTags::BUILT_IN`. Otherwise it would show up as a campaign variable.
- **Old values aren't cleaned up:** values for tags that have been removed from the content stay in `variables`. Harmless.
