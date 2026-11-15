# Plan 057: A clearer roles page: role list, compare table, and a permissions dialog with descriptions

> **Executor instructions**: Follow the steps in order, and run each
> verification before moving on. Paths are relative to `packages/core/`
> unless they say "root". If anything in "STOP conditions" happens, stop
> and report. Don't improvise. When done, update this plan's row in
> `plans/README.md`, unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 1b6cc19..HEAD -- packages/core/src/Enums/Permission.php packages/core/src/Http/Controllers/RoleController.php packages/core/src/Http/Controllers/ApiTokenController.php packages/core/src/Models/Role.php packages/core/src/Actions/Workspaces/CreateWorkspace.php packages/core/resources/js/components/permission-picker.tsx packages/core/resources/js/pages/marketing/workspace/roles.tsx packages/core/resources/js/pages/marketing/workspace/api-tokens.tsx resources/js/components/ui/checkbox.tsx`
> If any of these changed, compare them with the "Current state" excerpts.
> On a mismatch, STOP. One exception: plan 055 (part D) adds invitation
> revoking inside `RoleController::update()`'s transaction. That's expected;
> keep it.

## Status

- **Priority**: P2 (UI improvement requested by the maintainer, 2026-11-15)
- **Effort**: M
- **Risk**: LOW. It's mostly UI. The server changes are two new columns on `roles` and two new rules: built-in roles can't be renamed or deleted.
- **Depends on**: none. If plan 055 is pending, run it first or after; they touch different lines of `RoleController`.
- **Category**: direction (UX)
- **Planned at**: commit `1b6cc19`, 2026-11-15

## Why this matters

The current roles page is one table: a row per role and a column per area ("Contacts", "Campaigns" and so on), with short values like "View, edit". The edit dialog is a two-column grid of checkboxes labelled with long sentences. That has three problems:

- you can't see exactly what a role can do without opening it;
- you can't compare roles permission by permission;
- nothing explains what a permission covers. For example, it doesn't say that "Send campaigns" also covers cancelling them and picking A/B winners.

The maintainer's other app, Rallyboard, does this well. They shared three screenshots, described below. This plan brings the same pattern here.

**The reference (Rallyboard), described:**

1. **Role list** (a card titled "Roles", with the text "Built-in roles can't be renamed or deleted, but you can change what Admin, Member, and Viewer are allowed to do. Create a custom role for anything else."). It has one row per role. Each row shows:
    - the role name in semibold, with a small outlined monospaced uppercase `BUILT-IN` badge;
    - a one-line description below it ("Runs the team day to day: members, roles, activities, scoreboards, and scores.");
    - a muted line, "0 members · 16 of 25 permissions";
    - a `⋯` menu on the right, except on the Owner row.

    After the built-ins comes "No custom roles yet." (or the custom roles).

2. **Compare roles** (a second card, "What each role can do. Expand a group to see its individual permissions."). It has an "Expand all" button, then a table:
    - The first column is "PERMISSION" (uppercase, muted, monospaced), followed by one column per role.
    - Each group row has a chevron and the group name in semibold. For each role it shows "All", "None" or "4 of 5".
    - Expanding a group shows its permissions as rows: a label, a muted description, then a check mark (primary colour) or a muted dash for each role.
3. **Edit dialog** (titled "Edit 'Admin' permissions", with the text "Built-in roles keep their name, but you can choose what they're allowed to do. Changes apply straight away to 0 members with this role."):
    - A single-column, scrolling list, with a header row per group: a checkbox for the whole group, a small uppercase monospaced name and "0 of 5" on the right.
    - Under each header, its permissions: a checkbox, the label, and a muted description.
    - A footer: "No changes" on the left (or "3 changes"), then "Cancel" and "Save changes".

## Decisions made in this plan (the maintainer can override before execution)

1. **Built-in roles** are the four that every workspace starts with: Owner, Admin, Editor, Viewer. A new nullable `roles.key` column (`owner`, `admin`, `editor`, `viewer`) marks them; custom roles have `key = null`. Built-in roles:
    - **can't be renamed** (422 "Built-in roles keep their name.");
    - **can't be deleted** (403);
    - **can have their permissions changed**, except Owner, whose permissions still can't be changed.
2. **Descriptions:** a new nullable `roles.description` column (max 255).
    - Built-ins get fixed descriptions when the workspace is created, and the edit dialog doesn't show the field for them.
    - Custom roles get an optional "Description" field in the dialog.
3. **Permission labels and descriptions:** `Permission::label()` becomes a short name ("Send campaigns"), and a new `Permission::description()` explains what the permission covers. The texts are in Step 2. They're grounded in what each permission guards in `routes/web.php`.
4. **Groups** stay as they are in `permission-picker.tsx`: Contacts, Campaigns, Automations, Sending, Workspace.
5. **Permissions you don't hold:** in the roles dialog, they're shown but disabled. A tooltip says "You can't give out a permission you don't have." The server already refuses them, in `RoleController::validated()`. The API tokens dialog keeps its current behaviour: it only lists grantable permissions.
6. **No data migration:** the app is unreleased. Existing local workspaces won't have `key` or `description` on their roles until the database is rebuilt (`php artisan migrate:fresh --seed` at the root). Don't write a backfill.
7. **The "Duplicate" menu item** stays on every role, Owner included (Owner's menu then has only Duplicate). Rallyboard has no menu on Owner, but duplicating Owner is a useful start for a near-full custom role.

## Current state

**`src/Enums/Permission.php`**: 15 cases, with `label()` returning a sentence per case and `values()` returning all values:

```php
case ContactsView = 'contacts.view';      // label: 'View contacts, lists, tags and segments'
case ContactsManage = 'contacts.manage';  // 'Create, edit and archive contacts, lists, tags and custom fields'
case ContactsImport = 'contacts.import';  // 'Import contacts from CSV'
case SegmentsManage = 'segments.manage';  // 'Create and edit segments'
case CampaignsView = 'campaigns.view';    // 'View campaigns and their reports'
case CampaignsManage = 'campaigns.manage';// 'Create and edit draft campaigns and email templates'
case CampaignsSend = 'campaigns.send';    // 'Send and schedule campaigns'
case CampaignsApprove = 'campaigns.approve'; // 'Approve campaigns when approval is required'
case ProvidersManage = 'providers.manage';   // 'Connect sending providers and change sending settings'
case AutomationsView = 'automations.view';   // 'View automations and their reports'
case AutomationsManage = 'automations.manage'; // 'Create, edit, start and stop automations'
case MembersManage = 'members.manage';    // 'Invite and remove members'
case RolesManage = 'roles.manage';        // 'Create and edit roles'
case ApiTokensManage = 'api_tokens.manage'; // 'Create and revoke API tokens'
case WorkspaceManage = 'workspace.manage';  // 'Rename the workspace'
```

`label()` is used only by `RoleController::index()` and `ApiTokenController::index()` (both map to `['value' => ..., 'label' => ...]`). Check for other uses with `grep -rn "label()" src tests | grep -i permission`.

**`src/Models/Role.php`**: `#[Fillable(['workspace_id', 'name', 'permissions', 'is_owner'])]`; casts `permissions` array and `is_owner` boolean; `memberships()`; `isWithin(Role $actor)`; `grants(Permission)`. It uses the `BelongsToWorkspace` trait.

