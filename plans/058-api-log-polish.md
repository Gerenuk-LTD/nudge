# Plan 058: API log polish: copy buttons, whole-row expand, token links, status meanings, request IDs

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say "root". If anything in "STOP conditions" happens, stop
> and report. Don't improvise. When done, update this plan's row in
> `plans/README.md`, unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 7178d7e..HEAD -- packages/core/resources/js/pages/marketing/workspace/api-log.tsx packages/core/resources/js/components/data-table.tsx packages/core/resources/js/pages/marketing/workspace/api-tokens.tsx packages/core/resources/js/lib/format.ts packages/core/src/Http/Controllers/ApiRequestController.php`
> If any of these changed, compare them with the "Current state" notes.
> On a mismatch, STOP.

## Status

- **Priority**: P3 (polish requested by the maintainer after seeing plan 056 live, 2026-11-15)
- **Effort**: S–M
- **Risk**: LOW. It's frontend, apart from one small search change in the controller.
- **Depends on**: 056 (done, `ea439ad`)
- **Category**: direction (UX)
- **Planned at**: commit `7178d7e`, 2026-11-15

## Why this matters

Plan 056 shipped the API log, and the maintainer likes it. These changes make it quicker to use when debugging an integration, which is what the log is for. They follow how Stripe and Resend's request logs behave.

1. **Copy buttons** on the request and response bodies. They get pasted into tickets and HTTP clients.
2. **Click anywhere on a row to expand it**, not only the chevron.
3. **"View requests" on each API token**, opening the log filtered to that token.
4. **Status meanings and relative times.**
    - A tooltip on the status code: "422 · Validation failed".
    - The time reads "3 min ago", with the exact time in a tooltip.
5. **The request ID** in the expanded row, with a copy button. The search box also finds a request by its ID.
6. **Smaller touches:**
    - durations of 1 second or more in amber, with a "Slow request" tooltip;
    - a refresh button;
    - 5xx rows get a faint red background.

## Current state

**`resources/js/pages/marketing/workspace/api-log.tsx`** (from plan 056):

- `columns`: Time (`formatDateTime`), Token, Request (method badge + truncated path), Status (`statusDot()` + code), Duration (`{n} ms`, `meta: { numeric: true }`).
- The toolbar: `TableSearch` (placeholder "Search paths"), `FacetedFilter` for Token, Endpoint and Status, `ChoiceFilter` for Period (default `7d`), `ResetFilters`.
- `DataTable` with `renderExpanded={(row) => <RequestDetail id={row.id} bodyRetentionDays={...} />}`.
- `RequestDetail` fetches `ApiRequestController.show.url(id)` with `getJson`. Unless `bodies_missing` is set, it shows two `Body` blocks (a heading plus a `<pre>`), then a muted line "IP · user agent".
- `Body({ title, body })` renders `<pre className="max-h-96 overflow-auto rounded-lg border bg-card p-3 font-mono text-xs">` holding `pretty(body)`, or "No body".
- The page's props include `canViewBodies`, which it doesn't use.

**`resources/js/components/data-table.tsx`**:

- When `renderExpanded` is given, each row gets a leading cell with a ghost icon `Button` (`aria-label="Show details"`, `aria-expanded`) that calls `row.toggleExpanded()`.
- An expanded row is followed by a `<TableRow className="hover:bg-transparent">` containing one full-width cell.
- Rows are wrapped in `<Fragment key={row.id}>`.

**`resources/js/pages/marketing/workspace/api-tokens.tsx`**: each token row has a `DropdownMenu` (an `Ellipsis` trigger) whose only item is `Revoke`.

**`resources/js/lib/format.ts`**: `formatDateTime`, `formatDate` and `percent`. There's no relative-time helper and no test file.

**Clipboard**: `useClipboard()` from `@/hooks/use-clipboard` returns `[copiedText, copy]`. See `resources/js/components/copy-field.tsx` for the copied/copy pattern (`Check` icon and "Copied" while `copied === value`).

**Tooltips**: `@/components/ui/tooltip`. Copy the usage in `resources/js/components/sending-status.tsx`.

**Filters in the URL**: comma-separated, such as `?token=5&period=30d`. `TableQuery::values()` parses them, and the page's `FacetedFilter` ticks them from the URL.

**`src/Http/Controllers/ApiRequestController.php::requests()`** ends with `TableQuery::search($query, $request->string('search')->toString(), ['path']);`.

**Tests**: `tests/Feature/ApiRequestLogTest.php` (14 tests; it defines its own `logToken()` helper). Vitest only picks up `*.test.ts` files; see `resources/js/lib/merge-tags.test.ts` for the style.

## Commands

From `packages/core` (PowerShell: `php`/`composer`; Git Bash: `php.bat`/`composer.bat`), unless they say "root".

| Purpose         | Command                                                | Expected                                      |
| --------------- | ------------------------------------------------------ | --------------------------------------------- |
| One file        | `vendor/bin/pest tests/Feature/ApiRequestLogTest.php`  | all pass                                      |
| Core tests      | `vendor/bin/pest`                                      | all pass, except the 7 Windows-only SES tests |
| PHPStan (root)  | `vendor/bin/phpstan analyse --memory-limit=1G`         | 0 errors                                      |
| Pint (root)     | `vendor/bin/pint --test packages/core`                 | passes                                        |
| Frontend (root) | `npm run check`, `npm run types:check`, `npm run test` | pass                                          |

If `types:check` fails on missing Wayfinder helpers, run `php artisan wayfinder:generate --with-form` at the root. The generated files are gitignored.

## Scope

**In scope:** `api-log.tsx`, `data-table.tsx`, `api-tokens.tsx`, `lib/format.ts`, `lib/format.test.ts` (new), `ApiRequestController.php`, `ApiRequestLogTest.php`.

**Out of scope:**

- JSON syntax colouring and a live stream of new requests (the maintainer agreed to skip both).
- The middleware, the pruning job, and the stored data.
- The other tables that use `DataTable`. They must render exactly as before.

## Git workflow

- Branch off `dev`: `advisor/058-api-log-polish`.
- One or more commits, such as `feat(core): polish the API log`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: `formatRelative` with tests

In `lib/format.ts` add:

```ts
const relativeFormat = new Intl.RelativeTimeFormat(undefined, {
    numeric: 'auto',
    style: 'short',
});

