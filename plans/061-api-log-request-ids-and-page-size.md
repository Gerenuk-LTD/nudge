# Plan 061: API log request IDs become `req_<ulid>`, sent as `X-Request-Id`, and the log shows 25 rows a page

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say "root". If anything in "STOP conditions" happens, stop
> and report. Don't improvise. Don't edit `plans/README.md`; the reviewer
> maintains it.
>
> **Drift check (run first)**:
> `git diff --stat 16a296d..HEAD -- packages/core/src/Http/Middleware/LogApiRequest.php packages/core/src/Models/ApiRequest.php packages/core/src/Http/Controllers/ApiRequestController.php packages/core/resources/js/pages/marketing/workspace/api-log.tsx packages/core/tests/Feature/ApiRequestLogTest.php`
> If any changed, compare them with "Current state". On a mismatch, STOP.

## Status

- **Priority**: P3 (requested by the maintainer, 2026-11-15)
- **Effort**: S–M
- **Risk**: LOW. It adds a column, a response header and a few UI changes. The app is unreleased, so there's no backfill.
- **Depends on**: 056, 058 and 060 (all done)
- **Category**: direction (UX)
- **Planned at**: commit `16a296d`, 2026-11-15

## Why this matters

1. **Request IDs.** The log shows "Request #4821", which is the row's auto-increment ID. That table is shared by every workspace, so the number reveals roughly how many API requests the whole platform has served. It's also easy to misread, and API clients never see it, so they can't use it to find their own request.

    The fix:
    - give each request a ULID, shown as `req_<ulid>` the way Stripe and Resend do;
    - send it on every logged API response as an `X-Request-Id` header;
    - let the search box find it.

    An integrator can then copy the ID from their client's logs and jump straight to the request. The integer `id` stays as the primary key, for sorting, routing and `chunkById` pruning.

2. **Page size.** The log shows 50 rows a page, so you have to scroll a long way to reach the pagination, more so with rows expanded. Every other table in the app defaults to 25.

    The fix:
    - default to 25; the page-size picker still offers larger sizes;
    - show "1–25 of 340" in the toolbar, so the total is visible without scrolling.

## Current state

**`src/Models/ApiRequest.php`**:

- uses `BelongsToWorkspace`;
- `public const UPDATED_AT = null;`;
- `#[Fillable([... 'created_at'])]`;
- casts for `status`, `duration_ms` and `created_at`;
- `token()`.

**`src/Http/Middleware/LogApiRequest.php::handle()`**:

```php
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
```

`record()` calls `ApiRequest::create([...])` with `workspace_id`, `api_token_id`, `token_name`, `method`, `route`, `path`, `status`, `duration_ms`, `ip`, `user_agent`, `request_body` and `response_body`.

**`src/Http/Controllers/ApiRequestController.php`**:

- **`requests()`:**
    - works out `$byId = preg_match('/^#?\d+$/', $search) === 1;`;
    - skips the period clause when `$byId`;
    - later does `if ($byId) { $query->whereKey((int) ltrim($search, '#')); } else { TableQuery::search($query, $search, ['path']); }`;
    - paginates with `TableQuery::perPage($request, 50)` and the columns `['id', 'created_at', 'token_name', 'method', 'path', 'status', 'duration_ms']`.
- **`show()`** returns `request_body`, `response_body`, `ip`, `user_agent`, `route` and `bodies_missing`.

**`resources/js/pages/marketing/workspace/api-log.tsx`**:

- `type Detail` (the `show` response);
- the search placeholder "Search paths or #ID";
- in `RequestDetail`, the bottom line is

    ```tsx
    Request #{id}
    <CopyButton text={String(id)} label="Copy request ID" className="size-5" />
    ```

- the toolbar ends with `ResetFilters`, then the refresh button (`ml-auto`);
- `requests` is `Paginated<LogRow>`, which has `from`, `to` and `total`.

**`tests/Feature/ApiRequestLogTest.php`**:

- test 15, "a request can be found by its ID", searches `#<id>` and `<id>`;
- `logRow($workspace, [...])` creates rows through the model.

