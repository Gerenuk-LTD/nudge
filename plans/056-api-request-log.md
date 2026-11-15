# Plan 056: An API request log in workspace settings, each row expanding into its request and response

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say otherwise. If anything in "STOP conditions" happens, stop
> and report. Don't improvise. When done, update this plan's row in
> `plans/README.md`, unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 6982215..HEAD -- packages/core/src/MarketingServiceProvider.php packages/core/src/Http/Middleware packages/core/src/Models/ApiToken.php packages/core/config/marketing.php packages/core/routes/web.php packages/core/resources/js/components/data-table.tsx packages/core/resources/js/layouts/workspace-settings-layout.tsx`
> If any of these changed, compare them with the "Current state" excerpts.
> On a mismatch, STOP. Plan 053 adds a `previous_link_keys` key to
> `config/marketing.php`; that's expected. Changes elsewhere in
> `routes/web.php` are fine.

## Status

- **Priority**: P2 (feature requested by the maintainer, 2026-11-15)
- **Effort**: M
- **Risk**: LOW–MED. It adds a database write to every authenticated API request, and stores request and response bodies, which hold contact data (see "Privacy").
- **Depends on**: none. If plan 051 has landed, it already follows the same `contacts.view` rule this plan uses.
- **Category**: direction (feature)
- **Planned at**: commit `6982215`, 2026-11-15

## Why this matters

People connecting other tools to the API can't see what those tools sent or what came back. When a sync misbehaves, the only way to find out is the client's own logs. The maintainer asked for a log like the reference they shared: a settings tab listing every API call (time, token, method and endpoint, status), filterable by token, endpoint and date, where each row expands into the raw request and response.

## What it looks like (from the maintainer's reference)

A new **API log** tab in Workspace settings, next to **API tokens**:

- Title "API log", description "Every request made with this workspace's API tokens. Kept for 30 days; request and response bodies for 7." (both numbers come from config).
- A toolbar: search (path), **Token** filter (faceted, by token name), **Endpoint** filter (faceted, by route: "PUT contacts/{email}"), **Status** filter (Success / Client error / Server error), and a **Period** choice (Last 24 hours, Last 7 days, Last 30 days; default 7 days). No date picker component exists, so presets it is.
- Columns: chevron, **Time** (`Nov 3, 10:24`), **Token** (name), **Request** (`PUT` badge + path, monospaced), **Status** (coloured dot + code: green 2xx, amber 4xx, red 5xx), **Duration** (`84 ms`, numeric).
- Clicking the chevron expands the row: "Request" and "Response" blocks with pretty-printed JSON in a monospaced, scrollable `<pre>`, plus a small line with IP, user agent and the request ID. Bodies load when the row expands, not with the list.

## Decisions made in this plan (the maintainer can override before execution)

1. **What's logged:** every request that passes token authentication, including 403s from missing token permissions, 404s, 422s and 429s from the per-token limit. **Not** logged: requests with a missing or invalid token (no workspace to put them in), and the public docs routes.
2. **Bodies, only where they help debugging:** the request and response bodies are stored for **writes** (any method other than `GET` and `HEAD`) and for **errors** (any status ≥ 400, whatever the method). A **successful read** (`GET`/`HEAD` with status < 400) gets a row with no bodies: its response is a dump of contact data with little debugging value. This follows the services the maintainer compared (Stripe, Mollie, Klaviyo, MailerSend, Resend), which show bodies but limit how much and how long they keep. Stored bodies are each cut to 16 KB (`marketing.api_log.max_body_bytes`) and end with a note saying how much was cut.
3. **Never stored:** request headers (the `Authorization` header holds the token), except the user agent. Query strings are stored as part of the path.
4. **Retention, in two parts (as Mollie does):** bodies are cleared after **7 days** (`MARKETING_API_LOG_BODY_DAYS`); the rows themselves (time, token, endpoint, status, duration) are deleted after **30 days** (`MARKETING_API_LOG_DAYS`). One daily job does both. `MARKETING_API_LOG=false` turns logging off.
5. **Who can see it:** the tab needs `api_tokens.manage`. The request and response **bodies** also need `contacts.view`; without it the row expands to "You need permission to view contacts to see request and response bodies."
6. **Deleted tokens:** log rows keep the token's name (copied at write time), and their `api_token_id` becomes null.
7. **Token lifecycle events** (created, revoked), as in the reference: not in this plan. Possible follow-up; see Maintenance notes.

## Privacy

Request and response bodies contain contact emails, names and custom fields. That's why successful reads keep no bodies, stored bodies are cleared after 7 days and cut to 16 KB, and they're shown only to members who can view contacts. Mention the log in `DEPLOYING.md` (Step 7) so operators know it exists and how to turn it off or shorten it.

## Current state

**API routes** are registered in `src/MarketingServiceProvider.php::registerRoutes()`:

```php
Route::prefix(config('marketing.api_prefix'))
    ->middleware(['api', 'marketing.api', 'throttle:marketing-api'])
    ->name('marketing.api.')
    ->group(__DIR__.'/../routes/api.php');
