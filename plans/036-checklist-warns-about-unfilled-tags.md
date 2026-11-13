# Plan 036: The campaign checklist warns about merge tags that will come out blank; preheader tags get personalised

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 2ff0f57..HEAD -- packages/core/src/Sending/MergeTags.php packages/core/src/Sending/CampaignMessage.php packages/core/src/Campaigns/CampaignChecklist.php packages/core/resources/js/pages/marketing/campaigns/edit.tsx`
> Expected: no output. If `edit.tsx` changed, that's plans 029/030/032. Check the `stepChecks` excerpt below still matches, and STOP if a PHP excerpt doesn't.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW. The new checklist item doesn't block sending, and the preheader change only affects the hidden preview text.
- **Depends on**: none. Plan 037 builds on this one.
- **Category**: bug + UX
- **Planned at**: commit `2ff0f57`, 2026-11-13

## Why this matters

A user wrote a template containing their own tags, such as `{{ promo_code }}`, and then couldn't work out where to set them. Merge tags are filled in separately for each recipient from that contact's data. A tag that isn't built in, and isn't a custom field, is **silently replaced with nothing**. The pre-send checklist says nothing about it, so the campaign goes out with blanks.

While checking this, a second bug turned up: the **preheader** (the preview text inboxes show next to the subject) is never personalised. `CampaignMessage::withPreheader` inserts it with `e($preheader)` and doesn't run `MergeTags::render` on it. So a preheader like "Hi {{ first_name }}, …" reaches recipients with the literal `{{ first_name }}`.

## Current state

### `packages/core/src/Sending/MergeTags.php`

```php
class MergeTags
{
    private const PATTERN = '/\{\{\s*([a-z][a-z0-9_.]*)\s*(?:\|\s*([^}]*?))?\s*\}\}/i';

    public static function render(string $template, Contact $contact, array $extra, bool $escapeHtml): string { … }

    /** @return list<string> */
    public static function tagsIn(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $matches);
        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    private static function contactValue(Contact $contact, string $tag): ?string
    {
        if (str_starts_with($tag, 'custom.')) { … custom_fields[substr($tag, 7)] … }
        return match ($tag) {
            'email' => …, 'first_name' => …, 'last_name' => …, 'full_name' => …,
            default => null,
        };
    }
}
```

`$extra` holds `unsubscribe_url` and `postal_address`, which `CampaignMessage` supplies. Capture group 2 is the fallback (`{{ first_name | there }}`).

### `packages/core/src/Sending/CampaignMessage.php::build`

```php
        $unsubscribeUrl = $this->urls->unsubscribe($recipient);
        $extra = [
            'unsubscribe_url' => $unsubscribeUrl,
            'postal_address' => e((string) $workspace->postal_address),
        ];
        …
        $html = $this->withPreheader($html, (string) $snapshot->preheader);
        …
        $text = MergeTags::render($content['text'], $contact, ['unsubscribe_url' => $unsubscribeUrl, 'postal_address' => (string) $workspace->postal_address], escapeHtml: false);
```

`withPreheader(string $html, string $preheader)` builds `'<div style="display:none;…">'.e($preheader).'</div>'`.

### `packages/core/src/Campaigns/CampaignChecklist.php::for`

This method builds a list of `new ChecklistItem($key, $label, $passed, $blocking, $message)`. Its non-blocking items come at the end:

```php
            new ChecklistItem('unsubscribe', 'Unsubscribe link placed', in_array('unsubscribe_url', $tags, true), false,
                'No {{ unsubscribe_url }} tag, so an unsubscribe link will be added at the bottom.'),
            new ChecklistItem('links', 'Links use https', ! $this->hasInsecureLinks($html), false,
                'Some links use http://, which some inboxes flag.'),
```

`$html = (string) $campaign->html;` and `$tags = MergeTags::tagsIn($html);` are set at the top of the method. Custom field keys come from `CustomFieldDefinition` (`Gerenuk\Marketing\Models\CustomFieldDefinition`, column `key`). It uses the `BelongsToWorkspace` concern, so queries only see the current workspace.

### `packages/core/resources/js/pages/marketing/campaigns/edit.tsx`

```tsx
const stepChecks: Record<(typeof steps)[number], string[]> = {
    Content: [
        'subject',
        'sender',
        'content',
        'unsubscribe',
        'postal_address',
        'provider',
        'ab_variant',
        'links',
    ],
    Audience: ['audience', 'ab_audience'],
    'Review and send': [],
};
```

The Review step lists failed items with a "Fix" link to the step that owns the key, so a new key must be added here.

### Tests

These are in `packages/core/tests/Feature/SendingTest.php`. `sendNow($this, $campaign)` sends synchronously, and `$this->transports->sent[0]` is the captured Symfony `Email`. The campaign factory's subject is `'Hello {{ first_name | there }}'`, and its html contains `{{ first_name | there }}`, `{{ unsubscribe_url }}` and `{{ postal_address }}`. Exemplar test: "the sent message is personalised, tracked and carries unsubscribe headers".

## Commands you will need

| Purpose    | Command                                                                 | Expected                                                                                                     |
| ---------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Core tests | `cd packages/core && vendor/bin/pest`                                   | all pass                                                                                                     |
| PHP style  | `composer lint:check` (repo root)                                       | exit 0                                                                                                       |
| PHPStan    | `composer types:check` (repo root; the root `phpstan.neon` covers core) | no new errors                                                                                                |
| Frontend   | `npm run check && npm run types:check && npm run test && npm run build` | exit 0. If `npm run check` flags only formatting in `plans/*.md`, that failure was already there, so note it |

## Scope

**In scope:** `MergeTags.php`, `CampaignMessage.php` (preheader only), `CampaignChecklist.php`, the `stepChecks` map in `edit.tsx`, and new tests in `SendingTest.php`.
**Out of scope:** automation emails' checklist (`Automations/ActivationChecklist.php`), the template editor, `MergeTagOptions.php`, and any new feature for setting values (that's plan 037).

## Git workflow

Branch `advisor/036-unfilled-tags` from `dev`, one commit per step, conventional commits (e.g. `fix(core): personalise the preheader`). Do not push.

## Steps

### Step 1: `MergeTags` knows which tags it can fill

Add to `MergeTags`:

```php
    /** Tags filled for every email without a custom field: contact details, then the ones sending supplies. */
    public const BUILT_IN = ['email', 'first_name', 'last_name', 'full_name', 'unsubscribe_url', 'postal_address'];

    /**
     * Tags in the template that nothing will fill and that have no fallback, so they'd come out blank.
     *
     * @param  list<string>  $customKeys  The workspace's custom field keys
     * @return list<string>
     */
    public static function unfilledIn(string $template, array $customKeys): array
    {
        preg_match_all(self::PATTERN, $template, $matches, PREG_SET_ORDER);
        $unfilled = [];

        foreach ($matches as $match) {
            $tag = strtolower($match[1]);
            $hasFallback = trim($match[2] ?? '') !== '';
            $known = in_array($tag, self::BUILT_IN, true)
                || (str_starts_with($tag, 'custom.') && in_array(substr($tag, 7), $customKeys, true));

            if (! $known && ! $hasFallback) {
                $unfilled[] = $tag;
            }
        }

        return array_values(array_unique($unfilled));
    }
