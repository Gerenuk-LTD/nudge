# Plan 065: Every API response follows one format

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say "root". If anything in "STOP conditions" happens, stop
> and report. Don't improvise. Don't edit `plans/README.md`; the reviewer
> maintains it.
>
> **Drift check (run first)**:
> `git diff --stat 8882e60..HEAD -- packages/core/src/Http packages/core/routes/api.php packages/core/src/MarketingServiceProvider.php packages/core/workbench bootstrap/app.php`
> If anything changed, compare it with "Current state". On a mismatch, STOP.

## Status

- **Priority**: P2 (requested by the maintainer, 2026-11-15)
- **Effort**: M–L
- **Risk**: MEDIUM. Every API response shape changes slightly, but the app is unreleased, so no compatibility path is needed.
- **Depends on**: none
- **Category**: direction (API design)
- **Planned at**: commit `8882e60`, 2026-11-15

## Why this matters

The public API (`/api/v1`) is mostly consistent, but a client still has to special-case several endpoints:

- Contact lists use one paging format and the event timeline uses another.
- The same object has different fields depending on which endpoint returns it.
- Events have no `id` on the timeline.
- Timestamps come and go by object.
- Errors can only be told apart by their English `message`.

The maintainer wants one format everywhere, decided below.

## The format (decided by the maintainer; don't change it)

1. **Request bodies are flat.** The fields go at the top level, with no `data` wrapper. This is already true; keep it.
2. **A single record is returned inside `data`.** This covers GET of one record, POST and PUT (the record as it is after the write), and the result of an action:
    ```json
    { "data": { "id": 7, "name": "Newsletter", "...": "..." } }
    ```
3. **Every list is cursor-paginated, with the same envelope.** This is Laravel's cursor-paginated resource collection:
    ```json
    {
      "data": [ ... ],
      "links": { "first": null, "last": null, "prev": null, "next": "https://…?cursor=…" },
      "meta": { "path": "https://…/api/v1/tags", "per_page": 100, "next_cursor": "…", "prev_cursor": null }
    }
    ```
    - Every list endpoint takes `per_page` (1–500, default 100) and `cursor`.
    - Ordering must be total, so add `id` as a tie-breaker after any non-unique column.
4. **Every object has exactly one shape, built by one resource class.** The same fields appear on every endpoint that returns that object. The one documented exception: a segment's `contacts_count` appears only on `GET /segments/{segment}`, because counting a segment runs its whole query.
5. **Every mutable object has `created_at` and `updated_at`** (ISO 8601). Events are immutable and have `occurred_at` only.
6. **Every error has a stable machine-readable `code` and a `message`.** Validation errors add `errors`, as they do now:
    ```json
    {
        "code": "validation_failed",
        "message": "The name field is required.",
        "errors": { "name": ["The name field is required."] }
    }
    ```
7. **Every 429 sends `Retry-After`.**
8. **Unknown values of a constrained filter answer 422**, instead of silently returning nothing.

### Error codes

| HTTP                          | `code`                    | When                                                                                                                                            |
| ----------------------------- | ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| 401                           | `unauthenticated`         | Missing, unknown or expired token (`AuthenticateApiToken`)                                                                                      |
| 403                           | `missing_permission`      | Token lacks the route's permission (`EnsureTokenCan`)                                                                                           |
| 404                           | `not_found`               | Unknown route or record (route model binding, `firstOrFail`, the `segment` filter)                                                              |
| 404                           | `contact_not_found`       | `POST /events` for an unknown email without `create_contact`                                                                                    |
| 405                           | `method_not_allowed`      | Wrong HTTP method                                                                                                                               |
| 422                           | `validation_failed`       | Any `ValidationException`                                                                                                                       |
| 422                           | `invalid_idempotency_key` | Bad `Idempotency-Key` header on `POST /events`                                                                                                  |
| 429                           | `rate_limited`            | The per-token limit (`throttle:marketing-api`) **and** the bad-token limit in `AuthenticateApiToken`. Both use the message "Too many requests." |
| 500                           | `server_error`            | Anything else. Keep Laravel's message ("Server Error." when not debugging)                                                                      |
| other 4xx/5xx `HttpException` | `http_error`              | Fallback                                                                                                                                        |

Put these codes in a backed enum, `src/Http/ApiErrorCode.php` (`enum ApiErrorCode: string`), so they are listed in one place.

## Current state

Read these files in full before starting:

- `src/Http/Controllers/Api/`: `ContactController`, `ContactListController`, `CustomFieldController`, `EventController`, `MeController`, `SegmentController` and `TagController`.
- `src/Http/Resources/`: `ContactResource` and `ContactListResource`.
- `src/Http/Middleware/AuthenticateApiToken.php` and `EnsureTokenCan.php`.
- `src/MarketingServiceProvider.php`: `registerApiErrors()` (the 404 renderable), `registerRateLimiting()` and `registerRoutes()`.
- Root `bootstrap/app.php`.
- `workbench/app/Providers/DocsServiceProvider.php`: the Scramble config and the API description.

What each endpoint returns today, and what changes:

| Endpoint                                            | Today                                              | Change                                                                                              |
| --------------------------------------------------- | -------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| `GET /contacts`, `GET /segments/{segment}/contacts` | `ContactResource` cursor collection                | Already right; keep                                                                                 |
| `GET /contacts/{email}`, `PUT`, `DELETE`            | `{data: contact}`; 204                             | Keep                                                                                                |
| `GET /contacts/{email}/events`                      | `{data: [...], next_cursor}`, fixed 100, no `id`   | `EventResource` cursor collection with `per_page`; add `id`                                         |
| `POST /events`                                      | 202 `{data: {id, event, email}}`                   | 202 `{data: EventResource}`. Replays answer the same way.                                           |
| `GET /lists`                                        | `ContactListResource` collection, not paginated    | Cursor-paginated by `name`, then `id`                                                               |
| `GET/POST/PUT /lists/{list}`                        | `contacts_count` only on GET                       | Always `contacts_count`: call `loadCount('contacts')` after create and update                       |
| `POST/DELETE /lists/{list}/contacts`                | `{data: {added                                     | removed, not_found}}`                                                                               | Keep: it's an action result. Document it as such. |
| `GET /tags`                                         | inline `{id, name, contacts_count}`, not paginated | `TagResource` + timestamps, paginated by `name`, `id`                                               |
| `GET /segments`                                     | inline `{id, name}`, not paginated                 | `SegmentResource` (`id`, `name`, `definition`, timestamps), paginated by `name`, `id`               |
| `GET /segments/{segment}`                           | inline, with `contacts_count`                      | `SegmentResource` with `contacts_count` (use `->additional`, or a `withCount` flag on the resource) |
| `GET /custom-fields`, `POST`                        | inline `{key, label, type}`, not paginated         | `CustomFieldResource` + timestamps, paginated by `key` (unique per workspace)                       |
| `GET /me`                                           | `{data: {workspace, token}}`                       | Keep the shape                                                                                      |

The tables `contact_lists`, `tags`, `segments` and `custom_field_definitions` all already have `timestamps()` (`database/migrations/2026_10_28_000002_create_audience_tables.php`). No migration is needed.

**`GET /contacts?status=`**: `ContactFilter` treats `status` as a comma-separated list (`TableQuery::values`). Validate each value against `ContactStatus` (the enum `ContactResource` reads `status->value` from) and keep the comma-list support. If there's no such enum, use the values `subscribed` and `unsubscribed`, which the docs already list. A bad value answers 422 `validation_failed` with an `errors.status` entry.

**`bootstrap/app.php` (root)** uses `$request->is('api/*')` to decide on JSON errors. The package's prefix is `config('marketing.api_prefix')` (default `api/v1`). The package must render its own API errors for its prefix, whatever the host does. See Step 1.

## Scope

**In scope:**

- `src/Http/Controllers/Api/*`
- `src/Http/Resources/*` (new: `EventResource`, `TagResource`, `SegmentResource`, `CustomFieldResource`)
- `src/Http/ApiErrorCode.php` (new)
- `src/Http/Middleware/AuthenticateApiToken.php` and `EnsureTokenCan.php`
- `src/MarketingServiceProvider.php` (error rendering only)
- `workbench/app/Providers/DocsServiceProvider.php` (the description text)
- `docs/openapi.json` (regenerated, never hand-edited)
- API tests: `tests/Feature/ApiTest.php`, `EventIdempotencyTest.php`, `ApiDocsTest.php`, and any other test under `tests/` that asserts on an API response. `grep -rln "marketing.api\." tests`; at the root, `tests/Feature/Settings/SecurityTest.php` also matches.
- Root `app/Console/Commands/DemoData.php`: only the fake `response_body` JSON in `createApiLog`, so the demo API log shows the new shapes.

**Out of scope:**