```

Middleware aliases are in `registerMiddleware()` (line ~100):

```php
$router->aliasMiddleware('marketing.workspace', SetCurrentWorkspace::class);
$router->aliasMiddleware('marketing.api', AuthenticateApiToken::class);
$router->aliasMiddleware('marketing.token-can', EnsureTokenCan::class);
```

**`src/Http/Middleware/AuthenticateApiToken.php`** authenticates the bearer token, sets the current workspace, and stores the token on the request: `$request->attributes->set('marketing_api_token', $token);`. It returns a 401 JSON response for missing, invalid or expired tokens. `EnsureTokenCan` returns 403 JSON when the token lacks a permission.

**`src/Models/ApiToken.php`**: properties `id`, `workspace_id`, `created_by`, `name`, `token_hash`, `permissions`, `last_used_at`, `expires_at`. `#[Fillable([...])]` attribute style. Copy its model shape (attributes, `casts()`, docblock `@property` lines, workspace scoping) for the new model. Check how it's scoped to the workspace (`grep -n "ScopedBy\|BelongsToWorkspace\|trait" src/Models/ApiToken.php`) and do the same.

**Settings page and nav**: `src/Http/Controllers/ApiTokenController.php::index()` renders `marketing/workspace/api-tokens`. Routes `routes/web.php:177-181`:

```php
Route::middleware('can:api_tokens.manage')->group(function () {
    Route::get('api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
```

Tabs are in `resources/js/layouts/workspace-settings-layout.tsx` (`workspaceSettingsTabs`):

```tsx
{
    title: 'API tokens',
    href: apiTokensIndex(),
    icon: Code,
    permission: 'api_tokens.manage',
},
```

**Table building blocks** (copy from `resources/js/pages/marketing/campaigns/show.tsx:470-510`, the recipients table): `useTableQuery({ only: [...] })`, `TableSearch`, `FacetedFilter`, `ChoiceFilter`, `ResetFilters` (from `@marketing/components/table-toolbar`), `DataTable` with `sort={query.sort}`, `DataTableSkeleton`, `Pagination`. Server side, `src/Support/TableQuery.php` has `sort()`, `search()`, `perPage()` and `values()`; see `CampaignController::recipients()` (line ~364) for their use with `paginate(...)->withQueryString()->through(...)`.

**`DataTable` has no expandable rows** (`resources/js/components/data-table.tsx:49`). Its props are `columns`, `data`, `getRowId`, `emptyMessage`, `sortable`, `sort`. It uses TanStack's `useReactTable` with `getCoreRowModel` and `getSortedRowModel`.

**Daily pruning pattern**: `src/Jobs/PruneStaleImports.php` (an invokable class with a docblock explaining why), scheduled in `registerSchedule()`:

```php
$schedule->call(new PruneStaleImports)->daily()->name('marketing:prune-stale-imports')->withoutOverlapping(60);
```

**Migrations** live in `database/migrations/`; the latest is `2026_11_13_000001_add_variables_to_campaigns_table.php`. Name yours `2026_11_15_000001_create_api_requests_table.php` (bump the date if a later one exists).

**Tests**: `tests/Feature/ApiTest.php` has `function apiToken(Workspace $workspace, $user, array $permissions = [...]): string` returning a plain token, and requests like `$this->withToken($token)->getJson(route('marketing.api.contacts.index'))`. It's a global Pest function; call it, don't redeclare it.

