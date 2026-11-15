# Plan 062: Demo data covers every page, and `migrate:fresh --seed` creates it

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to the repo root unless
> they say otherwise. If anything in "STOP conditions" happens, stop and
> report. Don't improvise. Don't edit `plans/README.md`; the reviewer
> maintains it.
>
> **Drift check (run first)**:
> `git diff --stat 5616559..HEAD -- app/Console/Commands/DemoData.php tests/Feature/DemoDataCommandTest.php database/seeders/DatabaseSeeder.php`
> If any changed, compare them with "Current state". On a mismatch, STOP.

## Status

- **Priority**: P2 (requested by the maintainer for manual testing, 2026-11-15)
- **Effort**: M
- **Risk**: LOW. It's dev-only data. The command already refuses to run in production.
- **Depends on**: none. If plan 061 (API log request IDs) has landed, the API log rows get their `ulid` automatically through the model.
- **Category**: DX
- **Planned at**: commit `5616559`, 2026-11-15

## Why this matters

When testing by hand, the maintainer wants every page to have something to click. `php artisan app:demo-data` already builds a demo workspace:

- 500 contacts, plus custom fields, tags, lists and segments;
- a provider and suppressions;
- a draft, a scheduled and three sent campaigns (one an A/B test), with recipients, links and engagement events;
- one "Welcome series" automation.

It misses everything added since it was written:

- **Members:** only the owner. There are no teammates, no custom role and no pending invitation, so the Members and Roles pages are almost empty.
- **Email templates:** none.
- **Imports:** none, so the Imports page is empty.
- **Automations:** one automation and no runs, so it shows no progress. No draft or paused automation.
- **API:** no tokens and no API log.
- **Contact timelines:** only campaign events; no custom events such as `order_placed`.

Also, `php artisan migrate:fresh --seed` only creates a test user (`DatabaseSeeder`), so a fresh database has nothing to look at.

## Current state

**`app/Console/Commands/DemoData.php`** (449 lines):

- `#[Signature('app:demo-data {--contacts=500 ...} {--email=test@example.com ...}')]`.
- `handle()`:
    - refuses to run in production;
    - `firstOrCreate`s the user (password `password`, verified if new);
    - creates a workspace "Demo: {company}" through `CreateWorkspace` and sets a postal address;
    - inside `$currentWorkspace->runAs($workspace, ...)` and `DB::transaction`, runs `$this->components->task(...)` for: `createAudienceStructure`, `createContacts`, `createSegments`, `createProviderAndSuppressions`, `createCampaigns` and `createAutomation`;
    - switches the user to the workspace.
- `createCampaigns()` uses `Campaign::factory()`; `recordSend()` writes recipients, links and `ActivityEvent` rows in bulk (`ActivityEvent::insert($rows)`).
- `createAutomation()` creates "Welcome series" with `Automation::factory()`:
    - trigger `ListJoined` on the Newsletter list;
    - steps `send_email`, `wait` (3 days), `send_email`;
    - then calls `SyncAutomationEmails::handle()`.

Read the whole file before changing it. The new methods must follow its style: small private methods, one `components->task(...)` line each, and bulk inserts for large row counts.

**`tests/Feature/DemoDataCommandTest.php`** runs `app:demo-data --contacts=40 --email=demo@example.com` and checks:

- the user, the workspace and its contacts;
- three sent campaigns with consistent counts;
- that every segment's query runs.

**`database/seeders/DatabaseSeeder.php`** creates `test@example.com` with `User::factory()`.

**Models and enums to use** (read each before using it):

- `Gerenuk\Marketing\Models\Membership`, `Invitation` and `Role`:
    - `CreateWorkspace::defaultRoles()` gives the role names (Owner, Admin, Editor, Viewer);
    - see how `tests/` creates members (`addMember`) and invitations (grep `Invitation::create` in `packages/core/src` and `packages/core/tests`). Invitations store a `token_hash`, never a plain token.
- `EmailTemplate`: `EmailTemplateFactory`; `database/seeders/SendTestSeeder.php::document()` shows a builder document.
- `Import` and `ImportError`, with `ImportStatus`: `pending`, `queued`, `processing`, `completed`, `failed`.
    - Columns: `user_id`, `contact_list_id`, `file_path`, `original_filename`, `headers` (json), `column_mapping` (json), `tags` (json), `status`, `total_rows`, `processed_rows`, `created_count`, `updated_count`, `failed_count`, `started_at` and `finished_at`.
    - Find which disk and directory imports use (`grep -rn "file_path" packages/core/src/Imports packages/core/src/Http/Controllers/ImportController.php`) and write a small matching CSV, so pages that read the file don't break.