- Any web (Inertia) controller or page.
- The API log middleware and its UI.
- Root `bootstrap/app.php`. Leave it; the package handles its own prefix.
- New endpoints or new filters.
- Renaming fields that already exist.

## Git workflow

- Branch off local `dev`: `advisor/065-uniform-api-responses`.
- Commits per step are fine, such as `feat(core): API errors carry a code` and `feat(core): every API list is paginated and every object has one shape`. Each ends with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Errors

1. Create `ApiErrorCode` with the codes in the table.
2. In `MarketingServiceProvider::registerApiErrors()`, replace the single `NotFoundHttpException` renderable with one `renderable(function (Throwable $e, Request $request): ?JsonResponse)`. It returns `null` unless the request path is under the configured prefix (keep the existing `$request->is(trim(prefix,'/').'/*')` check). It maps:
    - `ValidationException` → 422 `validation_failed`, with `message` = `$e->getMessage()` and `errors` = `$e->errors()`;
    - `NotFoundHttpException` and `ModelNotFoundException` → 404 `not_found`, "Not found.";
    - `MethodNotAllowedHttpException` → 405 `method_not_allowed`, keeping Laravel's message;
    - `ThrottleRequestsException` → 429 `rate_limited`, "Too many requests.", **keeping `$e->getHeaders()`** (Retry-After, X-RateLimit-*);
    - any other `HttpExceptionInterface` → its status, `http_error`, its message (or the status text when empty) and its headers;
    - anything else → `null` when `config('app.debug')` is true, so the debug page still works; otherwise 500 `server_error`, "Server Error.".

    Build every body through one private helper, `apiError(ApiErrorCode $code, string $message, int $status, array $headers = [], ?array $errors = null)`, so the shape is defined once.

3. `AuthenticateApiToken`:
    - 401: `{code: unauthenticated, message: "Unauthenticated."}`.
    - 429: `{code: rate_limited, message: "Too many requests."}` with a `Retry-After` header of `RateLimiter::availableIn($failureKey)`.

    Use the same helper. Make it a small public static method on a new `src/Http/ApiError.php` class (`ApiError::response(...)`) that both the provider and the middleware call; then the helper above is that class.

4. `EnsureTokenCan` 403: `missing_permission`, same message.
5. `EventController`: the 404 → `contact_not_found`, and the idempotency 422 → `invalid_idempotency_key`, both through `ApiError::response`.

**Verify**: add tests in `ApiTest.php` asserting the exact JSON for each code: 401, 403, 404 (route model, `firstOrFail`, unknown path under the prefix), 405, 422 (`errors` present), and 429 for both limits, each with a `Retry-After` header. Don't write a test that changes `marketing.api_prefix`. Instead, assert that a JSON 422 comes back for a request **without** an `Accept: application/json` header (`$this->put(...)` rather than `putJson`, with a body). That proves the package renders JSON for its prefix by itself. If routes are registered before the test can change config, don't try to test another prefix. Run `vendor/bin/pest tests/Feature/ApiTest.php` → pass.

### Step 2: One resource per object

- Create `TagResource`, `SegmentResource`, `CustomFieldResource` and `EventResource`, following `ContactListResource`'s style: `@mixin`, `toArray(Request $request): array`, ISO 8601 timestamps with `?->toIso8601String()`, and `whenCounted` for counts.
    - **TagResource:** `id`, `name`, `contacts_count` (whenCounted), `created_at`, `updated_at`.
    - **SegmentResource:** `id`, `name`, `definition`, `contacts_count` (only when the controller provides it), `created_at`, `updated_at`.
    - **CustomFieldResource:** `key`, `label`, `type`, `created_at`, `updated_at`.
    - **EventResource:** `id`, `type` (enum value), `data` (`(object) ($this->data ?? [])`, as now), `campaign_id`, `occurred_at`.
- `ContactListResource`: add `updated_at`.
- Keep `ContactResource` as it is.
- Keep the doc comments with `@var` that Scramble reads (see `ContactResource`), and add them where a field's type isn't obvious (`definition`, `data`).

### Step 3: Controllers

Apply the endpoint table in "Current state":