**Laravel's `HasUlids`** (`Illuminate\Database\Eloquent\Concerns\HasUlids`) fills the columns named by `uniqueIds()` when they're empty, with lowercase ULIDs. If `uniqueIds()` doesn't include the primary key, the integer `id` stays the auto-incrementing key. Check this in the installed version: read `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasUniqueStringIds.php` (or `HasUlids.php`) and confirm `getKeyType()` and `getIncrementing()` only change when the key is in `uniqueIds()`.

## Commands

From `packages/core` (PowerShell: `php`; Git Bash: `php.bat`), unless they say "root".

| Purpose         | Command                                                | Expected                                      |
| --------------- | ------------------------------------------------------ | --------------------------------------------- |
| One file        | `vendor/bin/pest tests/Feature/ApiRequestLogTest.php`  | all pass                                      |
| Core tests      | `vendor/bin/pest`                                      | all pass, except the 7 Windows-only SES tests |
| PHPStan (root)  | `vendor/bin/phpstan analyse --memory-limit=1G`         | 0 errors                                      |
| Pint (root)     | `vendor/bin/pint --test packages/core`                 | passes                                        |
| Frontend (root) | `npm run check`, `npm run types:check`, `npm run test` | pass                                          |

## Scope

**In scope:**

- a new migration `database/migrations/2026_11_15_000003_add_ulid_to_api_requests_table.php` (bump the number if `000003` is taken);
- `src/Models/ApiRequest.php`;
- `src/Http/Middleware/LogApiRequest.php`;
- `src/Http/Controllers/ApiRequestController.php`;
- `resources/js/pages/marketing/workspace/api-log.tsx`;
- `tests/Feature/ApiRequestLogTest.php`;
- `DEPLOYING.md` (root): one sentence in the "API log" section.

**Out of scope:**