**The `roles` table** (`database/migrations/2026_10_28_000001_create_tenancy_tables.php`): `id`, `workspace_id`, `name`, `permissions` (json), `is_owner`, timestamps, `unique(['workspace_id', 'name'])`.

**`src/Actions/Workspaces/CreateWorkspace.php`** creates the default roles:

```php
foreach (self::defaultRoles() as $roleName => [$permissions, $isOwner]) {
    $role = Role::withoutGlobalScopes()->create([
        'workspace_id' => $workspace->id,
        'name' => $roleName,
        'permissions' => $permissions,
        'is_owner' => $isOwner,
    ]);
    ...
}

/** @return array<string, array{0: list<string>, 1: bool}> */
public static function defaultRoles(): array
{
    $everything = Permission::values();

    return [
        'Owner' => [$everything, true],
        'Admin' => [$everything, false],
        'Editor' => [[ ...contacts view/manage/import, segments, campaigns view/manage/send, automations view/manage... ], false],
        'Viewer' => [[Permission::ContactsView->value, Permission::CampaignsView->value, Permission::AutomationsView->value], false],
    ];
}
```

`defaultRoles()` has no other callers (`grep -rn defaultRoles src tests ../../app ../../database`).

**`src/Http/Controllers/RoleController.php`**:

- `index()` renders `marketing/workspace/roles` with:
    - `roles`: `id`, `name`, `permissions` (all of them for the owner), `is_owner` and `members_count`, ordered by id;
    - `permissions`: `{value, label}`.
