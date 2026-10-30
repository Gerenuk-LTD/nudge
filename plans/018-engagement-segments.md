# Plan 018: Segments can target people by engagement — opened, clicked or received an email in the last N days

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat ab8c3a6..HEAD -- packages/core/src/Segments packages/core/src/Models/Contact.php packages/core/resources/js/components/segment-builder.tsx packages/core/tests/Feature/SegmentTest.php`
> Expected: no output. On a mismatch with the excerpts, STOP.

## Status

- **Priority**: P1. The maintainer wants it ready for a Christmas campaign to about 6,000 contacts.
- **Effort**: M
- **Risk**: LOW. This is additive: new fields and operators, and existing segments are unchanged.
- **Depends on**: none
- **Category**: feature
- **Planned at**: commit `ab8c3a6`, 2026-10-30

## Why this matters

Segments can filter on contact fields, tags, lists and custom fields, but not on **engagement**. You can't build "people who opened or clicked something in the last 90 days".

Seasonal sends to old, unengaged addresses drive up spam complaints. Gmail and Yahoo require bulk senders to keep complaints under 0.3%, and going over it hurts every later email from that domain. Sending the big Christmas campaign only to recently engaged contacts is the standard protection.

The data already exists. Every send creates a `campaign_recipients` row with `sent_at`, `opened_at` and `clicked_at`. This plan exposes that as three segment fields:

| Field key           | Label             | Recipient column |
| ------------------- | ----------------- | ---------------- |
| `activity:opened`   | Opened an email   | `opened_at`      |
| `activity:clicked`  | Clicked a link    | `clicked_at`     |
| `activity:received` | Received an email | `sent_at`        |

Each field has three operators:

| Operator           | Label in the builder   | Meaning                                                                                 |
| ------------------ | ---------------------- | --------------------------------------------------------------------------------------- |
| `in_last_days`     | in the last … days     | At least one recipient row with that column on or after the start of the day N days ago |
| `not_in_last_days` | not in the last … days | No such row, which includes people who never did it                                     |
| `never`            | never                  | No recipient row with that column set at all                                            |

A ready-made starter segment, "Engaged recently" (opened an email in the last 90 days), gives the maintainer a one-click audience for the Christmas send.

## Current state

All paths are relative to `packages/core/`.

**`src/Segments/FieldType.php`**:

```php
enum FieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Choice = 'choice';
    case Tag = 'tag';
    case ContactList = 'list';

    /** @return list<string> */
    public function operators(): array
    {
        return match ($this) {
            self::Text => ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'is_empty', 'is_not_empty'],
            self::Number => ['equals', 'not_equals', 'greater_than', 'less_than', 'is_empty', 'is_not_empty'],
            self::Date => ['before', 'after', 'within_last_days', 'more_than_days_ago', 'is_empty', 'is_not_empty'],
            self::Boolean => ['is_true', 'is_false'],
            self::Choice => ['is', 'is_not'],
            self::Tag => ['has', 'has_not'],
            self::ContactList => ['in', 'not_in'],
        };
    }

    public static function operatorNeedsValue(string $operator): bool
    {
        return ! in_array($operator, ['is_empty', 'is_not_empty', 'is_true', 'is_false'], true);
    }
}
```

**`src/Segments/SegmentField.php`**: `new SegmentField(string $key, string $label, FieldType $type, ?string $column = null, array $options = [])`. `toArray()` sends `key`, `label`, `type`, `operators` and `options` to the UI.

**`src/Segments/SegmentFieldCatalog.php`**: `build()` lists the built-in fields (`email`, `first_name`, `last_name`, `status`, `source`, `created_at`, `tag`, `list`), then appends custom fields:

```php
$fields = [
    new SegmentField('email', 'Email', FieldType::Text, 'email'),
    ...
    new SegmentField('list', 'List', FieldType::ContactList, null, array_values(...)),
];

foreach (CustomFieldDefinition::query()->orderBy('label')->get() as $definition) { ... }
```

**`src/Segments/SegmentQuery.php`**: `applyCondition()` matches on `$field->type`:

```php
match ($field->type) {
    FieldType::Tag => $operator === 'has'
        ? $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($value))
        : $query->whereDoesntHave('tags', fn (Builder $tags) => $tags->whereKey($value)),
    FieldType::ContactList => ...,
    FieldType::Text => $this->applyText($query, $field->column, $operator, (string) $value),
    FieldType::Number => $this->applyNumber(...),
    FieldType::Date => $this->applyDate($query, $field->column, $operator, $value),
    FieldType::Boolean => ...,
    FieldType::Choice => ...,
};
```

`applyDate` uses `$startOfDay(now()->subDays((int) $value))` for `within_last_days`; follow the same whole-day convention.

**`src/Segments/SegmentDefinitionValidator.php`** `valueIsValid()`:

```php
if (in_array($operator, ['within_last_days', 'more_than_days_ago'], true)) {
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 36500]]) !== false;
}