- the routes (`api-log/{apiRequest}` keeps the integer key; it's internal);
- `docs/openapi.json` (it must not change; `ApiDocsTest` checks it);
- other tables' page sizes;
- `DataTable` and `Pagination`.

## Git workflow

- Branch off `dev`: `advisor/061-api-log-request-ids`.
- Commits per step are fine, such as `feat(core): give API requests a req_ ID and send it as X-Request-Id`. Each ends with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: The `ulid` column and model

Migration:

```php
Schema::table('api_requests', function (Blueprint $table) {
    $table->char('ulid', 26)->nullable()->unique()->after('id');
});
```

Its `down()` drops the unique index and the column. The column is nullable only because rows logged before this migration have no ULID. The app is unreleased, so don't backfill them.

In `ApiRequest`:

- `use HasUlids;`
- add `public function uniqueIds(): array { return ['ulid']; }` with a `@return list<string>` docblock;
- add `ulid` to `#[Fillable]`;
- add `@property string|null $ulid`;
- add `public function publicId(): ?string { return $this->ulid === null ? null : 'req_'.$this->ulid; }`, with a docblock saying it's the ID shown to people and sent as `X-Request-Id`.

**Verify**:

- `vendor/bin/pest tests/Feature/ApiRequestLogTest.php` → all pass;
- a quick check in a test or tinker that `ApiRequest::create([...])->id` is still an int and `ulid` is a 26-character lowercase string.

If the integer key stops auto-incrementing, STOP.

### Step 2: The middleware sets the ID and the header

In `handle()`:

- **Before `$next($request)`:** create the ID with `$ulid = strtolower((string) Str::ulid());`.
- **After `$next($request)`, before the logging check:** set the header with `$response->headers->set('X-Request-Id', 'req_'.$ulid);`. Every response that reaches this middleware carries the ID, even when logging is off, so client logs stay useful.
- **When recording:** pass `$ulid` to `record()` and include `'ulid' => $ulid` in `ApiRequest::create([...])`.

Requests rejected for a bad token never reach this middleware, so they get no header. That's fine.

**Verify**: `vendor/bin/pest tests/Feature/ApiTest.php tests/Feature/ApiDocsTest.php tests/Feature/ApiRequestLogTest.php` → all pass, and `git diff --stat -- ../../docs` (from `packages/core`) shows nothing.

### Step 3: Search by `req_` ID, and 25 rows a page

In `ApiRequestController::requests()`:

- **The ID match:**

    ```php
    $byId = preg_match('/^(req_)?[0-9a-hjkmnp-tv-z]{26}$/i', $search) === 1;
    ```

    When it matches, filter with `$query->where('ulid', strtolower(Str::after($search, 'req_')))`. (`Str::after` returns the whole string when the prefix is missing.) Keep skipping the period clause when `$byId`.

- **Drop the numeric `#ID` search:** IDs are no longer shown, and the app is unreleased.
- **The page size:** change `TableQuery::perPage($request, 50)` to `TableQuery::perPage($request)` (default 25).
- **The row columns:** add `ulid` to the paginate columns, and `'request_id' => $row->publicId()` to each mapped row.
- **`show()`:** add `'request_id' => $apiRequest->publicId()` to the JSON.

**Verify**: PHPStan → 0 errors.

### Step 4: The page

In `api-log.tsx`:

- **Types:** add `request_id: string | null` to `LogRow` and to `Detail`.
- **Search placeholder:** "Search paths or request ID".
- **`RequestDetail`'s bottom line:** show `detail.request_id` (`font-mono`) with the `CopyButton` copying it. When it's null (old rows), show nothing in its place: no "Request" text and no copy button. Keep " · IP · user agent" as now.
- **The range line:** in the toolbar, before the refresh button, add a muted `text-xs tabular-nums` span reading "`from`–`to` of `total`" when `requests` has loaded and `total > 0`. Give the span the `ml-auto` and remove `ml-auto` from the refresh button, so both sit at the right-hand end.

**Verify** (root): `npm run types:check`, `npm run check`, `npm run test` → pass.

### Step 5: Tests

In `ApiRequestLogTest.php`:

- **Replace test 15** with "a request can be found by its request ID":
    - create two rows with `logRow` (the second 10 days old);
    - searching `req_<second's ulid>` returns only the second row with the default period;
    - so does the bare ULID;
    - so does the same text in upper case;
    - searching the second row's integer `id` returns no rows.
- **Add "API responses carry the logged request ID":**
    - PUT a contact through the API, as test 1 does;
    - the response has `X-Request-Id` matching `/^req_[0-9a-z]{26}$/`;
    - the logged row's `publicId()` equals the header.
- **Add "the header is sent even when logging is off":**
    - with `marketing.api_log.enabled` set to false, a GET to `marketing.api.me` has an `X-Request-Id` header;
    - no row is written.
- **Add "the log shows 25 rows a page":**
    - create 30 rows;
    - `requests.per_page` is 25 and `requests.data` has 25 items.

**Revert check:** remove the `headers->set(...)` line, and the two header tests fail. Restore it afterwards.

**Verify**: `vendor/bin/pest` → all pass (apart from the 7 SES failures); PHPStan → 0 errors; Pint → passes.

### Step 6: Docs

In the root `DEPLOYING.md` "API log" section, add a sentence:

> Every logged API response carries an `X-Request-Id` header (`req_…`), and pasting it into the log's search box finds that request.

**Verify**: `grep -n "X-Request-Id" DEPLOYING.md` (root) → a match.

## Done criteria

- [ ] `api_requests.ulid` exists, and is unique and filled for new rows; `id` is still the integer primary key
- [ ] Logged API responses carry `X-Request-Id: req_<ulid>`, even with logging off
- [ ] The expanded row shows and copies `req_<ulid>`; search finds it (with or without `req_`, any case) regardless of period
- [ ] The log defaults to 25 rows a page; the toolbar shows "from–to of total"
- [ ] `docs/openapi.json` unchanged
- [ ] New tests pass and the revert check fails as described; Pest, PHPStan, Pint, `npm run check`, `types:check` and `npm run test` pass

## STOP conditions

- The drift check shows changes these notes don't cover.
- `HasUlids` in the installed Laravel changes the primary key's type or incrementing even though `id` isn't in `uniqueIds()`.
- `docs/openapi.json` would change.

## Maintenance notes

- The header is only set for requests that pass token authentication. If client tracing is ever wanted for 401s too, move ID creation into a middleware that runs before `marketing.api`.
- Rows logged before this plan have no ULID and show no ID. They age out within 30 days, so nothing needs to be done about them.
- If the API docs ever document response headers, add `X-Request-Id`.