- `update()` aborts 403 for the owner role and for roles above the actor (`isWithin`), validates through `validated()`, then updates and narrows members' API tokens in a transaction.
- `destroy()` aborts 403 for the owner role and for roles above the actor, and refuses (422) when the role has members.
- `validated()`:
    - checks `name` (required, max 50, unique per workspace) and `permissions` (present, array, valid enum values);
    - de-duplicates the permissions;
    - throws 422 "You can only give out permissions you have yourself." when a non-owner actor grants something they lack.
- `actingRole()` returns the actor's role.

**`resources/js/pages/marketing/workspace/roles.tsx`** (380 lines):

- One card: a header with "New role", then a table with one column per `PERMISSION_AREAS` area, using `AccessLevel` dots.
- `RoleMenu`: Edit / Duplicate / Delete.
- `RoleForm`: a dialog with a name field and `PermissionPicker`.
- `ConfirmDialog` for deleting.

Keep its imports and patterns: Wayfinder `RoleController.update(role.id)` with `form.submit(...)`, and `ConfirmDialog` from `@marketing/components/confirm-dialog`.

**`resources/js/components/permission-picker.tsx`** exports:

- `GROUPS` (internal): area titles with `[permission, shortName]` pairs;
- `NEEDS` (internal): `contacts.manage`, `contacts.import` and `segments.manage` need `contacts.view`; the campaigns ones need `campaigns.view`; `automations.manage` needs `automations.view`;
- `permissionSummary()`, `PERMISSION_AREAS`, `permissionLevels()`;
- `PermissionPicker({ options, value, onChange })`: a bordered list of fieldsets, each with an "Allow all / Allow none" text button and a two-column checkbox grid. Ticking something also ticks what it needs; unticking a "view" unticks what needs it.

`api-tokens.tsx` uses `PermissionPicker` and `permissionSummary`.

**`resources/js/components/ui/checkbox.tsx`** (root, the shadcn checkbox) always renders a `CheckIcon` in its indicator. It has no indeterminate look.

**Tests**: `tests/Feature/MembersAndRolesTest.php`. Pattern:

```php
test('the owner role cannot be edited or deleted', function () {
    [$owner] = $this->createOwner();
    $ownerRole = roleNamed('Owner');

    $this->actingAs($owner)->put(route('marketing.roles.update', $ownerRole), ['name' => 'Boss', 'permissions' => []])->assertForbidden();
    $this->actingAs($owner)->delete(route('marketing.roles.destroy', $ownerRole))->assertForbidden();
});
```

`roleNamed()` is a global helper; `$this->addMember($workspace, 'Editor')` adds a member with that role. Frontend tests are Vitest, and only pick up `**/*.test.ts` files (pure modules, not `.tsx`). See `resources/js/lib/merge-tags.test.ts` for the style.

## Commands

From `packages/core` (PowerShell: `php`/`composer`; Git Bash: `php.bat`/`composer.bat`), unless they say "root".

