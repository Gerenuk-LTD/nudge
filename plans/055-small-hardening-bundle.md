# Plan 055: Small hardening bundle: filled-subject lengths, variable names, setup token, role narrowing, event size, headers everywhere

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to the repo root unless
> they say otherwise. The six parts are independent: commit each separately.
> If anything in "STOP conditions" happens, stop and report. Don't
> improvise. When done, update this plan's row in `plans/README.md`, unless
> a reviewer dispatched you and told you they maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 6982215..HEAD -- packages/core/src/Campaigns/CampaignChecklist.php packages/core/src/Http/Requests/CampaignRequest.php packages/core/src/Http/Controllers/RoleController.php packages/core/src/Http/Controllers/Api/EventController.php bootstrap/app.php`
> If any of these changed, compare them with the "Current state" excerpts.
> On a mismatch, STOP. Plans 047 and 050 add lines to `bootstrap/app.php`
> (`trustHosts`, `trustProxies`, one middleware); that's expected.

## Status

- **Priority**: P3
- **Effort**: S (six small parts)
- **Risk**: LOW
- **Depends on**: none. If 047 and 050 haven't landed, Part F's excerpt is still accurate.
- **Category**: security (hardening) / bug
- **Planned at**: commit `6982215`, 2026-11-15

## Why this matters

Six small gaps from the round 6 audit. None is serious alone:

- **A.** A long variable value can push the filled subject, variant B subject or preheader past 255 characters. Test sends work (nothing is saved), but the real send fails when `PrepareCampaign` writes the 255-character snapshot columns on MySQL. The campaign gets stuck at preparation.
- **B.** Variable names have no length limit.
- **C.** The first-run `setup_token` isn't excluded from flashing, so a failed sign-up stores it in the session.
- **D.** Narrowing a role doesn't revoke invitations its members sent for higher roles, though changing one member's role does.
- **E.** `POST events` accepts `properties` of any size and depth. Each one is stored.
- **F.** Security headers (nosniff, X-Frame-Options, Referrer-Policy, HSTS) are added only to `web` routes. The API, the API docs page, and core's public tracking routes get none.

## Current state

**A. `packages/core/src/Jobs/PrepareCampaign.php::snapshot()`** writes:

```php
'subject' => MergeTags::fill((string) $campaign->subject, $values, escapeHtml: false),
'subject_b' => $campaign->ab_enabled && filled($campaign->ab_subject_b) ? MergeTags::fill((string) $campaign->ab_subject_b, $values, escapeHtml: false) : null,
'preheader' => $campaign->preheader === null ? null : MergeTags::fill($campaign->preheader, $values, escapeHtml: false),
```

The snapshot columns are `string` (255): `subject`, `preheader` (`packages/core/database/migrations/2026_10_29_000001_create_sending_tables.php:97-98`) and `subject_b` (`2026_10_30_000001_create_automation_tables.php:66`). `MergeTags::fill(string $template, array $values, bool $escapeHtml): string` fills variables only; per-recipient tags like `{{ first_name }}` are filled later at send time (they stay as tags, so they still count toward the length here, which is fine).

`packages/core/src/Campaigns/CampaignChecklist.php::for()` returns a list of `new ChecklistItem($key, $label, $passed, $blocking, $message)`. Examples:

```php
new ChecklistItem('subject', 'Subject line written', filled($campaign->subject), true, 'Write a subject line.'),
...
new ChecklistItem('variables', 'Variables have values', $missing === [], true,
    $missing === [] ? null : (string) __('Give these a value in Variables: :tags.', [...])),
```

`$values = $campaign->variables ?? [];` is already computed in `for()`.

**B. `packages/core/src/Http/Requests/CampaignRequest.php:41`**, inside the `variables` closure:

```php
foreach (array_keys((array) $value) as $key) {
    if (! preg_match('/^[a-z][a-z0-9_]*$/', (string) $key) || in_array($key, MergeTags::BUILT_IN, true)) {
        $fail(__('Variable names use lowercase letters, numbers and underscores.'));

        return;
    }
}
```

and `'variables.*' => ['nullable', 'string', 'max:1000']`.

**C. `bootstrap/app.php`**, `withExceptions`:

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
    );
})->create();
```

The field is read in `app/Actions/Fortify/CreateNewUser.php:27` (`$input['setup_token'] ?? null`).

**D. `packages/core/src/Http/Controllers/RoleController.php::update()`**:

```php
DB::transaction(function () use ($role, $validated): void {
    $role->update($validated);

    // Members' API tokens can't keep permissions the role no longer grants.
    foreach ($role->memberships()->pluck('user_id') as $userId) {
        ApiToken::narrowForUser($role->workspace_id, (int) $userId, $role->permissions);
    }
});
```

The pattern to copy, from `packages/core/src/Actions/Workspaces/ChangeMemberRole.php`:

```php
// Invitations they sent for roles above their new one would hand out access they no longer have.
Invitation::withoutGlobalScopes()
    ->with('role')
    ->where('workspace_id', $membership->workspace_id)
    ->where('invited_by', $membership->user_id)
    ->get()
    ->reject(fn (Invitation $invitation) => $invitation->role->isWithin($role))
    ->each(fn (Invitation $invitation) => $invitation->delete());
```

**E. `packages/core/src/Http/Controllers/Api/EventController.php:46`**: `'properties' => ['sometimes', 'array'],`. The method carries Scramble attributes documenting its responses; the committed spec is `packages/core/docs/openapi.json`, and CI fails when the spec drifts from the code. Find the re-export command in the `DocsServiceProvider` docblock (`grep -rn "openapi" packages/core/src/Docs* packages/core/src/**/DocsServiceProvider.php`).

**F. `bootstrap/app.php`**: `SecurityHeaders::class` is in `$middleware->web(append: [...])`. Core registers its other routes in `packages/core/src/MarketingServiceProvider.php::registerRoutes()`: `routes/public.php` with **no** middleware group, `routes/public-pages.php` with `web`, `routes/api.php` with `api`, and the docs routes with `api`. `app/Http/Middleware/SecurityHeaders.php` never overwrites a header the response already set.

**Tests**: checklist tests in `packages/core/tests/Feature/SendingTest.php:339` ("the checklist flags merge tags that nothing will fill"); role tests in `packages/core/tests/Feature/MembersAndRolesTest.php`; event tests in `packages/core/tests/Feature/ApiTest.php` and `EventIdempotencyTest.php`; headers in `tests/Feature/SecurityHeadersTest.php` (host); registration in `tests/Feature/Auth/RegistrationTest.php`.

## Commands

From the repo root (Git Bash: `php.bat`/`composer.bat`).

| Purpose         | Command                                                       | Expected                                      |
| --------------- | ------------------------------------------------------------- | --------------------------------------------- |
| Host tests      | `php artisan test --compact`                                  | all pass                                      |
| Core tests      | `cd packages/core && vendor/bin/pest`                         | all pass, except the 7 Windows-only SES tests |
| PHPStan         | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`  | 0 errors                                      |
| Pint            | `vendor/bin/pint --test`                                      | passes                                        |

## Scope

**In scope:** `CampaignChecklist.php`, `CampaignRequest.php`, `RoleController.php` (`update()` only), `Api/EventController.php` (the `properties` rule and its docs attributes), `packages/core/docs/openapi.json` (regenerated only), `bootstrap/app.php`, and the test files named in each part.

**Out of scope:** widening the snapshot columns (a migration; the checklist is the cheaper fix), the campaign editor UI (checklist items already show there), CSP headers, `SecurityHeaders` itself.

## Git workflow

- Branch off `dev`: `advisor/055-hardening-bundle`.
- One commit per part, e.g. `fix(core): the checklist blocks subjects too long once filled`, each ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Part A: Filled lengths

In `CampaignChecklist::for()`, compute the filled lengths with `mb_strlen(MergeTags::fill(..., $values, escapeHtml: false))` for the subject, the preheader (when not null) and variant B's subject (when A/B is on and it's filled). Add a **blocking** item after `variables`:

```php
new ChecklistItem('lengths', 'Subject and preheader fit', $tooLong === [], true,
    $tooLong === [] ? null : (string) __('With variables filled in, these are longer than 255 characters: :fields. Shorten them or their variables.', ['fields' => implode(', ', $tooLong)])),
