# Plan 034: More starter suggestions, automation starters, no "Personal letter", an empty state for Campaigns

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Precondition**: plan 031 (shared `EmptyState`) must be merged. Check: `test -f packages/core/resources/js/components/empty-state.tsx && echo ok` → `ok`. If not, STOP.
>
> **Drift check (run first)**: `git diff --stat 2ff0f57..HEAD -- packages/core/resources/js/components/segment-builder.tsx packages/core/resources/js/pages/marketing/custom-fields/index.tsx packages/core/src/Templates/StarterTemplates.php packages/core/resources/email-templates packages/core/resources/js/pages/marketing/campaigns/index.tsx`
> Expected: no output, or only plan 031/032/033's changes. Compare each excerpt below with the file; STOP if one no longer matches. `automations/index.tsx` **will** have changed (plan 031 put `EmptyState` in it) — that's expected.

## Status

- **Priority**: P2
- **Effort**: S–M
- **Risk**: LOW
- **Depends on**: 031
- **Category**: product (onboarding)
- **Planned at**: commit `2ff0f57`, 2026-11-12

## Why this matters

Empty pages offer "start from one of these" suggestions to help new workspaces get going. The maintainer decided:

1. **Segments:** add **"Gone quiet"**, for contacts who haven't opened an email in the last 90 days. It's the opposite of the existing "Engaged recently" and suits win-back campaigns.
2. **Custom fields:** add **Country**, a text field with key `country`.
3. **Templates:** remove the **"Personal letter"** starter.
4. **Automations:** the empty page gets suggestion cards. Each card creates a draft automation with a name and trigger, and the user adds the steps themselves. No prefilled steps.
5. **Campaigns:** a workspace with no campaigns shows the same empty-state style as the other pages, not an empty "In progress" shelf above an empty "Sent" table.

## Current state

### Segment starters — `packages/core/resources/js/components/segment-builder.tsx` (~line 148)

`segmentStarters(fields)` returns starters built with a local helper `starter(key, name, description, condition)`, where `condition` is `{ field, operator, value }`. The existing "Engaged recently" entry:

```tsx
        starter(
            'engaged',
            'Engaged recently',
            'Opened an email in the last 90 days',
            {
                field: 'activity:opened',
                operator: 'in_last_days',
                value: 90,
            },
        ),
```

The activity operators the backend accepts include `in_last_days` and `not_in_last_days` (`packages/core/src/Segments/FieldType.php`). The segments empty page (`pages/marketing/segments/index.tsx`) and the segment form (`?starter=<key>`) both read this list, so nothing else needs changing.

### Custom field starters — `packages/core/resources/js/pages/marketing/custom-fields/index.tsx` (~line 82)

```tsx
const STARTERS: NewField[] = [
    { label: 'Company', key: 'company', type: 'text' },
    { label: 'Plan', key: 'plan', type: 'text' },
    { label: 'Birthday', key: 'birthday', type: 'date' },
    { label: 'Lifetime value', key: 'lifetime_value', type: 'number' },
    { label: 'VIP', key: 'vip', type: 'boolean' },
];
```

### Starter templates — `packages/core/src/Templates/StarterTemplates.php` (~line 20)

```php
            'simple' => ['name' => __('Simple'), ...],
            'newsletter' => ['name' => __('Newsletter'), ...],
            'announcement' => ['name' => __('Announcement'), ...],
            'letter' => ['name' => __('Personal letter'), 'description' => __('Plain text that reads like a note from a person.'), 'editor' => EmailEditor::BLOCKS],
            'custom' => ['name' => __('Custom HTML'), ...],
```

Each starter's content lives in `packages/core/resources/email-templates/{key}.json` (builder) or `.html`. The only file for `letter` is `letter.json`. Nothing else references `'letter'`. Campaigns copy the starter's content when they're created, so existing campaigns aren't affected.

Tests are in `packages/core/tests/Feature/EmailTemplateTest.php`. This dataset (~line 103) checks that unknown starters are rejected:

```php
})->with([
    'unknown starter' => ['starter:missing'],
    'nonsense' => ['saved:abc'],
    ...
```

### Automations — `packages/core/resources/js/pages/marketing/automations/index.tsx`

After plan 031, the empty branch (`automations.length === 0 ? …`) renders `<EmptyState icon={Workflow} title="No automations yet" description="A welcome series is a good first one: …" />`. It has no actions. The page gets `triggers: { value: string; label: string }[]` from the server, and `canManage = useCan()('automations.manage')`.

Creating an automation POSTs `{ name, trigger_type }` to `AutomationController.store`. That is `packages/core/src/Http/Controllers/AutomationController.php::store`, which validates `name` (required, max 150) and `trigger_type`, then redirects to the editor. The client helper `AutomationController.store.url()` comes from `@/actions/Gerenuk/Marketing/Http/Controllers/AutomationController` and is already imported in this page.