| Purpose         | Command                                                 | Expected                                      |
| --------------- | ------------------------------------------------------- | --------------------------------------------- |
| Core tests      | `vendor/bin/pest`                                       | all pass, except the 7 Windows-only SES tests |
| One file        | `vendor/bin/pest tests/Feature/MembersAndRolesTest.php` | all pass                                      |
| PHPStan (root)  | `vendor/bin/phpstan analyse --memory-limit=1G`          | 0 errors                                      |
| Pint (root)     | `vendor/bin/pint --test packages/core`                  | passes                                        |
| Frontend (root) | `npm run check`, `npm run types:check`, `npm run test`  | pass                                          |

## Scope

**In scope:**

- `database/migrations/2026_11_15_000002_add_key_and_description_to_roles_table.php` (new; use `_000001` if plan 056's migration isn't there, and bump the date if a later migration exists)
- `src/Models/Role.php`
- `src/Enums/Permission.php`
- `src/Actions/Workspaces/CreateWorkspace.php`
- `src/Http/Controllers/RoleController.php`
- `src/Http/Controllers/ApiTokenController.php` (pass `description` with each permission)
- `resources/js/lib/permissions.ts` and `resources/js/lib/permissions.test.ts` (new: the pure logic moved out of the picker)
- `resources/js/components/permission-picker.tsx`
- `resources/js/pages/marketing/workspace/roles.tsx`
- `resources/js/pages/marketing/workspace/api-tokens.tsx` (only for the new import paths and the `description` field)
- `resources/js/components/ui/checkbox.tsx` (root: the indeterminate look)
- `tests/Feature/MembersAndRolesTest.php`

**Out of scope:**

- The members page and its role picker. Role names and ids stay the same, so it keeps working.
- What permissions exist, and what each one guards. This plan only renames and describes them.
- The settings layout and its tabs.
- The API spec (`docs/openapi.json`). Roles aren't in the API.

## Git workflow

- Branch off `dev`: `advisor/057-roles-page-redesign`.
- Commits per step are fine, such as `feat(core): built-in roles keep their name` and `feat(core): compare roles and describe each permission`. Each ends with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: `key` and `description` on roles

Migration:

```php
Schema::table('roles', function (Blueprint $table) {
    $table->string('key', 20)->nullable()->after('name');
    $table->string('description', 255)->nullable()->after('key');
    $table->unique(['workspace_id', 'key']);
});
```

Its `down()` drops the unique index and both columns. Several `null` keys in one workspace are allowed by both MySQL and SQLite unique indexes.

In `Role`:

- add `key` and `description` to `#[Fillable]` and the `@property` docblock (`?string`);
- add `public function isBuiltIn(): bool { return $this->key !== null; }`.

In `CreateWorkspace`, change `defaultRoles()` to return a keyed list with a name, description, permissions and owner flag:

```php
/**
 * @return array<string, array{name: string, description: string, permissions: list<string>, is_owner: bool}>
 */
public static function defaultRoles(): array
{
    $everything = Permission::values();

    return [
        'owner' => ['name' => 'Owner', 'description' => 'Full control of the workspace, including making other people owners.', 'permissions' => $everything, 'is_owner' => true],
        'admin' => ['name' => 'Admin', 'description' => 'Runs the workspace day to day: members, roles, sending, campaigns and contacts.', 'permissions' => $everything, 'is_owner' => false],
        'editor' => ['name' => 'Editor', 'description' => 'Builds and sends campaigns and automations, and looks after contacts.', 'permissions' => [...the same list as now...], 'is_owner' => false],
        'viewer' => ['name' => 'Viewer', 'description' => 'Sees contacts, campaigns and automations without changing anything.', 'permissions' => [...the same list as now...], 'is_owner' => false],
    ];
}
```

Update the loop to `foreach (self::defaultRoles() as $key => $role)` and create with `'key' => $key`, `'name' => $role['name']`, `'description' => $role['description']`, `'permissions' => $role['permissions']` and `'is_owner' => $role['is_owner']`. Keep the `$ownerRole` logic.

**Verify**: `vendor/bin/pest tests/Feature/MembersAndRolesTest.php tests/Feature/WorkspaceTest.php` → all pass.

