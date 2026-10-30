# Plan 007: The API can no longer silently resubscribe people who unsubscribed

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat f7bb714..HEAD -- packages/core/src/Actions/Contacts/UpsertContact.php packages/core/src/Http/Controllers/Api/ContactController.php packages/core/tests/Feature/ApiTest.php`
> Expected: no output. Otherwise compare the excerpts below with the live code; on a mismatch, STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: MED (changes API behaviour for integrators that resubscribe on purpose; they get an explicit opt-in flag)
- **Depends on**: none
- **Category**: bug (compliance)
- **Planned at**: commit `f7bb714`, 2026-10-30

## Why this matters

When a recipient unsubscribes, through the unsubscribe link or a provider's list-unsubscribe, the platform sets the contact's `status` to `unsubscribed`. It does not add a suppression row, so that status is the only thing stopping the next send.

The public API's upsert (`PUT /api/v1/contacts/{email}`) applies whatever `status` the caller sends. A customer's CRM sync that sends `status: subscribed` every night therefore re-subscribes everyone who opted out since the last sync, and they get the next campaign. That is a CAN-SPAM, GDPR and PECR problem, and it damages deliverability.

After this plan, an API upsert cannot move an existing unsubscribed contact back to subscribed unless the caller explicitly passes `"resubscribe": true`.

The other paths are already safe:

- **Imports** only ever unsubscribe (`packages/core/src/Imports/ColumnMapping.php:72`, "Imports only ever unsubscribe: a status column cannot re-subscribe someone who opted out").
- **The dashboard's "Add contact"** rejects existing emails (unique rule in `src/Http/Requests/ContactRequest.php`).
- **The dashboard edit form** requires `status` and is an explicit staff action.

## Current state

All paths below are relative to `packages/core/`.

`src/Actions/Contacts/UpsertContact.php`:

```php
public function handle(ContactData $data, ContactSource $source): array
{
    try {
        return $this->upsert($data, $source);
    } catch (UniqueConstraintViolationException) {
        // Another request created the same email between our read and insert; it now exists, so update it.
        return $this->upsert($data, $source);
    }
}