- Every list endpoint validates `per_page` (`nullable|integer|min:1|max:500`) and returns `XResource::collection($query->orderBy(...)->orderBy('id')->cursorPaginate($perPage)->withQueryString())`. To avoid repeating the rule, add a tiny private or trait helper only if it stays obvious; otherwise repeat the one line.
- `POST /events` and its replay return `(new EventResource($event))->response()->setStatusCode(202)`.
- Lists `store` and `update` return the list with `loadCount('contacts')`.
- `GET /contacts` validates `status` as described in "Current state".
- **Scramble attributes:**
    - Update every `#[Response(...)]` `type:` string that describes the old shapes, or delete it where Scramble now infers the resource correctly.
    - Every 4xx `#[Response]` type becomes `array{code: string, message: string}`, with `errors: array<string, list<string>>` added for 422.
    - Update the descriptions that mention `next_cursor` to say `meta.next_cursor`.

### Step 4: API description

In `DocsServiceProvider`, add a paragraph to `scramble.info.description` (keep it joined with `"\n\n"`) explaining the conventions in 5 short sentences or fewer:

- request bodies are flat JSON;
- a record comes back in `data`;
- lists are cursor-paginated (`per_page`, `cursor`, `meta.next_cursor`);
- errors have `code` and `message`, plus `errors` for 422;
- 429 responses include `Retry-After`.

Then regenerate the spec in `packages/core`: `vendor/bin/testbench scramble:export --path=docs/openapi.json`. Read the diff to check the new shapes are documented (spot-check `/tags`, `/events`, a 404). Commit the regenerated file.

### Step 5: Tests and demo data

- Update every existing API assertion to the new shapes (Step 1's `grep`). Where a test reads `next_cursor` at the top level, read `meta.next_cursor`.
- Add tests:
    1. Every list endpoint (`/contacts`, `/segments/{id}/contacts`, `/contacts/{email}/events`, `/lists`, `/tags`, `/segments`, `/custom-fields`) answers with `data`, `links` and `meta.next_cursor`, and honours `per_page=1` (one item, a non-null `next_cursor` when there are 2+ rows). A dataset over the routes is fine.
    2. `POST /lists` and `PUT /lists/{id}` include `contacts_count` and `updated_at`.
    3. Timeline events have `id`, and `POST /events`'s `data.id` matches the timeline's first event `id`.
    4. `GET /contacts?status=subscibed` → 422 `validation_failed` with `errors.status`; `?status=subscribed,unsubscribed` → 200.
    5. `GET /segments` items have `definition` and no `contacts_count`; `GET /segments/{id}` has `contacts_count`.
- In root `app/Console/Commands/DemoData.php` `createApiLog`, change the fake response bodies:
    - the `PUT contacts/{email}` body becomes `['data' => [...contact fields incl. created_at/updated_at...]]`;
    - the `POST events` body becomes `['data' => ['id' => …, 'type' => 'custom', 'data' => [...], 'campaign_id' => null, 'occurred_at' => …]]`;
    - every fake 4xx body gets a `code` matching the table.

    Only change the JSON payloads.

**Verify**:

- `vendor/bin/pest` in `packages/core` → all pass apart from the 7 SES tests;
- root `vendor/bin/pest` → all pass (run root `npm run build` first if `public/build` is missing);
- PHPStan (root) → 0 errors;
- Pint → passes;
- `git diff --exit-code docs/openapi.json` after a fresh `scramble:export` → clean (the committed spec is current).

## Done criteria

- [ ] Every API error body is `{code, message}` (+ `errors` on 422), with codes from `ApiErrorCode`; both 429s send `Retry-After`
- [ ] JSON errors for the package's prefix don't depend on the host's `bootstrap/app.php`
- [ ] Every list endpoint is cursor-paginated with `data`/`links`/`meta` and `per_page`
- [ ] Tags, segments, custom fields and events have resource classes; every mutable object has `created_at`/`updated_at`; events have `id`
- [ ] `POST /events` returns the full event; list create/update include `contacts_count`
- [ ] A bad `status` filter answers 422
- [ ] `docs/openapi.json` regenerated and committed; the description explains the conventions
- [ ] Pest (core and root), PHPStan and Pint pass

## STOP conditions

- The drift check shows changes these notes don't cover.
- Scramble can't document the resource collections or the error shape without hand-editing `openapi.json`. Report what it produces instead.
- The `Throwable` renderable breaks web (non-API) error pages or the debug page in tests.
- Cursor pagination on `name` turns out not to work with `orderBy('name')->orderBy('id')` (it should).

## Maintenance notes

- New API endpoints must use a resource class, return lists through `cursorPaginate`, and raise errors through exceptions or `ApiError::response` with an `ApiErrorCode`. Never hand-build `response()->json(['message' => ...])`.
- Error codes are part of the public contract once released: add new ones, never rename.
