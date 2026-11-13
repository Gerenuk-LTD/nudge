# Plan 026: The core package ships its own OpenAPI spec, and every self-hosted install serves docs for its own API

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 9f2f007..HEAD -- packages/core/routes/api.php packages/core/src/Http/Controllers/Api packages/core/src/Http/Resources packages/core/src/Http/Middleware packages/core/src/MarketingServiceProvider.php packages/core/config/marketing.php packages/core/composer.json .github/workflows/tests.yml`
> Any change is a STOP unless it only adds endpoints. New endpoints then also need annotating in Step 4.

## Status

- **Priority**: P2. Self-hosters currently have no API reference.
- **Effort**: M
- **Risk**: LOW. Docs only, plus two new read-only routes. No changes to API behaviour.
- **Depends on**: —
- **Category**: docs / DX
- **Planned at**: commit `9f2f007`, 2026-11-08

## Why this matters

The API (`/api/v1/...`) lives in `gerenuk/marketing-core`, not the host app. Self-hosters install that package, so its docs must:

1. **Ship with the package and be versioned with it.** The spec for a release has to describe that release's API.
2. **Need nothing extra at runtime.** Self-hosters shouldn't have to install a docs generator to read the docs.
3. **Describe each install correctly.** `marketing.api_prefix` is configurable and every install has its own domain, so a spec with a hard-coded base URL would be wrong for most people.
4. **Not drift.** If an endpoint changes without the docs changing, CI should fail.

The approach is to generate an OpenAPI 3.1 spec from the code with [Scramble](https://scramble.dedoc.co) (`dedoc/scramble`), run under Testbench inside `packages/core`. The spec is committed as `packages/core/docs/openapi.json`, and the package serves it with each install's own base URL, plus an optional viewer page. Scramble stays a dev dependency of the package.

## Current state

**Route registration**, in `packages/core/src/MarketingServiceProvider.php` `registerRoutes()`:

```php
Route::prefix(config('marketing.api_prefix'))
    ->middleware(['api', 'marketing.api', 'throttle:marketing-api'])
    ->name('marketing.api.')
    ->group(__DIR__.'/../routes/api.php');