- `AutomationRun`, with `AutomationRunStatus`: `active`, `waiting`, `completed`, `exited`.
    - Columns: `automation_id`, `contact_id`, `status`, `current_step_id`, `next_run_at`, `context`, `exit_reason` and `completed_at`.
    - `Automation` has `entered_count`, `completed_count` and `activated_at`. `AutomationStatus` is `draft`, `active` or `paused`.
- `ApiToken` (`token_hash`, `permissions`, `last_used_at`, `created_by`) and `ApiRequest`:
    - the API log;
    - columns `api_token_id`, `token_name`, `method`, `route`, `path`, `status`, `duration_ms`, `ip`, `user_agent`, `request_body`, `response_body` and `created_at`;
    - bodies only for writes (not `GET`/`HEAD`) and for statuses ≥ 400, as the real middleware does.
- `ActivityEvent` with `EventType::Custom` for custom events. Copy the row shape `recordSend()` uses, and check how the API's events endpoint stores a custom event's name and properties (`grep -rn "EventType::Custom" packages/core/src`).

## Scope

**In scope:** `app/Console/Commands/DemoData.php`, `tests/Feature/DemoDataCommandTest.php`, `database/seeders/DatabaseSeeder.php`.

**Out of scope:**

- anything under `packages/core` (no new factories);
- `SendTestSeeder`;
- real sending, queued jobs and real API tokens (every token gets a random hash nobody knows).

## Git workflow

- Branch off `dev`: `advisor/062-demo-data`.
- Commits per step are fine, such as `feat: demo data covers members, templates, imports, automation runs and the API log`. Each ends with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

Add each part as a private method with its own `$this->components->task(...)` line in `handle()`, after `Automation`.

### Step 1: Team

`createTeam(Workspace $workspace, User $owner)`:

- **Three teammates** with password `password` and verified emails: `admin@example.com` (Admin), `editor@example.com` (Editor) and `viewer@example.com` (Viewer), each `firstOrCreate`d by email. Give each a `Membership` in the workspace with that role, unless they already have one.
- **A custom role**, "Importer", with `contacts.view`, `contacts.import` and `campaigns.view`.
- **A pending invitation** to `newhire@example.com` as Editor, invited by the owner. Use a random token hash, and set the expiry the way the real invite code does.

The owner's line at the end of `handle()` should also mention the teammates: "Teammates: admin@, editor@ and viewer@example.com (password "password")."

### Step 2: Templates

`createTemplates(User $owner)`: two builder templates ("Newsletter" and "Product announcement") using a block document like `SendTestSeeder::document()`. Copy the document shape into a private method here; don't call the other seeder. Then one HTML template ("Plain announcement").

### Step 3: Imports

`createImports(User $owner)`:

- **One completed import** of `customers-march.csv`:
    - into the "Customers" list (or whichever list exists; check `createAudienceStructure()`);
    - 120 rows: 80 created, 30 updated, 10 failed;
    - a small CSV written to the import disk;
    - 10 `ImportError` rows with realistic messages, such as "The email field must be a valid email address." and "Duplicate row".
- **One failed import** of `leads.csv`: 0 processed, with `finished_at` set.

Use dates within the last 30 days.

### Step 4: More automations, with runs

`createAutomationRuns()`, after `createAutomation`:

- **"Welcome series":**
    - set it `active` with `activated_at` 40 days ago;
    - add `AutomationRun` rows for about 60 Newsletter contacts: about 25 `completed`, 20 `waiting` (on the `wait-3-days` step with a `next_run_at` in the next 3 days), 10 `active` and 5 `exited` (with an `exit_reason` such as "unsubscribed");
    - set `entered_count` and `completed_count` to match.
- **Two more automations**, with steps like "Welcome series":
    - "Win-back", `paused`;
    - "Birthday offer", `draft`, with a `DateField` trigger on a date custom field if one exists, and otherwise an `ApiEvent` trigger on `signed_up`.

Check the trigger config shapes in `AutomationFactory` and `packages/core/tests/Feature/AutomationTest.php`, and run `SyncAutomationEmails` for each, as `createAutomation()` does.

### Step 5: API tokens and log

`createApiLog(Workspace $workspace, User $owner)`:

- **Two tokens**, "Zapier sync" (`contacts.view` and `contacts.manage`) and "Website signup form" (`contacts.manage`). Each gets a random `token_hash` and a `last_used_at` within the last hour.
- **About 120 `ApiRequest` rows** over the last 12 days, created through the model so any `ulid` gets filled:
    - mostly `PUT contacts/{email}` (200/201, with request and response bodies using real demo contacts' emails), `POST events` (202), `GET contacts` and `GET me` (200, no bodies);
    - a few 422, 404, 403, 429 and one 500, with error response bodies;
    - durations of 12–240 ms, and 800–3000 ms for the 500;
    - bodies older than 7 days set to null, as the daily prune does;
    - IPs from documentation ranges (`203.0.113.x`, `198.51.100.x`) and user agents "Zapier" / "GuzzleHttp/7".

### Step 6: Custom events on contacts

`createCustomEvents()`: about 300 `EventType::Custom` events (`order_placed` with `total` and `items`, `trial_started`, `page_viewed` with `path`), spread over random contacts in the last 60 days. Use a bulk insert, as `recordSend()` does.

### Step 7: `migrate:fresh --seed` creates the demo

In `DatabaseSeeder::run()`, after creating the test user, unless the app is in production:

```php
$this->command->call('app:demo-data', ['--email' => 'test@example.com']);
```

The command `firstOrCreate`s the user, so it reuses the one just made. Keep the existing user creation, and make sure the test user's email is verified (the factory default already is; confirm).

### Step 8: Tests

Extend `tests/Feature/DemoDataCommandTest.php` (keep `--contacts=40`). Inside the workspace, after the command:

- the workspace has 4 members (owner + 3), a role named "Importer" and 1 invitation;
- there are 3 email templates;
- there are 2 imports (one `completed` with 10 `ImportError` rows, one `failed`);
- there are 3 automations, and "Welcome series" is active with `AutomationRun` rows whose `entered_count` matches;
- there are 2 API tokens and more than 50 `ApiRequest` rows, and none of the successful `GET` rows has bodies;
- there are `ActivityEvent` rows of type `custom`.

Add a smoke test, "every main page loads with demo data": acting as the demo owner, `get` each of these routes and `assertOk()`:

- `marketing.contacts.index`, a contact's `marketing.contacts.show` (one with custom events);
- `marketing.campaigns.index`, a sent campaign's `marketing.campaigns.show`;
- `marketing.templates.index`, `marketing.automations.index`, a run automation's `marketing.automations.edit`;
- `marketing.imports.index`, the completed import's `marketing.imports.show`;
- `marketing.segments.index`, `marketing.members.index`, `marketing.roles.index`;
- `marketing.api-tokens.index`, `marketing.api-log.index`.

Check the names with `php artisan route:list --name=marketing.` and drop any that don't exist.

Add a test that `php artisan db:seed` on a fresh test database creates the demo workspace for `test@example.com`. Use `$this->seed()`.

**Verify**:

- (root) `php artisan test tests/Feature/DemoDataCommandTest.php` → all pass;
- then `php artisan test` → all pass;
- `vendor/bin/phpstan analyse --memory-limit=1G` → 0 errors;
- `vendor/bin/pint --test` → passes.

Also time the command, since a slow seed is annoying. `php artisan app:demo-data --email=timing@example.com` against a throwaway SQLite database should finish in under ~20 seconds. To get one, set `DB_CONNECTION=sqlite` and `DB_DATABASE` to a file in your worktree, then `php artisan migrate --force` first. **Never run it against the main checkout's database.**

## Done criteria

- [ ] The demo workspace has teammates in each built-in role, a custom role and a pending invitation
- [ ] It has 3 email templates, 2 imports (one with errors), 3 automations (one active with runs) and 2 API tokens with about 120 API log rows
- [ ] Contacts have custom events on their timelines
- [ ] `php artisan migrate:fresh --seed` creates the demo workspace for `test@example.com`
- [ ] The extended and new tests pass, including the page smoke test; the full suite, PHPStan and Pint pass

## STOP conditions

- The drift check shows changes these notes don't cover.
- Creating any of this needs a change under `packages/core`. Report what's missing instead.
- Any part queues a job or makes a network call (watch for model events and observers: the command's existing approach must keep working).

## Maintenance notes

- When a new page or feature lands, add a little demo data for it here and a line in the smoke test, so manual testing always has something to click.
- The demo tokens' hashes are random, so they can't call the API. For a working token, create one in the UI.
