# Plan 051: Contact emails and names are shown only to members who can view contacts

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say otherwise. If anything in "STOP conditions" happens, stop
> and report. Don't improvise. When done, update this plan's row in
> `plans/README.md`, unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 6982215..HEAD -- packages/core/src/Http/Controllers/CampaignController.php packages/core/src/Http/Controllers/SegmentController.php packages/core/src/Http/Controllers/ImportController.php packages/core/src/Http/Controllers/SuppressionController.php packages/core/resources/js/pages/marketing/campaigns/show.tsx packages/core/resources/js/pages/marketing/segments/form.tsx packages/core/resources/js/pages/marketing/imports/show.tsx`
> If any of these changed, compare them with the "Current state" excerpts.
> On a mismatch, STOP. Plan 049 changes `SegmentController::update()` and
> `CampaignController::approve()`/`edit()`; those changes are expected.

## Status

- **Priority**: P2. It only matters for custom roles.
- **Effort**: M
- **Risk**: LOW. The built-in roles that see these pages (Owner, Admin, Editor) all have `contacts.view`, so nothing changes for them.
- **Depends on**: none
- **Category**: security / authorization
- **Planned at**: commit `6982215`, 2026-11-15

## Why this matters

`contacts.view` is meant to decide who can see the contact base. A workspace can make custom roles without it, for example "Campaign writer" with only `campaigns.*`, or an importer with `contacts.import`. Four places still show contact emails to those roles:

- the campaign report's **recipients** table (email, error, searchable by email), gated only by `campaigns.view`. It also works for automation emails;
- **segment preview** (`segments/preview`, behind `segments.manage`), which returns the id, email and name of 10 matching contacts for **any** definition sent to it. So it can be used to probe the contact table ("email starts with a…");
- **import pages** (behind `contacts.import`): raw CSV rows of **any member's** pending import, and the emails of failed rows (page and CSV download);
- the **suppression removal** toast, which names the email (behind `contacts.manage`).

After this plan, members without `contacts.view` see counts, not people.

## Current state

**Routes** (`routes/web.php`): `campaigns.show` is in `Route::middleware('can:campaigns.view')` (line ~95). `segments.preview` is in `can:segments.manage` (line 72). Imports are in `can:contacts.import` (lines 78-86). `suppressions.destroy` is in `can:contacts.manage` (line 92).

**Permission check in a controller**: `$this->user($request)->hasWorkspacePermission($this->currentWorkspace->getOrFail(), Permission::ContactsView)`. Check which controllers have `$this->currentWorkspace` (constructor-injected `CurrentWorkspace`) with `grep -n "currentWorkspace" src/Http/Controllers/<Name>.php`; `SegmentController` receives `CurrentWorkspace $currentWorkspace` as a method parameter instead. Add the parameter where needed rather than changing constructors.

**1. `src/Http/Controllers/CampaignController.php`**:

- `show()` (line 338) sets `'recipients' => Inertia::defer(fn () => $this->recipients($request, $campaign))` (line 355).
- `recipients()` (line 364) returns a paginator of `id, contact_id, email, error, status, opened, clicked`, searchable on `email`.
- Frontend `resources/js/pages/marketing/campaigns/show.tsx`: the prop is `recipients?: Paginated<Recipient>` (line 141); line 493 renders `{!recipients ? ( ...loading state... ) : ( <table ...> )}`.

**2. `src/Http/Controllers/SegmentController.php::preview()`** (line 80):

```php
return response()->json([
    'count' => (clone $query)->count(),
    'total' => Contact::query()->active()->count(),
    'sample' => $query->orderByDesc('id')->limit(10)->get(['id', 'email', 'first_name', 'last_name'])
        ->map(fn (Contact $contact) => [
            'id' => $contact->id,
            'email' => $contact->email,
            'name' => trim("{$contact->first_name} {$contact->last_name}") ?: null,
        ]),
]);
```

Frontend `resources/js/pages/marketing/segments/form.tsx:268`: `{preview && preview.sample.length > 0 && ( ... )}`, so an empty `sample` already hides the list.

**3. `src/Http/Controllers/ImportController.php`**:

- `show()` (line ~68) reads up to 5 raw rows into `$sample` for pending imports, and passes `'failedRows' => $import->errors()->orderBy('row_number')->limit(self::ERRORS_SHOWN)->get(['id', 'row_number', 'email', 'message'])`.
- `errors()` streams every failed row's `row_number, email, message` as CSV.
- `Import` has a `user_id` (the uploader; `src/Models/Import.php:19`).
- Frontend `imports/show.tsx`: `sample: (string | null)[][]` (line 47), `failedRows: FailedRow[]` (line 55), `Results` uses `failedRows` (line 335).

**4. `src/Http/Controllers/SuppressionController.php::destroy()`** (line ~100):

```php
Inertia::flash('toast', ['type' => 'success', 'message' => __(':email can be emailed again.', ['email' => $suppression->email])]);
```

**Tests**: custom roles are made like `Role::create(['workspace_id' => $this->workspace->id, 'name' => 'Audience only', 'permissions' => ['contacts.view'], 'is_owner' => false])` (`tests/Feature/HomeTest.php:59`), then `$this->addMember($this->workspace, 'Audience only')` (`tests/TestCase.php:64`). Inertia prop assertions: see `tests/Feature/HomeTest.php` for `assertInertia(fn ($page) => ...)`. Deferred props need a partial reload to resolve; find how existing tests load `recipients` with `grep -rn "recipients" tests/Feature/*.php` and copy that.

## Commands

From `packages/core` (PowerShell: `php`/`composer`; Git Bash: `php.bat`/`composer.bat`), unless they say "root".

| Purpose         | Command                                                       | Expected                                      |
| --------------- | ------------------------------------------------------------- | --------------------------------------------- |
| Core tests      | `vendor/bin/pest`                                             | all pass, except the 7 Windows-only SES tests |
| One file        | `vendor/bin/pest tests/Feature/ContactDataPermissionTest.php` | all pass                                      |
| PHPStan (root)  | `vendor/bin/phpstan analyse --memory-limit=1G`                | 0 errors                                      |
| Pint (root)     | `vendor/bin/pint --test packages/core`                        | passes                                        |
| Frontend (root) | `npm run check` and `npm run types:check`                     | pass                                          |

## Scope

**In scope:** the four controllers above (only the named methods), the three pages above, and `tests/Feature/ContactDataPermissionTest.php` (new).

**Out of scope:**

- Route middleware. Members keep access to these pages; only the contact details are withheld.
- The contact timeline, contact pages and API. They're already behind `contacts.view` or token abilities.
- Home page stats and counts. Counts aren't personal data.
- Changing the built-in roles.

## Git workflow

- Branch off `dev`: `advisor/051-contact-data-needs-contacts-view`.
- One commit per controller is fine. Style: `fix(core): campaign recipients need contacts.view`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Campaign recipients

In `CampaignController::show()`:

- compute `$canViewContacts` with the permission check;
- `'recipients' => $canViewContacts ? Inertia::defer(fn () => $this->recipients($request, $campaign)) : null`;
- add `'canViewRecipients' => $canViewContacts`.

In `show.tsx`: add `canViewRecipients: boolean` to the props. Where line 493 branches on `!recipients`, branch first on `!canViewRecipients` and render a short muted line in the same card: "You need permission to view contacts to see who received this email." Don't show the search or status filter in that case. Keep the existing loading state for `!recipients` when permitted.

**Verify**: `vendor/bin/pest tests/Feature/SendingTest.php tests/Feature/AbTestTest.php` → all pass; (root) `npm run types:check` → passes.

### Step 2: Segment preview

In `SegmentController::preview()`, add `CurrentWorkspace $currentWorkspace` to the method's parameters. Return `'sample' => []` when the user lacks `contacts.view`; keep `count` and `total`. Build the sample query only when permitted.

**Verify**: `vendor/bin/pest tests/Feature/SegmentTest.php` → all pass. The form already hides an empty sample.

### Step 3: Imports

Add a private helper in `ImportController`:

```php
/** The uploader always sees their own rows; anyone else needs permission to view contacts. */
private function canSeeRows(Request $request, Import $import): bool
```

It returns true when `$import->user_id === $this->user($request)->getAuthIdentifier()` or the user has `contacts.view`.

- `show()`: when `! canSeeRows`, skip reading the file (`$sample = []`) and pass `failedRows` without the `email` column (select `['id', 'row_number', 'message']`, and map in `'email' => null` so the shape stays). Add `'canSeeRows' => ...` to the props.
- `errors()`: add `Request $request`; `abort_unless($this->canSeeRows($request, $import), 403)`.
- `imports/show.tsx`: add `canSeeRows: boolean`. When false, hide the sample table on the mapping step (show the headers only, which the page already has as `headers`), hide the "download failed rows" link, and show "—" for a failed row's email. Make `FailedRow['email']` `string | null` if it isn't already.

Check how mapping works without a sample (`grep -n "sample" resources/js/pages/marketing/imports/show.tsx`). If the column-mapping UI can't render with an empty sample, STOP and report instead of reworking it.

**Verify**: `vendor/bin/pest tests/Feature/ImportTest.php` → all pass; (root) `npm run types:check` → passes.

### Step 4: Suppression toast

In `SuppressionController::destroy()`: when the user has `contacts.view`, keep `':email can be emailed again.'`; otherwise use `__('The address can be emailed again.')`.

**Verify**: `vendor/bin/pest tests/Feature/SuppressionTest.php` → all pass.

### Step 5: Tests

Create `tests/Feature/ContactDataPermissionTest.php`. `beforeEach`: an owner and workspace (`$this->createOwner()`), a role `Writer` with `campaigns.view`, `campaigns.manage`, `segments.manage`, `contacts.import` and `contacts.manage` but **not** `contacts.view`, and a member with it.

1. **Recipients are hidden.** A sent campaign with one recipient row (copy how `SendingTest` makes one, or create a `CampaignRecipient` directly). As the writer, GET `campaigns.show` → `canViewRecipients` false and `recipients` null. As the owner → `canViewRecipients` true, and the deferred `recipients` loads with the email.
2. **Segment preview has no sample.** As the writer, POST `segments.preview` with a valid definition (copy one from `SegmentTest.php`) → `count` is a number and `sample` is `[]`. As the owner → `sample` has the contact.
3. **Another member's import rows are hidden.** The owner uploads an import (copy from `ImportTest.php`). As the writer, GET `imports.show` → `sample` is `[]` and `canSeeRows` false; GET `imports.errors` → 403.
4. **The uploader sees their own rows.** The writer uploads an import; GET `imports.show` → `sample` isn't empty.
5. **Failed-row emails are hidden.** An import with an `ImportError` row that has an email. As the writer, `failedRows.0.email` is null.
6. **Suppression toast.** As the writer, DELETE `suppressions.destroy` → the flashed toast message doesn't contain the email.

**Revert checks** (restore after each, commit nothing from them):

- Always return the sample in `preview()`: test 2 fails.
- Remove the `abort_unless` in `errors()`: test 3 fails.

**Verify**: `vendor/bin/pest` → all pass (apart from the 7 SES failures); PHPStan → 0 errors; Pint → passes; `npm run check` and `npm run types:check` → pass.

## Done criteria

- [ ] Without `contacts.view`: no recipient emails on campaign reports, no segment preview sample, no other members' import rows or failed-row emails, no email in the suppression toast
- [ ] With `contacts.view`, and for an import's own uploader, everything shows as before
- [ ] All 6 tests pass and the revert checks fail as described
- [ ] Pest, PHPStan, Pint, `npm run check` and `types:check` pass
- [ ] `plans/README.md` row updated

## STOP conditions

- The drift check shows changes the excerpts don't cover.
- The import mapping UI needs sample rows to work (Step 3).
- A built-in role (Owner, Admin, Editor) turns out to lack `contacts.view` (check `src/Actions/Workspaces/CreateWorkspace.php::defaultRoles()`); then this plan would change what they see.

## Maintenance notes

- **Release note:** custom roles without "View contacts" now see counts instead of recipients, segment preview samples and other members' import rows.
- Any new page that lists contacts or recipients should check `contacts.view` the same way.
- Not covered: A/B results and link stats, which show no personal data.