```

where `$tooLong` lists `'subject'`, `'variant B subject'` and `'preheader'` as applicable. Wrap the field labels in `__()` too.

Check the subject column length is 255 on MySQL (`string()` default). Use `mb_strlen`, because MySQL's `VARCHAR(255)` counts characters.

Test, in `SendingTest.php`: a campaign whose subject is `'{{ long }}'` with variable `long` set to 300 `a`s fails `lengths` and can't be scheduled (`assertSessionHasErrors('campaign')`). With 200, it passes. Revert check: remove the item → the test fails.

**Verify**: core tests pass.

### Part B: Variable name length

In the `CampaignRequest` closure, also fail when `strlen((string) $key) > 50`, with `__('Variable names are at most 50 characters.')`. Add the check as a separate `if` before the existing one, so each message stays accurate.

Test (find the existing variables validation test with `grep -n "Variable names" packages/core/tests/Feature/*.php` and add next to it): a 51-character name is refused; a 50-character one is accepted.

**Verify**: core tests pass.

### Part C: Never flash the setup token

In `bootstrap/app.php`'s `withExceptions` closure, add `$exceptions->dontFlash(['setup_token']);`. Check the method exists in the installed Laravel (`grep -n "function dontFlash" vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Exceptions.php`). If it doesn't, STOP.

Test, in `tests/Feature/Auth/RegistrationTest.php`: as the first user, with a configured token, post a registration that fails validation (for example mismatched passwords) with `setup_token` set. Then `$this->assertSessionMissing('_old_input.setup_token')` (or check `session()->getOldInput('setup_token')` is null), while `_old_input.email` is present. Revert check: remove the line → the test fails.

**Verify**: host tests pass.

### Part D: Narrowing a role revokes its members' invitations above it

In `RoleController::update()`, inside the transaction, after the token loop, delete invitations sent by the role's members for roles not within the narrowed role, copying `ChangeMemberRole`'s query with `->whereIn('invited_by', $role->memberships()->pluck('user_id'))` instead of a single `invited_by`. Call `$role->refresh()` after the update if `isWithin()` needs the new permissions loaded (read `Role::isWithin` to check what it compares). Keep the comment style.

Test, in `MembersAndRolesTest.php` (find the existing role-narrowing test, `grep -n "narrow" packages/core/tests/Feature/MembersAndRolesTest.php`, and copy its setup): a member of a custom role with `members.invite` and `campaigns.manage` invites someone to a custom role that also has only those permissions, plus another invitation to a role with `campaigns.manage` alone. Narrow the first role by removing `campaigns.manage`. The invitation for the role that now exceeds it is deleted; one still within it stays. Check which permission names exist (`packages/core/src/Enums/Permission.php`) before writing.

**Verify**: core tests pass.

### Part E: Event properties are bounded

Change the rule to:

```php
'properties' => ['sometimes', 'array', 'max:50', function (string $attribute, mixed $value, Closure $fail): void {
    $json = json_encode($value, 0, 5);

    if ($json === false) {
        $fail(__('Properties can be nested at most 4 levels deep.'));
    } elseif (strlen($json) > 16384) {
        $fail(__('Properties can be at most 16 KB as JSON.'));
    }
}],
```

`json_encode`'s third argument is the maximum depth; it returns `false` when exceeded. Check the depth in a quick test so the message matches what's allowed (the array itself is level 1). Adjust the number in the message, not the limit, if they disagree.

Document the limits in the endpoint's existing Scramble attributes (the 422 description), then regenerate `packages/core/docs/openapi.json` with the repo's export command. Don't hand-edit the JSON.

Tests, in `ApiTest.php` next to the existing events tests: 51 top-level keys → 422; 6 levels deep → 422; a 20 KB string value → 422; a normal payload still returns the existing success status.

**Verify**: core tests pass, including the spec drift test (`ApiDocsTest` "every API route is in the committed spec").

### Part F: Security headers on every response

In `bootstrap/app.php`, remove `SecurityHeaders::class` from the `web(append: [...])` list and add `$middleware->append(SecurityHeaders::class);`, which makes it global middleware. Global middleware runs for every route, including core's `public.php` routes (no group) and the API.

Test, in `tests/Feature/SecurityHeadersTest.php`, following its existing assertions: a GET to the API docs spec (`route('marketing.api.docs.spec')`, if `MARKETING_API_DOCS` is on in tests) and an unauthenticated GET to `route('marketing.api.me')` (401) both carry `X-Content-Type-Options: nosniff` and `X-Frame-Options`. Check the route names with `php artisan route:list --path=api`.

**Verify**: host tests pass.

### Finish

**Verify**: host tests, core tests, PHPStan and Pint pass.

## Done criteria

- [ ] A: the checklist blocks a subject, variant B subject or preheader longer than 255 characters once variables are filled
- [ ] B: variable names longer than 50 characters are refused
- [ ] C: `setup_token` is never in the session's old input
- [ ] D: narrowing a role deletes its members' invitations for roles above it
- [ ] E: `properties` is limited to 50 keys, 4 levels and 16 KB, and the spec is regenerated
- [ ] F: API and public responses carry the security headers
- [ ] Each part's test passes, and the revert checks in A and C fail as described
- [ ] Host tests, core tests, PHPStan and Pint pass
- [ ] `plans/README.md` row updated

## STOP conditions

- The drift check shows changes the excerpts don't cover.
- `dontFlash` doesn't exist on `Exceptions` (Part C).
- The spec export changes operations other than `POST events` (Part E).
- A global `SecurityHeaders` breaks an existing test, for example one that asserts a tracking pixel's headers exactly (Part F).

## Maintenance notes

- If the snapshot columns are ever widened, Part A's limit should follow them.
- If clients send larger event properties legitimately, raise the 16 KB limit in one place, and the docs with it.
- Round 6 also found, not part of this plan: the Campaign segment field only lists the latest 100 sent broadcasts (`packages/core/src/Segments/SegmentFieldCatalog.php:52-54`), so "resend to non-openers" fails for older campaigns. Still in the backlog.