private function upsert(ContactData $data, ContactSource $source): array
{
    return DB::transaction(function () use ($data, $source): array {
        $contact = Contact::query()->forEmail($data->email)->lockForUpdate()->first();
        $created = $contact === null;
        ...
        if ($data->status !== null) {
            (new ChangeContactStatus)->apply($contact, $data->status);   // lines 67-68
        }

        $contact->save();
        ...
```

Callers of `UpsertContact::handle` are:

- `src/Http/Controllers/Api/ContactController.php:57` (`ContactSource::Api`);
- `src/Http/Controllers/ContactController.php:74` (`ContactSource::Manual`, new contacts only);
- `src/Jobs/ProcessImport.php` (`ContactSource::Import`, only ever sends `unsubscribed`);
- `src/Automations/StepRunner.php` and possibly others. Run `grep -rn "UpsertContact" src` to list every caller before changing the signature.

`src/Http/Controllers/Api/ContactController.php:52-62`:

```php
public function upsert(Request $request, string $email, ContactRules $contactRules, UpsertContact $upsertContact): JsonResponse
{
    $data = ['email' => Contact::normalizeEmail($email), ...$request->except('email')];
    $validated = Validator::make($data, $contactRules->rules())->validate();

    ['contact' => $contact, 'created' => $created] = $upsertContact->handle(ContactData::fromValidated($validated), ContactSource::Api);

    return (new ContactResource($contact->load(['tags:id,name', 'lists:id'])))
        ->response()
        ->setStatusCode($created ? 201 : 200);
}
```

`ContactRules::rules()` is shared with the web form, so don't add the API-only rule there. `ContactData::fromValidated()` ignores keys it doesn't know about.

`ContactStatus` cases are `Subscribed` (`'subscribed'`) and `Unsubscribed` (`'unsubscribed'`).

**Tests.** Pest in `tests/Feature/ApiTest.php`. Its `beforeEach` creates `$this->owner`/`$this->workspace`, and `apiToken($workspace, $user, $permissions = ['contacts.view', 'contacts.manage'])` returns a plain token. Use `Contact::factory()->unsubscribed()->create([...])` (the state exists; it's used in `tests/Feature/SendingTest.php`). Pattern:

```php
$this->withToken($token)->putJson(route('marketing.api.contacts.upsert', 'jane@example.com'), [...])->assertOk();
```

## Commands you will need

Run these from the repo root.

| Purpose    | Command                                                                   | Expected                  |
| ---------- | ------------------------------------------------------------------------- | ------------------------- |
| API tests  | `cd packages/core && vendor/bin/pest --compact tests/Feature/ApiTest.php` | all pass                  |
| Core suite | `cd packages/core && vendor/bin/pest --compact`                           | all pass                  |
| PHPStan    | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`              | 0 errors in touched files |
| Pint       | `vendor/bin/pint --dirty --format agent`, then `vendor/bin/pint --test`   | exit 0                    |

## Scope

**In scope:**

- `src/Actions/Contacts/UpsertContact.php`
- `src/Http/Controllers/Api/ContactController.php` (`upsert` only)
- `tests/Feature/ApiTest.php` (add tests)

**Out of scope:**

- `ContactRules.php`, `ContactData.php`, `ColumnMapping.php` and the web `ContactController`.
- Adding suppression rows for unsubscribes. That's a separate design question.
- Recording a timeline event for API resubscribes. This is deferred.

## Git workflow

- Branch: `advisor/007-no-silent-resubscribe`.
- Commit: `fix: stop api upserts from silently resubscribing people who unsubscribed`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Guard resubscription in `UpsertContact`

Add a third parameter, `bool $allowResubscribe = false`, to `handle()` and to the private `upsert()`, and pass it through, including on the retry. Update the `handle()` PHPDoc with this sentence: "An existing contact who unsubscribed stays unsubscribed unless `$allowResubscribe` is true, so an integration cannot quietly opt people back in."

Replace the status block with:

```php
$resubscribing = ! $created
    && $contact->status === ContactStatus::Unsubscribed
    && $data->status === ContactStatus::Subscribed;

if ($data->status !== null && (! $resubscribing || $allowResubscribe)) {
    (new ChangeContactStatus)->apply($contact, $data->status);
}
```

Import `ContactStatus` if it isn't already imported. Every existing caller keeps working: new contacts and unsubscribe requests are unaffected.

**Verify**: `cd packages/core && vendor/bin/pest --compact` → all pass. If a test fails because it relies on an upsert resubscribing someone, STOP.

### Step 2: Let API callers opt in explicitly

In `Api\ContactController::upsert`, validate with `[...$contactRules->rules(), 'resubscribe' => ['sometimes', 'boolean']]`, and call:

```php
$upsertContact->handle(ContactData::fromValidated($validated), ContactSource::Api, (bool) ($validated['resubscribe'] ?? false));
```

Update the method's PHPDoc: "Create or update a contact. Custom fields are merged; tags and lists are added, never removed. Someone who unsubscribed is only subscribed again when `resubscribe` is true."

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/ApiTest.php` → all pass

## Test plan

Add these to `tests/Feature/ApiTest.php`:

1. `test('an api upsert cannot quietly resubscribe someone who unsubscribed')`. Create an unsubscribed contact `ann@example.com`, then PUT `{'status': 'subscribed', 'first_name': 'Ann'}`. Expect `assertOk()`, `data.status` = `unsubscribed`, `data.first_name` = `Ann` (other fields still update), and `unsubscribed_at` still set on the model.
2. `test('an api upsert resubscribes when asked to explicitly')`. Same setup, with `{'status': 'subscribed', 'resubscribe': true}`. Expect `data.status` = `subscribed`.
3. `test('an api upsert can still unsubscribe and create subscribed contacts')`. PUT a new email with `status: subscribed` and expect 201 and `subscribed`. Then PUT the same email with `status: unsubscribed` and expect `unsubscribed`.

**Revert check**: temporarily make Step 1's condition always apply the status, confirm test 1 fails, then restore. Report both results.

## Done criteria

- [ ] `grep -n "allowResubscribe" packages/core/src/Actions/Contacts/UpsertContact.php` returns at least 3 matches.
- [ ] `grep -n "'resubscribe'" packages/core/src/Http/Controllers/Api/ContactController.php` returns a match.
- [ ] The core suite passes, including 3 new tests.
- [ ] PHPStan shows no errors in the touched files, and Pint is clean.
- [ ] Only in-scope files are changed.

## STOP conditions

- The drift check shows changes that don't match the excerpts.
- An existing test expects an upsert to resubscribe a contact.
- A caller of `UpsertContact::handle` outside the list above sends `status: subscribed` for existing contacts on purpose. Report it rather than passing `true` for it.

## Maintenance notes

- Integrators who relied on resubscribing must now send `"resubscribe": true`. Mention this in release notes or API docs.
- Any new caller of `UpsertContact` gets the safe default (no resubscribe). Reviewers should question any caller that passes `true`.
- Follow-ups:
    - write a timeline event when the API resubscribes someone;
    - consider recording recipient unsubscribes as suppressions, so a status change alone can't undo them.