```

`marketing.api` is `AuthenticateApiToken`: a bearer token (prefix `gmk_`). It returns `401 {"message":"Unauthenticated."}` if the token is invalid, and `429` after 30 bad attempts per IP per minute. `marketing.token-can:<permission>` is `EnsureTokenCan`, aliased at line ~100, and returns `403 {"message":"This token does not have the <permission> permission."}`.

**`packages/core/config/marketing.php`:** `'api_prefix' => 'api/v1'`, `'api_requests_per_minute' => env('MARKETING_API_REQUESTS_PER_MINUTE', 120)`.

**`packages/core/routes/api.php`** has 22 routes:

- `GET me` (any valid token)
- **`contacts.view`:** `GET contacts`, `GET contacts/{email}`, `GET contacts/{email}/events`, `GET lists`, `GET lists/{list}`, `GET tags`, `GET custom-fields`, `GET segments`, `GET segments/{segment}`, `GET segments/{segment}/contacts`
- **`contacts.manage`:** `PUT contacts/{email}`, `DELETE contacts/{email}`, `POST lists`, `PUT lists/{list}`, `DELETE lists/{list}`, `POST lists/{list}/contacts`, `DELETE lists/{list}/contacts`, `POST custom-fields`, `POST events`

**How the controllers (`src/Http/Controllers/Api/`) affect what Scramble can infer:**

| Controller                                                                          | Input                                                                                                                                                                  | Output                                                   | Scramble will infer                                 |
| ----------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- | --------------------------------------------------- |
| `ContactController@index`                                                           | inline `$request->validate([...])`                                                                                                                                     | `ContactResource::collection` + `cursorPaginate`         | well                                                |
| `ContactController@upsert`                                                          | `Validator::make($data, [...$contactRules->rules(), 'resubscribe' => ...])`; `ContactRules::rules()` is built at runtime from the workspace's custom-field definitions | `JsonResponse`                                           | **poorly: needs annotating**                        |
| `ContactListController`                                                             | inline `validate` (via private `validated()` / `contactsFromRequest()`)                                                                                                | `ContactListResource`                                    | mostly; check the private-method rules come through |
| `CustomFieldController@store`                                                       | `$request->validate($rules->forCreate())`                                                                                                                              | `JsonResponse`                                           | **body poorly**                                     |
| `EventController`                                                                   | inline `validate`                                                                                                                                                      | `response()->json([...], 202)`                           | body well; response partly                          |
| `TagController`, `SegmentController`, `MeController`, `CustomFieldController@index` | none / `per_page`                                                                                                                                                      | `response()->json(['data' => $collection->map(fn ...)])` | **response shape poorly**                           |

`ContactResource` returns `custom_fields` as an object whose keys are defined per workspace, so the spec can only describe it as `object` with `additionalProperties`.

**Package dev setup:** `packages/core/composer.json` has `orchestra/testbench ^11.3` in `require-dev`. There's no `testbench.yaml` or `workbench/` yet. `tests/TestCase.php` registers `InertiaServiceProvider` and `MarketingServiceProvider`. The package has **no Blade views** and doesn't call `loadViewsFrom` (`resources/` holds only `css`, `email-templates` and `js`).

**CI (`.github/workflows/tests.yml`):** the `core-mysql` job runs `composer --working-dir=packages/core install`, then `vendor/bin/pest` in `packages/core`.

## Commands you will need

| Purpose                  | Command                                                                             | Expected                     |
| ------------------------ | ----------------------------------------------------------------------------------- | ---------------------------- |
| Add Scramble (core, dev) | `composer --working-dir=packages/core require --dev dedoc/scramble`                 | resolves against Laravel 13  |
| Export the spec          | `cd packages/core && vendor/bin/testbench scramble:export --path=docs/openapi.json` | writes the file, exit 0      |
| Drift check              | `cd packages/core && git diff --exit-code docs/openapi.json`                        | exit 0 right after an export |
| Validate the spec        | `npx --yes @redocly/cli@latest lint packages/core/docs/openapi.json`                | no errors (warnings are OK)  |
| Core tests               | `cd packages/core && vendor/bin/pest --compact`                                     | all pass                     |
| Host tests               | `php artisan test --compact`                                                        | all pass                     |
| PHPStan / Pint           | as usual                                                                            | 0 errors / exit 0            |

## Scope

**In scope:**

- `packages/core/composer.json` / `composer.lock` (`dedoc/scramble` in `require-dev` **only**)
- `packages/core/testbench.yaml` (new)
- `packages/core/workbench/app/Providers/DocsServiceProvider.php` (new; configures Scramble for export only)
- `packages/core/workbench/app/Scramble/TokenPermissionExtension.php` (new)
- `packages/core/docs/openapi.json` (new, generated)
- `packages/core/src/Http/Controllers/Api/*.php` (docblocks and Scramble attributes only)
- `packages/core/src/Http/Controllers/ApiDocsController.php` (new)
- `packages/core/resources/views/api-docs.blade.php` (new)
- `packages/core/src/MarketingServiceProvider.php` (`loadViewsFrom`, plus a docs route group)
- `packages/core/config/marketing.php` (an `api_docs` block)
- `packages/core/tests/Feature/ApiDocsTest.php` (new)
- `.github/workflows/tests.yml` (a drift step in `core-mysql`)
- `packages/core/.gitattributes` (export-ignore `workbench/` and `testbench.yaml`)
- `DEPLOYING.md` (a short "API docs" section)

**Out of scope:**

- Any change to validation, responses, status codes or route names. Annotations describe the current behaviour; they don't change it. If something looks like a bug, note it in the report.
- Converting the `response()->json` controllers to JsonResources. That's a reasonable follow-up, but it's a behaviour risk that this plan doesn't need.
- A hosted public docs site. The committed spec is ready for one; picking a provider is the author's call.
- Making Scramble a runtime (`require`) dependency of the package, or installing it in the host app.

## Git workflow

- Branch: `advisor/026-api-docs`.
- Commits:
    - `build(core): generate the API spec with Scramble under Testbench`
    - `docs(core): annotate API controllers so the spec is complete`
    - `feat(core): serve the API spec and a docs page from each install`
    - `ci: fail when the committed API spec is out of date`
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Install Scramble as a dev dependency of the package

```bash
composer --working-dir=packages/core require --dev dedoc/scramble
```

**Verify:**

- It appears under `require-dev` in `packages/core/composer.json`, not `require`.
- The root `composer.json` is unchanged.

If it doesn't resolve against `laravel/framework ^13.17`, **STOP**. Don't pin an old version or add `--ignore-platform-reqs`.

Read the installed version's docs (`vendor/dedoc/scramble/README.md` and the docs site for that version) before Steps 2 and 4. The attribute and configuration names used below (`Scramble::configure()`, `withDocumentTransformers`, `OperationExtension`, `#[BodyParameter]`, `#[QueryParameter]`, `@response`) must match the installed version. Where they differ, use the installed version's equivalent and note it in the report.

### Step 2: Configure the export under Testbench

The configuration lives in `workbench/`, which is dev-only, so nothing Scramble-related is loaded in a self-hoster's app.

1. **Create `packages/core/testbench.yaml`.** Its providers should be `Inertia\ServiceProvider`, `Gerenuk\Marketing\MarketingServiceProvider`, `Dedoc\Scramble\ScrambleServiceProvider` and `Workbench\App\Providers\DocsServiceProvider`. Add a `workbench/` PSR-4 entry (`"Workbench\\App\\": "workbench/app/"`) to `autoload-dev` in `packages/core/composer.json`, then run `composer --working-dir=packages/core dump-autoload`.
2. **In `DocsServiceProvider::register()`, set:**
    - `scramble.api_path` to `config('marketing.api_prefix')`;
    - `scramble.info.version` to a fixed string, `'1'`, so the export is deterministic;
    - `scramble.ui.title` to `'Gerenuk Marketing API'`;
    - `scramble.servers` to `['Default' => 'api/v1']`. The served copy replaces this per install in Step 5.
3. **In `boot()`:**
    - restrict documented routes to names starting `marketing.api.`;
    - add a document transformer that registers an HTTP bearer security scheme as the default. Describe it as "A workspace API token (starts `gmk_`), created under Settings → API tokens." Check that wording against the UI and correct it.
    - register `TokenPermissionExtension` (Step 3).
4. **Export:** `cd packages/core && vendor/bin/testbench scramble:export --path=docs/openapi.json`.

**Verify:**

- `docs/openapi.json` exists and lists all 22 operations from "Current state".
- Its paths have **no** `api/v1` prefix; the prefix is in `servers`.
- Running the export twice gives an identical file (`git diff --exit-code` after the second run). If it doesn't, find the source of the non-determinism (timestamps, ordering) and fix it in `DocsServiceProvider`. If you can't, STOP.

Commit 1.

### Step 3: Document token permissions and the shared error responses

Create `workbench/app/Scramble/TokenPermissionExtension.php`, a Scramble operation extension. For each route:

- If its middleware includes `marketing.token-can:<permission>`, add `x-required-permission: <permission>` to the operation, and put "Requires a token with the `<permission>` permission." at the start of its description.
- Add these responses to every operation unless Scramble already added them:
    - `401` (`{"message": "Unauthenticated."}`)
    - `403` (only for routes with a permission; message as in `EnsureTokenCan`)
    - `429` ("Too many requests", from either the per-token limit `marketing.api_requests_per_minute` or the 30-bad-tokens-per-IP limit).

    Scramble usually generates `404` and `422` on its own. Check, and add them through the extension if it doesn't.

`GET me` has no permission middleware. It should get `401`/`429` but no `403` and no `x-required-permission`.

**Verify:** re-export. `PUT /contacts/{email}` shows `contacts.manage`, and `GET /contacts` shows `contacts.view`.

### Step 4: Annotate the controllers

Use docblocks and Scramble attributes only. Don't change any code paths. For each controller, re-export and look at the operation in the generated file before moving on.

1. **Every operation:**
    - add a one-line summary docblock if it doesn't have one, keeping the existing ones (for example the `upsert` docblock and the class docblock "Contacts are addressed by email address…");
    - give each controller a tag (Contacts, Lists, Tags, Custom fields, Segments, Events, Account).
2. **The `{email}` path parameter:** document it as an email address that should be URL-encoded and is matched case-insensitively. Check `Contact::normalizeEmail` to confirm the case handling. `{list}` and `{segment}` are integer IDs.
3. **`ContactController@upsert` body:** describe it with body-parameter attributes that mirror `ContactRules::rules()` exactly:
    - `email` comes from the path, so leave it out of the body;
    - `first_name`, `last_name`: string|null, max 255;
    - `status`: enum of `ContactStatus` values;
    - `custom_fields`: object, keys defined per workspace; see `GET /custom-fields`;
    - `tags`: string[], max 50, each max 100;
    - `lists`: int[] of list IDs;
    - `resubscribe`: bool.

    Document the 200-versus-201 behaviour (`created`) from the controller body, and the `resubscribe` rule from plan 007.

4. **`CustomFieldController@store` body:** mirror `CustomFieldRules::forCreate()`.
5. **Response shapes:** add a response annotation for each of these, mirroring the exact array the code builds:
    - `TagController@index`, `SegmentController@index` / `show`, `MeController`, `CustomFieldController@index` / `store`;
    - `EventController@store` (202) and `EventController@index`;
    - `ContactController@archive`, `ContactListController@destroy`, `addContacts` and `removeContacts`.
6. **`custom_fields` in `ContactResource`:** a docblock or attribute that makes it `object` with `additionalProperties: true`.
7. **Pagination:** check that the cursor-paginated endpoints (`GET contacts`, `GET segments/{segment}/contacts`) show `per_page` (1–500, default 100), `cursor`, and the `links`/`meta` envelope.

Re-export and run `npx --yes @redocly/cli@latest lint packages/core/docs/openapi.json`. There should be no errors.

**Self-check:** for each endpoint, compare the spec against the existing `tests/Feature/ApiTest.php` assertions. Where a test asserts a JSON shape, the spec must agree with it.

Commit 2.

### Step 5: Serve the spec and a docs page from the package

1. **Config.** Add to `packages/core/config/marketing.php`, next to `api_prefix`:
    ```php
    'api_docs' => [
        'enabled' => (bool) env('MARKETING_API_DOCS', true),
    ],
    ```
    The spec describes open-source code, so it isn't secret, and it defaults to on. Operators can turn it off.
2. **Controller.** Create `src/Http/Controllers/ApiDocsController.php`:
    - `spec()`: read `__DIR__.'/../../../docs/openapi.json'`, decode it, set `servers` to `[['url' => url(config('marketing.api_prefix'))]]`, and return it as JSON with `Cache-Control: public, max-age=3600`. If the file is missing, return 404. Don't throw.
    - `page()`: return the `marketing::api-docs` view.
3. **View.** Create `resources/views/api-docs.blade.php`. It's a standalone HTML page that loads a viewer from a pinned CDN URL with an exact version, pointed at the spec route. Use Scalar (`@scalar/api-reference`) or Redoc, and record which in the report.
    - It must **not** use Inertia or the host app's layout, so it works in any install.
    - Add `$this->loadViewsFrom(__DIR__.'/../resources/views', 'marketing');` in the service provider. Check that the `marketing` view namespace isn't already used by the host app (`grep -rn "marketing::" resources app`).
4. **Routes.** In `registerRoutes()`, when `config('marketing.api_docs.enabled')` is true, register these **outside** the token-authenticated group, under the same prefix and `marketing.api.` name prefix, with the `api` middleware and a plain `throttle:60,1`:
    - `GET {prefix}/openapi.json` → `spec`, named `marketing.api.docs.spec`;
    - `GET {prefix}/docs` → `page`, named `marketing.api.docs`.

    Make sure Step 2's route filter (names starting `marketing.api.`) doesn't document these two. Exclude `marketing.api.docs*` explicitly.

5. **Security headers.** Plan 021 added security headers; check for a CSP. If there is one and it blocks the CDN viewer on the docs page, relax it for that route only, and say so in the report. If it can only be relaxed globally, STOP.

**Verify:**

- In the host app (Herd), `GET /api/v1/openapi.json` returns the spec with `servers[0].url` equal to `APP_URL` + `/api/v1`.
- `/api/v1/docs` renders, and "Try it" with a real token can call `GET /me`.

Commit 3.

### Step 6: Tests

Create `packages/core/tests/Feature/ApiDocsTest.php`. These tests must **not** need Scramble installed at test time; they read the committed file.

- The spec route returns 200 JSON, and its `servers[0].url` ends with the configured `marketing.api_prefix`. Override it to `api/v2` in one test, and expect `.../api/v2`. Registration reads config at boot, so check whether this needs `defineEnvironment` and re-registering routes.
- With `marketing.api_docs.enabled = false`, both docs routes 404 (use `defineEnvironment` / an attribute so the config is set before boot).
- The docs page returns 200 HTML that contains the spec route's URL.
- **Coverage guard:** every route whose name starts `marketing.api.` (excluding `marketing.api.docs*`) has a matching `paths[uri][method]` in `docs/openapi.json`, after stripping the prefix and converting `{param}` placeholders. A new endpoint without a re-export fails this test locally, before CI.
- The spec routes need no token (no 401).

Run the core tests, the host tests, PHPStan and Pint.

### Step 7: CI drift check, packaging and docs

1. **CI.** In `.github/workflows/tests.yml` job `core-mysql`, after "Install core dependencies", add:
    ```yaml
    - name: API spec is up to date
      working-directory: packages/core
      run: |
          vendor/bin/testbench scramble:export --path=docs/openapi.json
          git diff --exit-code docs/openapi.json || (echo "::error::Run 'vendor/bin/testbench scramble:export --path=docs/openapi.json' in packages/core and commit the result." && exit 1)
    ```
    If the export needs a database, move the step after the MySQL service is ready and give it the same env as the Pest step.
2. **Packaging.** In `packages/core/.gitattributes` (create it if needed), add `/workbench export-ignore` and `/testbench.yaml export-ignore`. **Keep `docs/` shipped.**
3. **`DEPLOYING.md`.** Add a short "API docs" section covering:
    - the docs are at `{APP_URL}/api/v1/docs` and the raw spec at `/api/v1/openapi.json`;
    - `MARKETING_API_DOCS=false` turns them off;
    - the spec can be imported into Postman, Insomnia or Bruno.
4. **Contributing note.** Next to the existing developer notes (find where they live; if there's no obvious place, put it in the report), add: "Changing an API endpoint? Re-export the spec: `cd packages/core && vendor/bin/testbench scramble:export --path=docs/openapi.json`."

Commit 4.

## Done criteria

- `packages/core/docs/openapi.json` is committed, passes `redocly lint` with no errors, and documents all 22 operations, including bodies, responses, permissions and error codes.
- `dedoc/scramble` is only in `packages/core`'s `require-dev`.
- Each install serves the spec with its own base URL, and a docs page, both behind `marketing.api_docs.enabled`.
- `ApiDocsTest` passes, including the coverage guard. All existing core and host tests pass.
- CI fails when the spec is stale.
- No API behaviour changed. `git diff 9f2f007 -- packages/core/src/Http/Controllers/Api` shows only docblocks, attributes and `use` lines.

## STOP conditions

- `dedoc/scramble` doesn't support Laravel 13.
- The export isn't deterministic and you can't make it so.
- Annotating an endpoint accurately would need a code change, for example a response that's impossible to describe. Report it instead of changing it.
- Documenting an endpoint shows behaviour that looks wrong (a wrong status code, a field leaking). Finish the docs to match the current behaviour and report the finding. Don't fix it here.
- A CSP from plan 021 can only be relaxed globally for the CDN viewer.
- The drift check finds changes other than added endpoints.

## Maintenance notes

- **The spec is a release artifact.** Re-export on every API change; CI and the coverage test enforce it. Breaking changes belong in `api/v2`, with `marketing.api_prefix` and `info.version` bumped together.
- **Follow-up:** converting the `response()->json` controllers (`Tag`, `Segment`, `Me`, `CustomField`, `Event`) into JsonResources would let Scramble infer their shapes and remove most of Step 4's response annotations. Make sure the JSON output stays byte-identical.
- **Hosted docs:** any provider that accepts a raw OpenAPI file (Scalar, Redocly, Mintlify) can point at `packages/core/docs/openapi.json` on `main`.