/**
 * How long ago something happened, such as "3 min. ago" or "yesterday", up to a week; older times fall back to
 * formatDateTime. Pass `now` in tests.
 */
export function formatRelative(
    value: string | null | undefined,
    now: Date = new Date(),
): string | null;
```

Rules, with `seconds = (now - value) / 1000`:

- under 45 seconds → "just now";
- under 60 minutes → `relativeFormat.format(-minutes, 'minute')`;
- under 24 hours → hours;
- under 7 days → days;
- otherwise `formatDateTime(value)`.

Round each unit down (`Math.floor`), with a minimum of 1. A future time (clock skew) counts as "just now".

`lib/format.test.ts` covers:

- 10 seconds ago → "just now";
- 3 minutes ago → contains "3";
- 2 hours ago → contains "2";
- 1 day ago → a non-empty string that isn't "just now";
- 10 days ago → equals `formatDateTime(value)`;
- `null` → `null`.

Don't assert locale-specific wording beyond what's above.

**Verify** (root): `npm run test` → passes.

### Step 2: Row clicks and row classes in `DataTable`

When `renderExpanded` is given, the main `<TableRow>` also gets:

- `className="cursor-pointer"` (merged with the class from the next bullet);
- an `onClick` that toggles the row, unless:
    - the click started inside an interactive element: `(event.target as HTMLElement).closest('button, a, input, select, textarea, [role="menuitem"], [role="checkbox"]')`;
    - or the user is selecting text: `window.getSelection()?.toString()` is non-empty.

The chevron button stays, as the keyboard and screen-reader control. Its own click must not toggle twice: the `closest('button')` check already covers that, since it skips the row handler. Confirm by clicking the chevron.

Add an optional prop:

```ts
/** Extra classes for a row, such as a tint for failed requests. */
rowClassName?: (row: TData) => string | undefined;
```

It's applied with `cn(...)` to the main `<TableRow>`. Without either prop, rows render exactly as before.

**Verify** (root): `npm run types:check` → passes. `git diff` shows no change to how rows render when neither `renderExpanded` nor `rowClassName` is passed.

### Step 3: Status meanings, relative time, slow requests and 5xx rows

In `api-log.tsx`:

- Add `statusText(status: number): string`:
    - known codes:

        | Code | Text               |
        | ---- | ------------------ |
        | 200  | OK                 |
        | 201  | Created            |
        | 202  | Accepted           |
        | 204  | No content         |
        | 400  | Bad request        |
        | 401  | Not signed in      |
        | 403  | Missing permission |
        | 404  | Not found          |
        | 405  | Method not allowed |
        | 409  | Conflict           |
        | 413  | Too large          |
        | 422  | Validation failed  |
        | 429  | Rate limited       |
        | 500  | Server error       |
        | 502  | Bad gateway        |
        | 503  | Unavailable        |

    - other codes by class: "Success", "Redirect", "Client error", "Server error".
- **Status cell:** wrap the dot and code in a `Tooltip` reading "`{status} · {statusText}`". Give the trigger `tabIndex={0}`, so keyboard users can reach the tooltip.
- **Time cell:** show `formatRelative(created_at)`, in a `Tooltip` holding `formatDateTime(created_at)`. Use a `<time dateTime={created_at}>` as the trigger.
- **Duration cell:** when `duration_ms >= 1000`, add `text-amber-600 dark:text-amber-400` and a `Tooltip` reading "Slow request". Show it as seconds with one decimal ("1.4 s"); below 1000 it stays "`n` ms".
- **5xx rows:** pass `rowClassName={(row) => row.status >= 500 ? 'bg-destructive/5 hover:bg-destructive/10' : undefined}` to `DataTable`.
- Remove the unused `canViewBodies` from the props type. Leave the server prop alone; it's harmless and the tests may read it.

**Verify** (root): `npm run types:check`, `npm run check` → pass.

### Step 4: Copy buttons and the request ID

- **`Body`:** wrap the `<pre>` in a `relative group` div. Add a small ghost icon `Button` (`size-7`, `absolute top-2 right-2`) that copies the text shown, so pretty-printed JSON is copied as displayed. Use `useClipboard()`. The icon is `Copy`, or `Check` while `copied === text`. Set `aria-label` to "Copy request body" or "Copy response body" (taken from `title`), with a `Tooltip` reading "Copy" / "Copied". Don't render the button when the body is null.
- **`RequestDetail`:** replace the bottom line with a muted `text-xs` row: "Request #`{id}`", a tiny copy button (copies the plain ID, `aria-label="Copy request ID"`), then " · IP · user agent" when present. The ID comes from the `id` prop; the server already knows it.

**Verify** (root): `npm run types:check` → passes.

### Step 5: Find a request by its ID

- **Server:** in `ApiRequestController::requests()`, before the existing `TableQuery::search(...)` call, read the search text. If it matches `^#?\d+$`, filter by `whereKey((int) ltrim($search, '#'))` instead of searching paths. Otherwise search paths as now.
- **Page:** change the search placeholder to "Search paths or #ID".