Trigger values (`AutomationTrigger` enum): `list_joined`, `tag_added`, `segment_entered`, `date_field`, `inactivity`, `api_event`.

**The pattern to copy**: the Lists empty page (`pages/marketing/lists/index.tsx`, ~lines 128–152) shows "Or start from one of these" with a grid of starter buttons:

```tsx
<div className="mt-5 w-full max-w-3xl space-y-2.5">
    <p className="text-xs font-medium text-muted-foreground">
        Or start from one of these
    </p>
    <div className="grid gap-2 text-left sm:grid-cols-2">
        {STARTERS.map((starter) => (
            <button
                key={starter.name}
                type="button"
                onClick={() => add(starter)}
                className="group flex items-center gap-3 rounded-lg border bg-card px-3.5 py-3 text-left shadow-xs transition-colors outline-none hover:border-foreground/20 focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                        {starter.name}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {starter.description}
                    </span>
                </span>
                <ArrowRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
            </button>
        ))}
    </div>
</div>
```

### Campaigns — `packages/core/resources/js/pages/marketing/campaigns/index.tsx`

The page receives `campaigns: Paginated<CampaignSummary>` (sent, sending or cancelled, filtered by `search`/`status`; `Paginated` has `total`) and `upcoming: CampaignSummary[]` (drafts and scheduled). It always renders:

```tsx
                {(upcoming.length > 0 || canCreate) && (
                    <section className="space-y-3">
                        <h2 className="text-base font-semibold">In progress</h2>
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            {upcoming.map(…)}
                            {canCreate && upcoming.length < 4 && (
                                <NewCampaign variant="card" />
                            )}
                        </div>
                    </section>
                )}

                <h2 className="text-base font-semibold">Sent</h2>
                <div …toolbar…>
                <DataTable … emptyMessage={ table.get('search') || table.get('status') ? 'No campaigns match these filters.' : 'Nothing sent yet. Campaigns appear here once they go out.' } />
                <Pagination page={campaigns} query={table} />
```

`NewCampaign` is the dialog component in the same file. `<NewCampaign />` (with the default variant) renders the primary "New campaign" button and opens the dialog. `table` is `useTableQuery(...)` with `table.get('search')` and `table.get('status')`.

## Conventions

- Copy and naming follow the surrounding code. Sentence case, no trailing full stop in starter descriptions (e.g. "Added in the last 30 days").
- PHP: Pint (`composer lint:check`); Pest tests in `packages/core/tests/Feature`.

## Commands you will need

| Purpose        | Command                                                                 | Expected                                                                                                               |
| -------------- | ----------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Frontend gates | `npm run check && npm run types:check && npm run test && npm run build` | exit 0. If `npm run check` flags only `plans/*.md` formatting, that failure was already there, so note it and carry on |
| PHP style      | `composer lint:check`                                                   | exit 0                                                                                                                 |
| Core tests     | `cd packages/core && vendor/bin/pest --filter=EmailTemplate`            | all pass                                                                                                               |

## Scope

**In scope:**

- `segment-builder.tsx` (`segmentStarters` only)
- `custom-fields/index.tsx` (`STARTERS` only)
- `StarterTemplates.php` and delete `resources/email-templates/letter.json`
- `EmailTemplateTest.php`
- `automations/index.tsx`
- `campaigns/index.tsx`

**Out of scope:**

- Any controller or route.
- Prefilled automation steps.
- The Lists starters.
- Changing other starter copy.

## Git workflow

Branch `advisor/034-starters` from `dev`. One commit per step, conventional commits (e.g. `feat(core): suggest a "Gone quiet" segment`). Do not push.

## Steps

### Step 1: "Gone quiet" segment starter

In `segmentStarters`, insert directly after the `'engaged'` starter:

```tsx
        starter(
            'quiet',
            'Gone quiet',
            "Hasn't opened an email in the last 90 days",
            {
                field: 'activity:opened',
                operator: 'not_in_last_days',
                value: 90,
            },
        ),
```

**Verify**: `npm run types:check` → exit 0.

### Step 2: Country field starter

Add `{ label: 'Country', key: 'country', type: 'text' },` to `STARTERS` after `Plan`.

### Step 3: Remove "Personal letter"

1. Delete the `'letter' => [...]` line from `StarterTemplates::catalog()`.
2. `git rm packages/core/resources/email-templates/letter.json`.
3. In `EmailTemplateTest.php`, add `'removed starter' => ['starter:letter'],` to the "cannot start from an unknown template" dataset, after `'unknown starter'`.
4. Check nothing else refers to it: `grep -rn "letter" packages/core/src packages/core/resources --include=*.php --include=*.ts --include=*.tsx | grep -vi "newsletter\|letters"` → no output.