### Step 2: Short labels and descriptions for permissions

In `Permission`, replace `label()`'s texts and add `description()`:

| Case                 | `label()`          | `description()`                                                                                    |
| -------------------- | ------------------ | -------------------------------------------------------------------------------------------------- |
| `contacts.view`      | View contacts      | See contacts, lists, tags, custom fields, segments and suppressions.                               |
| `contacts.manage`    | Manage contacts    | Add, edit, archive and unsubscribe contacts, and edit lists, tags, custom fields and suppressions. |
| `contacts.import`    | Import contacts    | Upload CSV files of contacts and see past imports.                                                 |
| `segments.manage`    | Manage segments    | Create, edit and delete segments.                                                                  |
| `campaigns.view`     | View campaigns     | See campaigns, their reports and email templates.                                                  |
| `campaigns.manage`   | Edit campaigns     | Create, edit, duplicate, test and delete draft campaigns, and manage email templates.              |
| `campaigns.send`     | Send campaigns     | Schedule, unschedule and cancel campaigns, and pick A/B test winners.                              |
| `campaigns.approve`  | Approve campaigns  | Approve campaigns and automations when the workspace requires approval.                            |
| `providers.manage`   | Manage sending     | Connect and test sending providers, and change sending settings.                                   |
| `automations.view`   | View automations   | See automations and their reports.                                                                 |
| `automations.manage` | Manage automations | Create, edit, start, pause and delete automations.                                                 |
| `members.manage`     | Manage members     | Invite people, remove members and change their roles.                                              |
| `roles.manage`       | Manage roles       | Create, edit and delete roles. Nobody can give out a permission they don't have.                   |
| `api_tokens.manage`  | Manage API tokens  | Create and revoke API tokens. A token can only do what its creator can.                            |
| `workspace.manage`   | Rename workspace   | Change the workspace's name.                                                                       |

Before writing them, check each description against `routes/web.php`: grep for `can:<permission>` and read the routes in that group. If a description promises something the routes don't support, or misses something major, correct the text and list the change in your report. If plan 056 has landed, add "and see the API log" to `api_tokens.manage`.

Add a `description` key next to `label` in both `RoleController::index()` and `ApiTokenController::index()`.

**Verify**: `vendor/bin/pest` → all pass. If a test asserts an old label text, update the assertion to the new label and say which test.

### Step 3: Built-in rules in `RoleController`

- `index()`:
    - Order built-ins first in `defaultRoles()` order (owner, admin, editor, viewer), then custom roles by name. Sort the collection in PHP using `array_keys(CreateWorkspace::defaultRoles())` for the built-in order.
    - Add `key`, `description` and `is_builtin` to each role.
    - Add the prop `grantable`: every permission value for an owner actor, otherwise the actor role's `permissions`.
- `update()`: after the existing checks, if `$role->isBuiltIn()` and the submitted `name` differs from `$role->name`, throw `ValidationException::withMessages(['name' => __('Built-in roles keep their name.')])`.
- `destroy()`: `abort_if($role->isBuiltIn(), 403);` next to the owner check.
- `validated()`:
    - accept `description` (`['nullable', 'string', 'max:255']`) and return it;
    - for built-in roles, return the role's existing description instead of the submitted one;
    - update the return type's docblock.
- `store()` is unchanged apart from saving the description. New roles never get a `key`.

**Verify**: `vendor/bin/pest tests/Feature/MembersAndRolesTest.php` → all pass.

### Step 4: Move the permission logic into `lib/permissions.ts`, with tests

Create `resources/js/lib/permissions.ts`:

1. Move `GROUPS`, `NEEDS`, `permissionSummary`, `PERMISSION_AREAS` and `permissionLevels` there from `permission-picker.tsx`. Export `GROUPS` as `PERMISSION_GROUPS`. Re-export the moved functions from `permission-picker.tsx`, so `api-tokens.tsx` keeps working unchanged, or update its imports; either is fine.
2. Add these pure functions:
    - `togglePermission(value: Permission[], permission: Permission, on: boolean, order: Permission[]): Permission[]`: the current `set` logic from the picker (ticking adds what it needs; unticking a needed permission removes its dependents), returning the result in `order`.
    - `toggleGroup(value, permissions, on, order)`: the current `setGroup` logic, plus: turning a group on also adds what each permission needs.
    - `groupCoverage(permissions: Permission[], group: Permission[]): { granted: number; total: number; label: 'All' | 'None' | string }`. The label is "All" when everything is granted, "None" when nothing is, and otherwise "`granted` of `total`".
    - `countChanges(before: Permission[], after: Permission[]): number`: the size of the symmetric difference.

`resources/js/lib/permissions.test.ts` covers:

- ticking `contacts.manage` also adds `contacts.view`;
- unticking `campaigns.view` removes `campaigns.manage`, `campaigns.send` and `campaigns.approve`;
- turning on the Campaigns group adds all four campaign permissions;
- `groupCoverage` returns "All", "None" and "2 of 4";
- `countChanges(['a', 'b'], ['b', 'c'])` returns 2 (cast the strings as `Permission`).

**Verify** (root): `npm run test` → passes, including the new file; `npm run types:check` → passes.

### Step 5: An indeterminate checkbox

In the root `resources/js/components/ui/checkbox.tsx`:

- render `MinusIcon` (from `lucide-react`) instead of `CheckIcon` when `props.checked === 'indeterminate'`;
- give `data-[state=indeterminate]` the same background, text and border classes as `data-[state=checked]`.

Radix already sets `data-state="indeterminate"` when it's passed `checked="indeterminate"`. Nothing else about the component changes.

**Verify** (root): `npm run types:check` → passes. `grep -rn "<Checkbox" resources/js packages/core/resources/js | wc -l` shows several existing uses. They pass booleans, so they're unaffected.

### Step 6: The new `PermissionPicker`

Rewrite `PermissionPicker` (same props, plus an optional `grantable?: Permission[]`) to match the reference dialog:

- A single-column list (no border around the whole list), grouped by `PERMISSION_GROUPS`, skipping permissions not in `options`, as now.
- **Group header row**:
    - a `Checkbox` with `checked` set to `true`, `false` or `'indeterminate'` from `groupCoverage`, which calls `toggleGroup`;
    - the group title in `text-xs font-medium uppercase tracking-wide text-muted-foreground font-mono`;
    - "`granted` of `total`" on the right, in `text-xs text-muted-foreground tabular-nums`;
    - a bottom border, `pb-2`.

    Wrap the checkbox and title in a `<label>` so clicking the title toggles the group.

- **Permission rows** under the header (`grid gap-3 py-3`): a `Checkbox` (`mt-0.5`), then the label (`text-sm`) with the description under it (`text-xs text-muted-foreground`). The whole row is a `<label>`. They call `togglePermission`.
- **Not grantable**: when `grantable` is given and a permission isn't in it, its checkbox is `disabled` and wrapped in a `Tooltip` with "You can't give out a permission you don't have." Copy the Tooltip usage from `components/sending-status.tsx`. A group's checkbox only toggles the grantable permissions in it.
- Remove the "Allow all / Allow none" buttons; the group checkbox replaces them.

The `options` type becomes `{ value: Permission; label: string; description: string }`. Update the `PermissionOption` types in `roles.tsx` and `api-tokens.tsx`.

**Verify** (root): `npm run types:check` → passes; `npm run check` → passes. Open the API tokens page's "New token" dialog (`composer run dev`, or the repo's usual dev command) and check the picker still works there.

### Step 7: The roles page

Rewrite `roles.tsx` as two cards, matching the reference. Use the same card shell as now (`rounded-xl border bg-card shadow-xs`, a header with `border-b px-5 py-4 sm:px-6`).

**Card 1, "Roles":**

- **Header:**
    - title "Roles";
    - description "Built-in roles can't be renamed or deleted, but you can change what Admin, Editor and Viewer are allowed to do. Create a custom role for anything else.";
    - a "New role" button on the right, as now.