```

### Step 2: Personalise the preheader

In `CampaignMessage::build`, replace
`$html = $this->withPreheader($html, (string) $snapshot->preheader);`
with:

```php
        $preheader = MergeTags::render((string) $snapshot->preheader, $contact, ['unsubscribe_url' => $unsubscribeUrl, 'postal_address' => (string) $workspace->postal_address], escapeHtml: false);
        $html = $this->withPreheader($html, $preheader);
```

`withPreheader` already escapes with `e()`, so render with `escapeHtml: false` here to avoid double escaping.

### Step 3: The checklist item

In `CampaignChecklist::for`:

1. Collect the text that will be personalised:
   `$personalised = implode("\n", array_filter([(string) $campaign->subject, (string) $campaign->preheader, $html, $campaign->ab_enabled ? (string) $campaign->ab_subject_b : '', $campaign->ab_enabled ? (string) $campaign->ab_html_b : '']));`
2. `$unfilled = MergeTags::unfilledIn($personalised, CustomFieldDefinition::query()->pluck('key')->all());`
3. Add this item after `'unsubscribe'` and before `'links'`:

```php
            new ChecklistItem('merge_tags', 'Every merge tag can be filled', $unfilled === [], false,
                $unfilled === [] ? null : trans_choice(
                    'This tag has no value, so it will be blank: :tags. Use a contact field, a custom field, or give it a fallback like {{ tag | text }}.|These tags have no value, so they will be blank: :tags. Use contact fields, custom fields, or give them a fallback like {{ tag | text }}.',
                    count($unfilled),
                    ['tags' => implode(', ', array_map(fn (string $tag) => "{{ {$tag} }}", $unfilled))],
                )),
```

Add `use Gerenuk\Marketing\Models\CustomFieldDefinition;`.

### Step 4: Link it to the Content step

In `edit.tsx`, add `'merge_tags'` to `stepChecks.Content`, after `'unsubscribe'`.

### Step 5: Tests (in `SendingTest.php`)

1. **Preheader personalised**: create a contact with `first_name` `Ann`, and a campaign with `'preheader' => 'For {{ first_name }}'`. After `sendNow`, the sent HTML body contains `For Ann` and doesn't contain `{{ first_name }}`.
2. **Checklist flags unfilled tags**: create a campaign whose html is the factory html plus `{{ promo_code }} {{ custom.missing }} {{ maybe | none }}`, and `CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text'])` (there's no factory; `BelongsToWorkspace` fills `workspace_id`). Also add `{{ custom.plan }}` to the html. Then `$items = app(CampaignChecklist::class)->for($campaign, $this->workspace);` and find the `merge_tags` item. Assert that `passed` is false, `blocking` is false, and that the message contains `{{ promo_code }}` and `{{ custom.missing }}` but not `maybe` and not `custom.plan`.
3. **A clean campaign passes**: the factory campaign as it is → the `merge_tags` item has `passed` true.
4. **It doesn't block**: a campaign with `{{ promo_code }}` still sends (`sendNow`, then `status` is `Sent`).

**Verify**: run the test commands; all pass. Revert check: temporarily undo Step 2 and confirm test 1 fails, then restore it. Say in your report that you did this.

**Verify (final)**: run every command in the table.

## Done criteria

- All commands pass, with no new PHPStan errors.
- `grep -n "'merge_tags'" packages/core/src/Campaigns/CampaignChecklist.php packages/core/resources/js/pages/marketing/campaigns/edit.tsx` → one match in each.
- `grep -n "MergeTags::render((string) \$snapshot->preheader" packages/core/src/Sending/CampaignMessage.php` → one match.
- `git diff --stat dev` lists only in-scope files.

## STOP conditions

- An excerpt doesn't match.
- An existing test fails because it expects the preheader with a literal `{{ … }}`. Report the test name.

## Maintenance notes

- `MergeTags::BUILT_IN` is now the list of tags that are always filled. Any new tag supplied in `$extra`, or handled in `contactValue`, must be added to it, or the checklist will wrongly flag it.
- Plan 037 turns the unfilled tags that aren't custom fields into per-campaign variables, and changes this item's message.
- Automation emails have no checklist warning yet. That's a possible follow-up.