## Commands

From `packages/core` (PowerShell: `php`/`composer`; Git Bash: `php.bat`/`composer.bat`), unless they say "root".

| Purpose         | Command                                                   | Expected                                      |
| --------------- | --------------------------------------------------------- | --------------------------------------------- |
| Core tests      | `vendor/bin/pest`                                         | all pass, except the 7 Windows-only SES tests |
| One file        | `vendor/bin/pest tests/Feature/ApiRequestLogTest.php`     | all pass                                      |
| PHPStan (root)  | `vendor/bin/phpstan analyse --memory-limit=1G`            | 0 errors                                      |
| Pint (root)     | `vendor/bin/pint --test packages/core`                    | passes                                        |
| Frontend (root) | `npm run check`, `npm run types:check`, `npm run test`    | pass                                          |

## Scope

**In scope:**

- `database/migrations/2026_11_15_000001_create_api_requests_table.php` (new)
- `src/Models/ApiRequest.php` (new)
- `src/Http/Middleware/LogApiRequest.php` (new)
- `src/Jobs/PruneApiRequests.php` (new)
- `src/Http/Controllers/ApiRequestController.php` (new)
- `src/MarketingServiceProvider.php` (alias, API middleware list, schedule)
- `config/marketing.php` (an `api_log` block)
- `routes/web.php` (two routes in the `api_tokens.manage` group)
- `resources/js/components/data-table.tsx` (optional expandable rows)
- `resources/js/pages/marketing/workspace/api-log.tsx` (new)
- `resources/js/layouts/workspace-settings-layout.tsx` (one tab)
- `tests/Feature/ApiRequestLogTest.php` (new)
- `DEPLOYING.md` at the repo root (one short section)

**Out of scope:**

- Logging web (Inertia) requests, webhooks from providers, or tracking hits.
- Token lifecycle events (created / revoked / denied) as log rows.
- The OpenAPI spec. The log isn't part of the API, so `docs/openapi.json` must not change (the drift test would catch it).
- Exporting the log.

## Git workflow

- Branch off `dev`: `advisor/056-api-request-log`.
- Commits per step are fine, e.g. `feat(core): log API requests per workspace`, each ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Table, model, config

Migration, table `api_requests`:

```php
$table->id();
$table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
$table->foreignId('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
$table->string('token_name', 100);
$table->string('method', 10);
$table->string('route', 100)->nullable();   // the route's URI pattern, e.g. "contacts/{email}"
$table->string('path', 2048);               // the real path with query string
$table->unsignedSmallInteger('status');
$table->unsignedInteger('duration_ms');
$table->string('ip', 45)->nullable();
$table->string('user_agent', 255)->nullable();
$table->mediumText('request_body')->nullable();
$table->mediumText('response_body')->nullable();
$table->timestamp('created_at')->useCurrent();

$table->index(['workspace_id', 'created_at']);
$table->index(['workspace_id', 'api_token_id', 'created_at']);
```

No `updated_at`: rows are never updated. Check the API tokens table name (`grep -rn "create('api_tokens'\|Schema::create('api" database/migrations`).

Model `ApiRequest`: workspace-scoped like `ApiToken`; `public const UPDATED_AT = null;`; `#[Fillable([...all columns except id...])]`; `@property` docblock; casts `created_at` datetime, `status`/`duration_ms` int; a `token()` `BelongsTo` to `ApiToken` (`api_token_id`).

`config/marketing.php`, after `api_docs`:

```php
// Every authenticated API request, shown under Workspace settings → API log. Bodies (cut to max_body_bytes) are
// kept only for writes and errors, and cleared after body_retention_days because they hold contact data. The rows
// themselves go after retention_days.
'api_log' => [
    'enabled' => (bool) env('MARKETING_API_LOG', true),
    'retention_days' => (int) env('MARKETING_API_LOG_DAYS', 30),
    'body_retention_days' => (int) env('MARKETING_API_LOG_BODY_DAYS', 7),
    'max_body_bytes' => 16384,
],
```

If `body_retention_days` is larger than `retention_days`, the rows go first and the bodies with them; nothing needs to enforce the order.

**Verify**: `vendor/bin/pest tests/Feature/ApiTest.php` → all pass (the migration runs).