Add to `ApiRequestLogTest.php`:

15. **"a request can be found by its ID"**: two logged requests. `search=#<id of the second>` returns only that row in `requests.data`. The same ID without the `#` does too.

**Verify**: `vendor/bin/pest tests/Feature/ApiRequestLogTest.php` → 15 pass.

### Step 6: The refresh button

In the toolbar, after `ResetFilters`, add a `Button variant="outline" size="icon"` (`size-8`, `ml-auto`) with a `RefreshCw` icon, `aria-label="Refresh"` and a `Tooltip` reading "Refresh".

- On click, call `router.reload({ only: ['requests', 'routes'] })`, tracking a `refreshing` state through `onStart` and `onFinish`.
- While refreshing, the button is disabled and the icon spins (`animate-spin`).
- Check that `routes` is a normal (not deferred) prop that `reload` can ask for. It is in `index()`.

**Verify** (root): `npm run types:check` → passes.

### Step 7: "View requests" on API tokens

In `api-tokens.tsx`, add a `DropdownMenuItem asChild` **before** `Revoke` in each token's menu:

- a Wayfinder `Link` to `apiLogIndex({ query: { token: String(token.id), period: '30d' } })`, imported from `@/routes/marketing/api-log`;
- icon `ScrollText` and the text "View requests";
- a `DropdownMenuSeparator` between it and `Revoke`.