return match ($field->type) {
    FieldType::Text => ...,
    FieldType::Number => is_numeric($value),
    FieldType::Date => is_string($value) && self::isDate($value),
    FieldType::Choice, FieldType::Tag, FieldType::ContactList => in_array(...),
    FieldType::Boolean => true,
};
```

**`src/Models/Contact.php`** has `HasMany` relations `automationRuns()` and `events()`, but none to `CampaignRecipient`. `CampaignRecipient` is workspace-scoped, and segments always run inside a workspace context, so a plain `hasMany` is correct. `campaign_recipients.contact_id` is a foreign key, so it's indexed.

**`resources/js/components/segment-builder.tsx`**:

- an `operatorLabels` map;
- `valuelessOperators = ['is_empty', 'is_not_empty', 'is_true', 'is_false']`;
- `defaultValue()`, which returns 30 for `['within_last_days', 'more_than_days_ago']`;
- `describeCondition()`, with special sentences for those two operators;
- `ValueInput`, which renders a number input plus a "days" or "days ago" suffix for those two operators;
- a `starter(key, name, description, condition)` helper, whose starters include `starter('new', 'New contacts', 'Added in the last 30 days', { field: 'created_at', operator: 'within_last_days', value: 30 })`.

The TS `SegmentField` type comes from `@marketing/types`; check whether `type` is a string union there, and add `'activity'` if so.

**Tests.** `tests/Feature/SegmentTest.php`:

- its `beforeEach` creates an owner and workspace and upserts three contacts:
    - `ann@example.com` (subscribed);
    - `bob@example.com` (subscribed);
    - `cat@test.org` (unsubscribed);
- `matchingEmails(array $conditions, string $match = 'all')` validates and runs a definition and returns the sorted emails;
- `condition($field, $operator, $value = null)` builds a condition.

For recipients, create a campaign with `Campaign::factory()->create()`. Then create rows with `CampaignRecipient::create(['campaign_id' => ..., 'contact_id' => ..., 'email' => ..., 'status' => RecipientStatus::Sent, 'sent_at' => ..., 'opened_at' => ..., 'clicked_at' => ...])`; `workspace_id` fills from the current workspace. Each contact can have only one row per campaign, because of a unique index on `(campaign_id, contact_id)`, so use separate campaigns for multiple sends to the same contact.

## Commands you will need

Run these from the repo root.

| Purpose       | Command                                                                         | Expected                        |
| ------------- | ------------------------------------------------------------------------------- | ------------------------------- |
| Segment tests | `cd packages/core && vendor/bin/pest --compact tests/Feature/SegmentTest.php`   | all pass                        |
| Core suite    | `cd packages/core && vendor/bin/pest --compact`                                 | all pass (278 at planning time) |
| Host suite    | `php artisan test --compact`                                                    | all pass (63)                   |
| PHPStan       | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`                    | 0 errors                        |
| Pint          | `vendor/bin/pint --dirty --format agent` then `vendor/bin/pint --test`          | exit 0                          |
| Frontend      | `npm run types:check && npm run check && npm run build && CI=true npm run test` | exit 0                          |

## Scope

**In scope:**

- `src/Segments/FieldType.php`
- `src/Segments/SegmentFieldCatalog.php`
- `src/Segments/SegmentQuery.php`
- `src/Segments/SegmentDefinitionValidator.php`
- `src/Models/Contact.php` (one relation)
- `resources/js/components/segment-builder.tsx`
- `resources/js/types.ts` (only if the field type is a union)
- `tests/Feature/SegmentTest.php`

**Out of scope:**

- Per-campaign conditions ("received the Black Friday campaign").
- Automation triggers.
- Indexes and migrations. At 6,000 contacts the existing `contact_id` index is enough.
- Changing what an "open" means. Apple Mail Privacy Protection inflates opens; see Maintenance notes.

## Git workflow

- Branch: `advisor/018-engagement-segments`.
- Commit: `feat: segment contacts by when they last opened, clicked or received an email`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Backend field type, fields and validation

- In `FieldType`, add `case Activity = 'activity';`. Its operators are `['in_last_days', 'not_in_last_days', 'never']`. Add `'never'` to the list in `operatorNeedsValue`, so `never` takes no value.
- In `SegmentFieldCatalog::build()`, add three fields after `created_at`, using the keys, labels and columns from the table above, for example `new SegmentField('activity:opened', 'Opened an email', FieldType::Activity, 'opened_at')`.
- In `SegmentDefinitionValidator::valueIsValid`, add `'in_last_days', 'not_in_last_days'` to the day-count operators, which accept an integer from 1 to 36500. Add `FieldType::Activity => false` to the `match`. It is never reached for valued operators, but the match must stay exhaustive.
- In `Contact`, add:

    ```php
    /**
     * @return HasMany<CampaignRecipient, $this>
     */
    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }
    ```

**Verify**: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` → 0 errors

### Step 2: Query

In `SegmentQuery::applyCondition`, add `FieldType::Activity => $this->applyActivity($query, $field->column, $operator, $value),`. Then add:

```php
/**
 * Whether the contact did something across all their campaign emails: an open, a click or a send, by whole day.
 *
 * @param  Builder<Contact>  $query
 */