**Verify**: `cd packages/core && vendor/bin/pest --filter=EmailTemplate` → all pass, including the new case. `composer lint:check` → exit 0.

### Step 4: Automation starters on the empty page

In `automations/index.tsx`:

1. Add a constant above the component:

```tsx
/** Common first automations: each makes a draft with this name and trigger, ready for its steps. */
const STARTERS = [
    {
        name: 'Welcome series',
        trigger: 'list_joined',
        description: 'Greet people when they join a list',
    },
    {
        name: 'Birthday email',
        trigger: 'date_field',
        description: 'Send something on a date in their details',
    },
    {
        name: 'Win back',
        trigger: 'inactivity',
        description: 'Reach out when someone stops engaging',
    },
    {
        name: 'Tag follow-up',
        trigger: 'tag_added',
        description: 'Follow up when a contact is given a tag',
    },
] as const;
```

2. Show only starters whose trigger the server offers: `const starters = STARTERS.filter((starter) => triggers.some((trigger) => trigger.value === starter.trigger));`.
3. Pass `actions` to the existing `EmptyState`, only when `canManage`. Use the Lists markup above, with each starter as a `<button type="button">` whose `onClick` is `router.post(AutomationController.store.url(), { name: starter.name, trigger_type: starter.trigger })`. Import `router` from `@inertiajs/react` and `ArrowRight` from `lucide-react`. Before the grid, also put the existing `<NewAutomation triggers={triggers} />` button. Wrap both in a fragment, as Lists does.
4. If plan 031 didn't migrate this page (no `EmptyState` here), STOP.

**Verify**: `npm run check && npm run types:check` → exit 0 (apart from the plans/*.md formatting failure that was already there).

### Step 5: Empty state for Campaigns

In `campaigns/index.tsx`:

1. Work out: `const filtered = Boolean(table.get('search') || table.get('status'));` and `const nothingYet = !filtered && upcoming.length === 0 && campaigns.total === 0;`.
2. When `nothingYet`, render this in place of **both** the "In progress" section and everything from `<h2 …>Sent</h2>` through `<Pagination …/>`:

```tsx
<EmptyState
    icon={Send}
    title="No campaigns yet"
    description="A campaign is a one-off email to a list, a segment or everyone. Start from a template and send it now or schedule it."
    actions={canCreate && <NewCampaign />}
/>
```

Import `EmptyState` from `@marketing/components/empty-state` and `Send` from `lucide-react`. Otherwise, render exactly what's there now. 3. Leave the `PageHeader` as it is. It still shows its own New campaign button.

**Verify (final)**: `npm run check && npm run types:check && npm run test && npm run build` → exit 0. `cd packages/core && vendor/bin/pest` → all pass. `composer lint:check` → exit 0.

## Test plan

- PHP: the new `'removed starter'` dataset case in `EmailTemplateTest.php` proves `starter:letter` is now rejected.
- No React component tests exist, and don't add any.
- Manual, if a browser is available, in a fresh workspace:
    - **Segments:** the empty page lists "Gone quiet", and clicking it opens the form with "Opened an email · not in the last 90 days".
    - **Custom fields:** the empty page lists Country.
    - **Template pickers:** the new-campaign and new-template pickers no longer show Personal letter.
    - **Automations:** the empty page shows 4 cards, and each one creates a draft with that name and trigger and opens the editor.
    - **Campaigns:** with no campaigns, the page shows the empty state. After creating a draft, the shelf and table come back.

## Done criteria

- All commands above exit 0 (the plans/*.md formatting failure that was already there is allowed).
- `test -f packages/core/resources/email-templates/letter.json` → false.
- `grep -n "'quiet'" packages/core/resources/js/components/segment-builder.tsx` matches.
- `grep -n "key: 'country'" packages/core/resources/js/pages/marketing/custom-fields/index.tsx` matches.
- `grep -n "No campaigns yet" packages/core/resources/js/pages/marketing/campaigns/index.tsx` matches.
- `git diff --stat dev` lists only in-scope files.

## STOP conditions

- Plan 031 isn't merged, or `automations/index.tsx` has no `EmptyState`.
- The segment form rejects `not_in_last_days` for `activity:opened` (validation error on save). Report the error.
- Removing `letter` breaks any test other than the one you added.

## Maintenance notes

- Automation starters only set the name and trigger. A later "recipes" feature could add prefilled steps, and would need content for the emails.
- `STARTERS` is filtered against the server's triggers, so removing a trigger enum case won't leave a card pointing at it.