Use the `Link` from `@inertiajs/react`, as other pages do. Check that Wayfinder's `index()` accepts `{ query: ... }` (grep another page for `query:` usage). If it doesn't, build the URL with `apiLogIndex.url()` plus a query string.

Only show the item when the user can see the log. The log and the tokens page both need `api_tokens.manage`, so anyone on this page can; nothing extra is needed.

**Verify** (root): `npm run types:check`, `npm run check` → pass.

### Step 8: Check it in the browser

Run the app (`composer run dev` at the root). If the local database has no log rows, make a few real ones: create an API token in the UI and send some requests with curl, such as a `PUT` to `/api/v1/contacts/{email}` with a JSON body, a `GET` to `/api/v1/me`, and a `PUT` with an invalid email to get a 422.

Then check that:

- clicking a row (not the chevron) expands it, and clicking the chevron toggles it once;
- selecting text in a row doesn't toggle it;
- both copy buttons work;
- the tooltips show the status meaning and the exact time;
- refresh reloads the table;
- "View requests" from API tokens opens the log filtered to that token;
- other tables (contacts, campaign recipients) don't change.

If you can't open a browser, say so in your report instead of claiming you checked.

**Verify**: Pest (full), PHPStan, Pint, and (root) `npm run check`, `npm run types:check`, `npm run test` → all pass.

## Done criteria

- [ ] Rows expand on click anywhere except interactive elements, and not while text is selected
- [ ] The status code has a meaning tooltip; the time is relative, with the exact time in a tooltip
- [ ] Durations of 1 s or more are amber with "Slow request"; 5xx rows are tinted
- [ ] Both bodies have copy buttons; the expanded row shows "Request #ID" with a copy button
- [ ] Searching `#ID` or a bare number finds that request (test 15)
- [ ] The refresh button reloads the table
- [ ] API tokens have "View requests", which opens the log filtered to that token for 30 days
- [ ] `DataTable` without the new props renders as before
- [ ] `formatRelative` tests and test 15 pass; Pest, PHPStan, Pint, `npm run check`, `types:check` and `npm run test` pass
- [ ] `plans/README.md` row updated

## STOP conditions

- The drift check shows changes these notes don't cover.
- Row clicks can't be added without changing how `DataTable` behaves for tables that don't pass `renderExpanded`.
- Wayfinder's generated `index()` can't add a query string, and `.url()` doesn't exist either.

## Maintenance notes

- `statusText()` only lists codes the API returns today. Add new ones when an endpoint starts returning them; unknown codes fall back to their class.
- `rowClassName` and row clicks are generic `DataTable` features now. Other expandable tables get click-to-expand for free.
- Searching by ID treats any all-digits search as an ID. A path search for a bare number (unlikely) would need a leading `/`.