private function applyActivity(Builder $query, string $column, string $operator, mixed $value): void
{
    match ($operator) {
        'in_last_days' => $query->whereHas('campaignRecipients', fn (Builder $recipients) => $recipients->where($column, '>=', now()->subDays((int) $value)->startOfDay())),
        'not_in_last_days' => $query->whereDoesntHave('campaignRecipients', fn (Builder $recipients) => $recipients->where($column, '>=', now()->subDays((int) $value)->startOfDay())),
        'never' => $query->whereDoesntHave('campaignRecipients', fn (Builder $recipients) => $recipients->whereNotNull($column)),
        default => throw new InvalidArgumentException("Unknown activity operator [{$operator}]."),
    };
}
```

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/SegmentTest.php` → all existing tests pass

### Step 3: Builder UI

In `segment-builder.tsx`:

- add the labels `in_last_days: 'in the last'`, `not_in_last_days: 'not in the last'` and `never: 'never'`;
- add `'never'` to `valuelessOperators`;
- treat `in_last_days` and `not_in_last_days` like `within_last_days` everywhere it is listed:
    - `defaultValue` returns 90 for them, since 90 days is the usual engagement window;
    - `ValueInput` shows a number input with a "days" suffix;
    - `describeCondition` produces "Opened an email in the last 90 days" and "Opened an email not in the last 90 days";
    - `changeOperator` resets the value for the activity type the way it does for `date`, so switching to `never` clears it;
- add a starter after "New contacts": `starter('engaged', 'Engaged recently', 'Opened an email in the last 90 days', { field: 'activity:opened', operator: 'in_last_days', value: 90 })`;
- if `@marketing/types` defines the field type as a union, add `'activity'`.

**Verify**: `npm run types:check && npm run check && npm run build` → exit 0

## Test plan

Add to `tests/Feature/SegmentTest.php` (dates relative to `now()`):

- **ann:** sent 10 days ago, opened 9 days ago, clicked 9 days ago.
- **bob:** sent 200 days ago, opened 199 days ago, never clicked.
- **cat:** never sent anything, and is unsubscribed but active. Segments match active contacts regardless of status, as the existing "empty segment" test shows.

1. `test('engagement conditions find who opened, clicked or received recently')`:
    - `activity:opened in_last_days 30` returns `['ann@example.com']`;
    - `activity:opened in_last_days 365` returns ann and bob;
    - `activity:clicked in_last_days 365` returns ann only;
    - `activity:received in_last_days 30` returns ann only.
2. `test('engagement conditions find who has gone quiet or never engaged')`:
    - `activity:opened not_in_last_days 30` returns bob and cat;
    - `activity:clicked never` returns bob and cat;
    - `activity:received never` returns cat.
3. `test('engagement combines with other conditions')`: `match: all`, with `activity:opened in_last_days 365` and `status is subscribed`, returns ann and bob.
4. Extend the existing invalid-definitions dataset with `activity:opened in_last_days 0` and `activity:opened in_last_days 'abc'`.
5. `test('the field catalog offers the engagement fields with their operators')`: `app(SegmentFieldCatalog::class)->find('activity:clicked')->toArray()['operators']` equals `['in_last_days', 'not_in_last_days', 'never']`.

**Revert check**: make `applyActivity`'s `in_last_days` branch ignore the date (`whereHas` with no condition), confirm test 1 fails, then restore it. Report the result.

## Done criteria

- [ ] `grep -n "case Activity" packages/core/src/Segments/FieldType.php` matches
- [ ] `grep -n "activity:opened" packages/core/src/Segments/SegmentFieldCatalog.php packages/core/resources/js/components/segment-builder.tsx` matches in both files
- [ ] Core suite passes with the new tests; host suite passes; PHPStan reports 0 errors; Pint is clean
- [ ] Frontend types, lint, build and `CI=true npm run test` all pass
- [ ] Only in-scope files changed

## STOP conditions

- An excerpt doesn't match.
- Saved segments or automations stop validating. For example, an existing test fails because the catalog's field order or count is asserted somewhere. Report it rather than reordering.
- `whereHas('campaignRecipients', ...)` is affected by the workspace scope in a way that changes results across workspaces. The tests stay in one workspace; if you see cross-workspace rows, STOP.

## Maintenance notes

- **Opens are unreliable.** Apple Mail Privacy Protection pre-loads images, so many Apple users "open" every email. For a strict engaged audience, combine "Clicked a link in the last 90 days" with "Opened an email in the last 30 days" using an "any" group, or rely on clicks alone.
- **Scale.** At around 100,000 contacts, consider an index on `campaign_recipients (contact_id, opened_at)` and the same for `clicked_at`, or a denormalised `last_engaged_at` on contacts.
- **A natural next step** is per-campaign conditions ("received campaign X", "didn't open campaign X") for resending to non-openers.