### Step 2: The logging middleware

`src/Http/Middleware/LogApiRequest.php`:

```php
/**
 * Records each authenticated API request and its response for the workspace's API log. Runs after the token is
 * authenticated, so requests without a valid token (which belong to no workspace) aren't recorded. A failure to
 * record never fails the request.
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $next($request);

        if (config('marketing.api_log.enabled')) {
            try {
                $this->record($request, $response, (int) ((hrtime(true) - $started) / 1_000_000));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $response;
    }
```

`record()`:

- `$token = $request->attributes->get('marketing_api_token')`; return early unless it's an `ApiToken`.
- `workspace_id` from `$token->workspace_id` (set explicitly; don't rely on the scope's creating hook alone, since the current workspace is set but be explicit anyway).
- `route` from `$request->route()?->uri()`, with the API prefix stripped (`Str::after($uri, trim(config('marketing.api_prefix'), '/').'/')`), so it reads `contacts/{email}`.
- `path` from `$request->getRequestUri()`, cut to 2048 characters.
- Decide whether to keep bodies with a private `keepsBodies(Request $request, Response $response): bool`: `! in_array($request->getMethod(), ['GET', 'HEAD'], true) || $response->getStatusCode() >= 400`. When it's false, store both bodies as null and skip the rest of this bullet.
- `request_body`: `$request->getContent()`, or null when empty. `response_body`: `$response->getContent()`; for a `StreamedResponse` or `BinaryFileResponse` (no content) store null. Pass both through a private `cut(?string $body): ?string` that keeps the first `max_body_bytes` bytes (use `mb_strcut` so a multi-byte character isn't split) and appends `"\n… cut, {N} more bytes"` when it cut.
- `user_agent` cut to 255 with `Str::limit($ua, 255, '')`.
- `ApiRequest::create([...])`.

Register the alias `marketing.api-log` in `registerMiddleware()`, and add it to the API group **right after `marketing.api`**:

```php
->middleware(['api', 'marketing.api', 'marketing.api-log', 'throttle:marketing-api'])
```

Placed before the throttle, the per-token 429s are logged too. Placed after `marketing.api`, invalid-token 401s never reach it.

Check the order with `php artisan route:list --path=api/v1/contacts -v` from the repo root; the middleware column should list it after `AuthenticateApiToken`.

**Verify**: `vendor/bin/pest tests/Feature/ApiTest.php tests/Feature/EventIdempotencyTest.php` → all pass, unchanged.

### Step 3: Pruning

`src/Jobs/PruneApiRequests.php`, modelled on `PruneStaleImports`. It does two things, in this order:

1. **Clear old bodies:** for rows with `created_at` older than `now()->subDays(config('marketing.api_log.body_retention_days'))` and a non-null `request_body` or `response_body`, set both to null.
2. **Delete old rows:** rows with `created_at` older than `now()->subDays(config('marketing.api_log.retention_days'))`.

Use `ApiRequest::withoutGlobalScopes()` for both, and work in chunks (`->chunkById(1000, fn ($rows) => ApiRequest::withoutGlobalScopes()->whereKey($rows->modelKeys())->update([...]) / ->delete())`, or a looped `limit(5000)`; pick whichever works on both SQLite and MySQL and say which). The docblock says why: bodies hold contact data, so they go sooner than the rows. Schedule it daily next to `PruneStaleImports`, named `marketing:prune-api-requests`, `withoutOverlapping(60)`.

**Verify**: PHPStan (root) → 0 errors.

### Step 4: Controller and routes

`src/Http/Controllers/ApiRequestController.php`:

- `index(Request $request): Response` renders `marketing/workspace/api-log` with:
    - `requests`: a paginated list (`TableQuery::perPage($request, 50)`), newest first, filtered by
        - `token`: `TableQuery::values($request->input('token'))` → `whereIn('api_token_id', ...)`;
        - `route`: `TableQuery::values(...)` of `"METHOD route"` strings → split on the first space into method and route, OR-ed together;
        - `status`: values from `success`, `client_error`, `server_error` → `status` between 200–399, 400–499, 500–599;
        - `period`: `24h`, `7d` (default) or `30d` → `created_at >= now()->sub...`;
        - `search` on `path` via `TableQuery::search`.

      Each row: `id`, `created_at` (ISO), `token_name`, `method`, `path`, `status`, `duration_ms`. **No bodies.**
    - `tokens`: `ApiToken::query()->orderBy('name')->get(['id', 'name'])` as facet options `{ value: string(id), label: name }`.
    - `routes`: distinct `method` + `route` pairs from this workspace's log (`ApiRequest::query()->select('method', 'route')->distinct()->orderBy('route')->get()`), as `{ value: "PUT contacts/{email}", label: same }`.
    - `retentionDays` and `bodyRetentionDays`: from config.
    - `canViewBodies`: the user has `contacts.view` in the current workspace.

    Use `Inertia::defer` for `requests`, as the recipients table does.
- `show(Request $request, ApiRequest $apiRequest): JsonResponse` returns `request_body`, `response_body`, `ip`, `user_agent`, `route`, and `bodies_missing`, one of:
    - `'hidden'`: the user lacks `contacts.view`. Return both bodies as null, even when they're stored. Check this first.
    - `'not_kept'`: a successful read (`GET`/`HEAD`, status < 400), whose bodies are never stored.
    - `'cleared'`: both bodies are null and the row is older than `body_retention_days`.
    - `null`: otherwise (the bodies are shown; either may still be null because it was empty). Route model binding is workspace-scoped through the model's scope, so another workspace's id is a 404. Confirm with test 6.

Routes, inside the `can:api_tokens.manage` group:

```php
Route::get('api-log', [ApiRequestController::class, 'index'])->name('api-log.index');
Route::get('api-log/{apiRequest}', [ApiRequestController::class, 'show'])->name('api-log.show');
```

**Verify**: `vendor/bin/pest` → all pass.

### Step 5: Expandable rows in `DataTable`

Add two optional props:

```ts
/** When given, each row gets a chevron that shows this under it. Only the row's own content is rendered, on demand. */
renderExpanded?: (row: TData) => ReactNode;
```

When `renderExpanded` is set:

- add TanStack's `getExpandedRowModel()` and `getRowCanExpand: () => true` to `useReactTable`, with `expanded` state;
- render a leading 40px header cell (empty, `sr-only` text "Details") and, in each row, a ghost icon button with `ChevronRight` rotated 90° when expanded (`aria-expanded`, `aria-label="Show details"`), calling `row.toggleExpanded()`;
- after an expanded row, render `<TableRow className="hover:bg-transparent"><TableCell colSpan={columns.length + 1} className="bg-muted/30 p-4">{renderExpanded(row.original)}</TableCell></TableRow>`.

Without the prop, the table renders exactly as before. Check `lucide-react` is the icon library (`grep -n "lucide-react" resources/js/components/data-table.tsx resources/js/layouts/workspace-settings-layout.tsx`).

**Verify** (root): `npm run types:check` → passes; `npm run test` → passes. If the repo has a `data-table` unit test (`find resources/js -name "*data-table*.test.*"`), add a case there: rows render no chevron without `renderExpanded`, and clicking the chevron shows the expanded content.

### Step 6: The page and tab

`resources/js/pages/marketing/workspace/api-log.tsx`, using `WorkspaceSettingsLayout` exactly as `api-tokens.tsx` does (copy its layout wrapper, `Head` and heading pattern):

- Heading "API log" and description "Every request made with this workspace's API tokens. Kept for {retentionDays} days; request and response bodies for {bodyRetentionDays}."
- Toolbar as described in "What it looks like". Use `TableSearch` (placeholder "Search paths"), `FacetedFilter` for Token, Endpoint and Status, `ChoiceFilter` for Period (default `7d`), and `ResetFilters`.
- `DataTable` with `renderExpanded={(row) => <RequestDetail id={row.id} canViewBodies={canViewBodies} />}`.
- Columns:
    - Time: `formatDateTime` (find the existing helper: `grep -rn "export function formatDateTime" resources/js`).
    - Token: `token_name`.
    - Request: `<span className="font-mono text-xs">` with a small method badge (reuse `StatusBadge` if its tones fit; otherwise a `rounded border px-1.5` span) followed by the path, truncated with `title` set to the full path.
    - Status: a dot (`size-1.5 rounded-full`, green / amber / red, using the same colour tokens other status dots in the app use: `grep -rn "rounded-full bg-" resources/js/components/*.tsx | head`) and the code.
    - Duration: `{duration_ms} ms`, `meta: { numeric: true }`.
- `RequestDetail` (in the same file): fetches `ApiRequestController.show.url(id)` with `fetch` (the repo's other JSON fetches, e.g. segment preview, show the pattern: `grep -rn "preview.url\|fetch(" resources/js/pages/marketing/segments/form.tsx`) when it mounts, then shows:
    - "Request" and "Response" headings, each with a `<pre className="max-h-96 overflow-auto rounded-lg border bg-card p-3 font-mono text-xs">`. Pretty-print with `JSON.stringify(JSON.parse(body), null, 2)` inside a try/catch, falling back to the raw text. Show "No body" for null.
    - A muted line: IP · user agent.
    - Instead of the two blocks, when `bodies_missing` is:
        - `'hidden'`: "You need permission to view contacts to see request and response bodies."
        - `'not_kept'`: "Bodies aren't kept for successful reads."
        - `'cleared'`: "Bodies are kept for {bodyRetentionDays} days."
- Empty state: when there are no rows and no filters, "No API requests yet. Requests made with your API tokens appear here." (use the shared `EmptyState` if it fits a table area; check `resources/js/components/empty-state.tsx`).

Add the tab after "API tokens" in `workspace-settings-layout.tsx`: title "API log", icon `ScrollText` from `lucide-react` (or `Logs`; whichever exists in the installed version), `permission: 'api_tokens.manage'`, `href` from the generated `@/routes/marketing/api-log` `index`. Wayfinder generates the route helpers; if they're missing, run the repo's generation (`php artisan wayfinder:generate` at the root, if that command exists). Don't commit generated files.

Per the project's UI rule, explanations of controls go in tooltips, not in notes under fields.

**Verify** (root): `npm run check`, `npm run types:check`, `npm run test` → pass.

### Step 7: Docs

In `DEPLOYING.md`, next to the API docs section, add "API log": what it records; that request and response bodies (which hold contact data) are kept only for writes and errors, cut to 16 KB and cleared after `MARKETING_API_LOG_BODY_DAYS` days (default 7); that rows go after `MARKETING_API_LOG_DAYS` days (default 30); that `MARKETING_API_LOG=false` turns it off; and that the token itself is never stored. Three or four sentences, matching the file's tone.

**Verify**: `grep -n "MARKETING_API_LOG_BODY_DAYS" ../../DEPLOYING.md` → matches.

### Step 8: Tests

Create `tests/Feature/ApiRequestLogTest.php`. `beforeEach`: `[$this->owner, $this->workspace] = $this->createOwner();`. Use `apiToken(...)` from `ApiTest.php`.

1. **A request is logged with its bodies.** PUT a contact through `marketing.api.contacts.upsert`. One `ApiRequest` row: method `PUT`, route `contacts/{email}`, status 200 or 201 (whatever the API returns), the token's name, a request body containing the email, a response body containing the email, `duration_ms` ≥ 0.
2. **The token is never stored.** The plain token string appears in no column of the row (check `request_body`, `response_body`, `path`, `user_agent`).
3. **Invalid tokens aren't logged.** A request with a bad token → 401 and no row.
4. **A missing permission is logged.** A token without `contacts.manage` doing the PUT → 403 and a row with status 403.
5. **Long bodies are cut.** With `config(['marketing.api_log.max_body_bytes' => 100])`, the PUT from test 1 (its response must be longer than 100 bytes; check) → `response_body` is at most 100 bytes plus the cut note, and ends with "more bytes".
6. **Workspaces are kept apart.** A second workspace's owner can't see the first's row on `api-log.index` (not in `requests`), and `api-log.show` for it → 404.
7. **The page needs `api_tokens.manage`.** An Editor (`$this->addMember($this->workspace, 'Editor')`; check whether Editor has `api_tokens.manage`, and use a custom role without it if it does) → 403 on both routes.
8. **Bodies need `contacts.view`.** A custom role with `api_tokens.manage` only → `api-log.show` for the PUT's row returns null bodies and `bodies_missing` `'hidden'`.
9. **Filters.** Three rows with different tokens, statuses and routes; `token`, `status` and `route` filters each return only the matching rows. A row created 10 days ago (set `created_at`) shows with `period=30d` and not with the default.
10. **Pruning.** Rows 31 days, 8 days and 1 day old, all with bodies. `(new PruneApiRequests)()` deletes the 31-day row, clears both bodies on the 8-day row (the row stays), and leaves the 1-day row's bodies alone. `api-log.show` for the 8-day row returns `bodies_missing` `'cleared'`.
11. **Logging can be turned off.** With `marketing.api_log.enabled` false, a request leaves no row.
12. **A deleted token keeps its rows.** Delete the token; the row stays with `api_token_id` null and `token_name` intact.
13. **Successful reads keep no bodies.** A `GET` on `marketing.api.contacts.index` → 200 and a row with both bodies null; `api-log.show` returns `bodies_missing` `'not_kept'`.
14. **Failed reads keep their bodies.** A `GET` on `marketing.api.contacts.show` for an email that doesn't exist → 404 and a row with the response body stored.

**Revert checks** (restore after each, commit nothing from them):

- Remove `marketing.api-log` from the API middleware list: tests 1 and 4 fail.
- Remove the `contacts.view` check in `show()`: test 8 fails.
- Make `keepsBodies()` always return true: test 13 fails.
- Remove the body-clearing part of `PruneApiRequests`: test 10 fails.

**Verify**: `vendor/bin/pest` → all pass (apart from the 7 SES failures), including `ApiDocsTest` (the spec mustn't change); PHPStan → 0 errors; Pint → passes; (root) `npm run check`, `npm run types:check`, `npm run test` → pass.

## Done criteria

- [ ] Every authenticated API request writes one `api_requests` row with method, route, path, status, duration, token name, IP and user agent
- [ ] Bodies (cut to 16 KB) are stored for writes and errors only; successful `GET`/`HEAD` rows have none
- [ ] Invalid-token requests and the docs routes aren't logged; the token itself is never stored
- [ ] Workspace settings has an "API log" tab (needs `api_tokens.manage`) with token, endpoint, status and period filters and search
- [ ] Rows expand to the request and response, loaded on demand; bodies need `contacts.view`, and a missing body says why
- [ ] The daily job clears bodies older than `MARKETING_API_LOG_BODY_DAYS` and deletes rows older than `MARKETING_API_LOG_DAYS`; `MARKETING_API_LOG=false` turns logging off
- [ ] `DataTable` without `renderExpanded` renders as before
- [ ] `docs/openapi.json` is unchanged
- [ ] All 14 tests pass and the revert checks fail as described
- [ ] Pest, PHPStan, Pint, `npm run check`, `types:check` and `npm run test` pass
- [ ] `plans/README.md` row updated

## STOP conditions

- The drift check shows changes the excerpts don't cover.
- `ApiToken`'s workspace scoping works differently from what the model reveals, so the new model's scoping is unclear.
- The logging middleware changes any existing API test's outcome (it must be invisible to clients).
- `docs/openapi.json` would change.
- Adding expandable rows to `DataTable` needs changes to its existing props or behaviour.

## Maintenance notes

- **Load:** one insert per API request. The per-token rate limit (`MARKETING_API_REQUESTS_PER_MINUTE`, default 120) bounds it. If an install pushes far more, consider moving the write to `terminate()` or a queue.
- **Follow-up, from the maintainer's reference:** log token lifecycle events (created, permissions narrowed, revoked) as rows in the same view, with an "Action" filter. That needs a `type` column and rows without a request; kept out to keep this plan focused.
- If plan 051 (contact data needs `contacts.view`) is reverted or changed, keep this page's body rule in line with it.
- New API endpoints are logged automatically. Endpoints returning files would log a null response body; that's intended.
- The body rule goes by HTTP method. A future endpoint that writes data over `GET` (it shouldn't) or reads over `POST` (a search endpoint, say) would be logged by method, not by what it does. If a `POST` search endpoint is added, consider listing its route in `keepsBodies()` as a read.
- Masking fields inside stored bodies (as Stripe does for card numbers) isn't needed: the API takes no passwords or card data. Revisit if an endpoint ever accepts secrets, such as provider credentials.