- **Role rows**, one per role (`divide-y`), each `px-5 py-4 sm:px-6`:
    - Line 1: the name in `font-medium`, with the `Crown` icon for Owner as now. Built-ins get a `Badge` with `variant="outline"` and `className="font-mono text-[10px] uppercase tracking-wide"` reading "Built-in".
    - Line 2: the description in `text-sm text-muted-foreground`, when there is one.
    - Line 3: `text-xs text-muted-foreground`, reading "`n` member(s) · `granted` of `total` permissions". `total` is `permissions.length` from the props; Owner shows `total` of `total`.
    - The `RoleMenu` on the right:
        - **Edit** for every role except Owner. The label is "Edit permissions" for built-ins and "Edit" for custom roles.
        - **Duplicate** for every role.
        - **Delete** only for custom roles, still disabled when the role has members.
- After the built-ins: if there are no custom roles, a muted row reading "No custom roles yet."

**Card 2, "Compare roles"** (header description "What each role can do. Expand a group to see its individual permissions."):

- Below the header, a `Button variant="outline" size="sm"` toggling "Expand all" / "Collapse all". Keep the expanded group titles in `useState<Set<string>>`.
- A table in `overflow-x-auto`:
    - Header row (`bg-muted/40`): "Permission" (`font-mono text-xs uppercase tracking-wide text-muted-foreground`), then one centred column per role with its name.
    - The first column is `sticky left-0 bg-card` (`bg-muted/40` in the header), with a right border and `min-w-64`, so it stays visible when many roles scroll sideways.
    - Per group: a row with a chevron button (`ChevronRight`, rotated 90° when open, `aria-expanded`, `aria-controls`) and the group title in `font-medium`. Each role's cell shows `groupCoverage(role.permissions, group).label`, with "None" muted.
    - When a group is open: one row per permission. The first cell is indented to line up with the group title, showing the label (`text-sm`) and the description (`text-xs text-muted-foreground`). Each role's cell shows a `Check` icon (`size-4 text-primary`, with `<span className="sr-only">Allowed</span>`) or a muted `—` (with `<span className="sr-only">Not allowed</span>`).
- Remove the old area-columns table, `AccessLevel`, and the footer note about owners.

**Dialog (`RoleForm`):**

- **Title:** "Edit '{name}' permissions" for a built-in role, "Edit {name}" for an existing custom role, "New role" otherwise.
- **Description:** for built-ins, "Built-in roles keep their name, but you can choose what they're allowed to do." Then, for any existing role, "Changes apply straight away to {n} member(s) with this role." New roles get "Choose what people with this role can do in the workspace."
- **Fields:**
    - Custom and new roles: the name field, as now, and a new "Description" field (`Input`, optional, max 255, placeholder "What this role is for"), with errors shown by `InputError`.
    - Built-ins: neither field. Still submit `name: role.name` and `description: role.description`.
- **Layout:** the header and footer stay put, and the permissions scroll. Make `DialogContent` `flex max-h-[calc(100svh-2rem)] flex-col sm:max-w-xl`, and put the picker in a `min-h-0 flex-1 overflow-y-auto` area. Remove `overflow-y-auto` from `DialogContent` itself.
- **Picker:** `PermissionPicker` with `grantable={grantable}`.
- **Footer:**
    - on the left, a muted change count: "No changes" or "{n} change(s)", from `countChanges(initial, current)` plus 1 each if the name or description changed;
    - on the right, "Cancel" and "Save changes" ("Create role" for a new role);
    - "Save changes" is disabled while processing, or when an existing role has no changes.
- **Duplicating** copies the description too.

Per the project's UI rule, explanations of controls go in tooltips, not notes under fields. The descriptions under permissions are content, not hints, so they stay.

**Verify** (root): `npm run check`, `npm run types:check`, `npm run test` → pass. Then run the app and check:

- the role list shows four built-ins with badges, descriptions and counts;
- the compare table opens and closes groups, and "Expand all" works;
- the dialog's group checkbox shows the indeterminate state when part of a group is ticked;
- the change count goes up and back to "No changes";
- an Admin (not an owner) sees disabled checkboxes only for permissions their role lacks (none, by default; take one away from Admin as the owner to see it).

### Step 8: Tests

In `MembersAndRolesTest.php` add:

1. **"a new workspace's built-in roles have keys and descriptions"**: after `createOwner()`, the workspace's roles have keys `owner`, `admin`, `editor` and `viewer`, each with a non-empty description.
2. **"built-in roles keep their name"**: the owner PUTs `roles.update` for Editor with `name: 'Writers'` and Editor's permissions → 422 on `name`. With `name: 'Editor'` and one permission removed → redirect, and the permission is gone.
3. **"built-in roles can't be deleted"**: deleting Viewer (no members) → 403, and the role still exists.
4. **"custom roles save a description"**: store a role with `description: 'Helps with imports'` → saved. Then update it with a new description → saved.
5. **"a built-in role's description can't be changed"**: PUT Editor with `name: 'Editor'` and `description: 'Changed'` → Editor's description is unchanged.
6. **"the roles page lists built-ins first and describes each permission"**: `get(route('marketing.roles.index'))` → Inertia props. `roles.0.key` is `owner`; a custom role created as "Aardvark" comes after `viewer`, not first. Every `permissions.*` has a non-empty `description`.
7. **"the roles page passes what the actor can grant"**: as an Admin with `campaigns.approve` removed from their role, `grantable` doesn't contain `campaigns.approve`. As the owner, `grantable` has all 15.

Pest unit check, in the same file or a new `tests/Unit/PermissionTest.php` (whichever folder exists): every `Permission` case has a non-empty `label()` and `description()`.

**Revert checks** (restore after each, and commit nothing from them):

- Remove the name check in `update()`: test 2 fails.
- Remove `abort_if($role->isBuiltIn(), 403)`: test 3 fails.

**Verify**: `vendor/bin/pest` → all pass (apart from the 7 SES failures); PHPStan → 0 errors; Pint → passes; (root) `npm run check`, `npm run types:check` and `npm run test` → pass.

## Done criteria

- [ ] `roles` has `key` and `description`; new workspaces' four roles are keyed and described
- [ ] Built-in roles can't be renamed (422) or deleted (403); their permissions (except Owner's) can change
- [ ] Every permission has a short label and a description, shown in the dialog and in the compare table
- [ ] The roles page has a role list (badge, description, member and permission counts) and a "Compare roles" table with expandable groups and "Expand all"
- [ ] The dialog has group checkboxes with an indeterminate state, "x of y" counts, a scrolling body, a change count and "Save changes"
- [ ] Permissions the actor lacks are disabled with a tooltip
- [ ] The API tokens dialog still works with the new picker
- [ ] The new PHP and Vitest tests pass, and the revert checks fail as described
- [ ] Pest, PHPStan, Pint, `npm run check`, `types:check` and `npm run test` pass
- [ ] `plans/README.md` row updated

## STOP conditions

- The drift check shows changes the excerpts don't cover, other than plan 055's.
- `defaultRoles()` turns out to have callers other than `CreateWorkspace::handle()`, such as a seeder or the host app.
- A permission description can't be made accurate without changing what a permission guards (that's a behaviour change, out of scope).
- Radix's checkbox in the installed version doesn't support `checked="indeterminate"`.

## Maintenance notes

- New permissions need a `label()`, a `description()` and a place in `PERMISSION_GROUPS`. The unit test catches a missing description, but not a missing group: a permission outside every group won't appear in the dialog or the compare table. Consider a Vitest case that compares the groups with a list of all permission values, if one ever goes missing.
- New built-in roles: add a key to `defaultRoles()`. Existing workspaces won't get it without a data migration, which will matter after release.
- After release, renaming a built-in's key or description would need a data migration; the texts live in each workspace's rows, not in code.
- Plan 056 (API log) is gated by `api_tokens.manage`. Update that permission's description when it lands (Step 2 says how).
