# Plan 064: The API log gets its own Method column and Method filter

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say "root". If anything in "STOP conditions" happens, stop
> and report. Don't improvise. Don't edit `plans/README.md`; the reviewer
> maintains it.
>
> **Drift check (run first)**:
> `git diff --stat 29535a2..HEAD -- packages/core/src/Http/Controllers/ApiRequestController.php packages/core/resources/js/pages/marketing/workspace/api-log.tsx packages/core/tests/Feature/ApiRequestLogTest.php`
> If any changed, compare them with "Current state". On a mismatch, STOP.

## Status

- **Priority**: P3 (requested by the maintainer, 2026-11-15)
- **Effort**: S
- **Risk**: LOW
- **Depends on**: 061 (done, `29535a2`)
- **Category**: direction (UX)
- **Planned at**: commit `29535a2`, 2026-11-15

## Why this matters

The method (GET, PUT and so on) is shown inside the Request column, next to the path. The Endpoint filter combines method and route ("PUT contacts/{email}"), so you can't filter on a method alone, such as every `PATCH`.

The maintainer wants:

- the method in its own column;
- a Method filter;
- the Endpoint filter on the route alone.

Then "every PATCH" and "every write to `contacts/{email}`" are both one or two clicks.

## Current state

**`src/Http/Controllers/ApiRequestController.php`**:

- **`index()`'s `routes` prop:**

    ```php
    'routes' => ApiRequest::query()->select('method', 'route')->distinct()->orderBy('route')->orderBy('method')->get()
        ->map(fn (ApiRequest $row) => ['value' => $row->method.' '.$row->route, 'label' => $row->method.' '.$row->route])
        ->values(),
    ```

- **`requests()`'s route filter:**

    ```php
    ->when(TableQuery::values($request->input('route')), function (Builder $query, array $routes): void {
        $query->where(function (Builder $query) use ($routes): void {
            foreach ($routes as $value) {
                [$method, $route] = array_pad(explode(' ', $value, 2), 2, '');

                $query->orWhere(fn (Builder $query) => $query->where('method', $method)->where('route', $route));
            }
        });
    })
    ```

**`resources/js/pages/marketing/workspace/api-log.tsx`**:

- **The `request` column:**

    ```tsx
    <span className="flex items-center gap-2 font-mono text-xs">
        <span className="rounded border px-1.5 py-0.5 font-medium">
            {row.original.method}
        </span>
        <span
            className="max-w-72 truncate sm:max-w-md"
            title={row.original.path}
        >
            {row.original.path}
        </span>
    </span>
    ```

- **The toolbar:** `TableSearch`, then `FacetedFilter`s for Token (`tokens`), Endpoint (`routes`, query key `route`) and Status (`STATUSES`), then `ChoiceFilter` Period, then `ResetFilters`, whose `filtered` check lists `['token', 'route', 'status']` and whose reset clears those keys.

**`tests/Feature/ApiRequestLogTest.php`**, the filters test (around line 145): it creates rows with `method`/`route` pairs and asserts `$expectTotal(['route' => 'POST events'], 1)` and `$expectTotal(['route' => 'GET contacts/{email}'], 1)`.

## Scope

**In scope:** `ApiRequestController.php`, `api-log.tsx`, `ApiRequestLogTest.php`.

**Out of scope:** the middleware, the model, the stored data, and `DataTable`.

## Git workflow

- Branch off `dev`: `advisor/064-api-log-method`.
- One commit, `feat(core): filter the API log by method`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Server

- **`index()`:**
    - Replace `routes` with distinct routes only: `ApiRequest::query()->whereNotNull('route')->distinct()->orderBy('route')->pluck('route')`, mapped to `['value' => $route, 'label' => $route]`.
    - Add a `methods` prop: the distinct methods in this workspace's log, in the order `GET, POST, PUT, PATCH, DELETE`, then any others alphabetically, as `['value' => 'GET', 'label' => 'GET']`.
- **`requests()`:**
    - The route filter becomes `->when(TableQuery::values($request->input('route')), fn (Builder $query, array $routes) => $query->whereIn('route', $routes))`.
    - Add a method filter: `->when(TableQuery::values($request->input('method')), fn (Builder $query, array $methods) => $query->whereIn('method', array_map('strtoupper', $methods)))`.

**Verify**: PHPStan (root) → 0 errors.

### Step 2: Page

- **Columns:**
    - Add a **Method** column before **Request**. It shows the existing method badge (`rounded border px-1.5 py-0.5 font-mono text-xs font-medium`).
    - The **Request** column shows only the path (`font-mono text-xs`, truncated with `title`, as now). Rename its header to **Path**.
- **Filters:**
    - Add a `FacetedFilter` titled "Method" (`options={methods}`, query key `method`) before Endpoint.
    - Add `method` to the `filtered` check and to the `ResetFilters` reset.
- **Props:** add `methods: FacetOption[]`.
- **Skeleton:** update `DataTableSkeleton columns={...}` to the new column count.

**Verify** (root): `npm run types:check`, `npm run check` (format the file with `npx vp fmt` if needed), `npm run test` → pass.

### Step 3: Tests

In the filters test:

- change the route assertions to route-only values:
    - `['route' => 'events']` → 1;
    - `['route' => 'contacts/{email}']` → the number of rows with that route;
- add `['method' => 'POST']` → 1;
- add `['method' => 'get,post']` → both, which also checks lower case is accepted.

Add a test, **"the method and endpoint options come from this workspace's log"**:

- with logged `PATCH contacts/{email}`, `GET me` and `PATCH me` rows, the page's `methods` prop is `GET`, then `PATCH`;
- `routes` is `contacts/{email}` and `me`, each once.

**Verify**: `vendor/bin/pest tests/Feature/ApiRequestLogTest.php` → all pass; full `vendor/bin/pest` → all pass apart from the 7 SES tests; PHPStan → 0 errors; Pint → passes.

## Done criteria

- [ ] Method has its own column and filter; the Path column shows the path only
- [ ] The Endpoint filter lists each route once and filters on route alone; method and endpoint filters combine
- [ ] Updated and new tests pass; Pest, PHPStan, Pint, `npm run check`, `types:check` and `npm run test` pass

## STOP conditions

- The drift check shows changes these notes don't cover.
- The change needs files outside the scope.

## Maintenance notes

Old links that used the combined `route=PUT contacts/{email}` value now match nothing. The app is unreleased, so nothing needs to handle them.
