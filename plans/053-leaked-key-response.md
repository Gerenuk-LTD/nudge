# Plan 053: A leaked APP_KEY can be fully retired without breaking unsubscribe links

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in "STOP conditions" happens, stop and report. Don't
> improvise. When done, update this plan's row in `plans/README.md`, unless a
> reviewer dispatched you and told you they maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 6982215..HEAD -- DEPLOYING.md packages/core/src/Sending/TrackingUrls.php packages/core/config/marketing.php packages/core/tests/Feature/TrackingTest.php app/Console/Commands .env.example`
> If any of these changed, compare them with the "Current state" excerpts.
> On a mismatch, STOP. Plans 047 and 050 add unrelated lines to
> `.env.example` and `DEPLOYING.md`; that's expected.

## Status

- **Priority**: P2, **deferred until before release**. Until then, a leaked key means resetting the database and generating a new key. After release it matters a lot, and the tooling must exist before an incident.
- **Effort**: S–M
- **Risk**: LOW. Nothing changes until an operator runs the new command or sets the new variable.
- **Depends on**: none
- **Category**: security / docs
- **Planned at**: commit `6982215`, 2026-11-15

## Why this matters

`DEPLOYING.md`'s "Rotating APP_KEY" section is framed for a key that "may have leaked", but it tells operators to keep the old key in `APP_PREVIOUS_KEYS` for about a year, so unsubscribe links in sent emails keep working. While the old key is there:

- Laravel still accepts URLs signed with it (`vendor/laravel/framework/src/Illuminate/Routing/RoutingServiceProvider.php:81` passes previous keys to the URL generator). Email-verification links stay forgeable, so an attacker with the old key can verify any address.
- Provider credentials, webhook secrets and 2FA secrets stay encrypted under the old key, so a database leak plus the old key still exposes them.

So the guide doesn't contain a leak. This plan separates **routine rotation** (today's steps) from **leak response**:

1. A command re-encrypts everything stored under the old key.
2. Tracking and unsubscribe links get their own list of old keys, `MARKETING_PREVIOUS_LINK_KEYS`, checked only by core's tracking routes. The operator can drop the leaked key from `APP_PREVIOUS_KEYS`, and old unsubscribe links keep working. The cost: someone with the leaked key can still forge tracking links (open, click, unsubscribe or pause for a recipient) until the operator drops it from that list too. That's far less harmful, and the docs say so.

## Current state

**`DEPLOYING.md:116-122`**:

```markdown
## Rotating APP_KEY

Rotate the key if it may have leaked:

1. Copy the current `APP_KEY` value into `APP_PREVIOUS_KEYS`. Separate several old keys with commas.
2. Run `php artisan key:generate` to set a new `APP_KEY`.
3. Run `php artisan config:cache` and `php artisan queue:restart`.

New links are signed with the new key. Unsubscribe, click and open links in emails already sent keep working as long as the old key stays in `APP_PREVIOUS_KEYS`, and so do stored credentials. Keep it there for as long as old emails might still be acted on; a year is sensible for unsubscribe links. Signed-in users stay signed in.
```

**`packages/core/src/Sending/TrackingUrls.php`**:

```php
public function verify(string $payload, string $signature): bool
{
    foreach ([config('app.key'), ...config('app.previous_keys', [])] as $key) {
        if (is_string($key) && $key !== '' && hash_equals($this->sign($payload, $key), $signature)) {
            return true;
        }
    }

    return false;
}

/**
 * New links are always signed with the current key.
 */
private function sign(string $payload, ?string $key = null): string
{
    return substr(hash_hmac('sha256', $payload, 'marketing-tracking|'.($key ?? config('app.key'))), 0, 24);
}
```

**`config/app.php:102`** builds `previous_keys` from `APP_PREVIOUS_KEYS` with `array_filter(explode(',', (string) env('APP_PREVIOUS_KEYS', '')))`. Copy that shape.

**Encrypted data at rest:**

- `packages/core/src/Models/Provider.php:50-51`: casts `'credentials' => 'encrypted:array'` and `'webhook_secret' => 'encrypted'`. Laravel's encrypted casts use `Crypt::encryptString` (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php`, `castAttributeAsEncryptedString` near line 1471). `encrypted:array` stores `encryptString(json_encode($value))`.
- `App\Models\User`: `two_factor_secret` and `two_factor_recovery_codes`, written by Fortify with `Fortify::currentEncrypter()->encrypt(...)` (`vendor/laravel/fortify/src/Actions/EnableTwoFactorAuthentication.php:44`), i.e. `encrypt()` with serialization, **not** `encryptString`. Check how recovery codes are written in the same file before re-encrypting them.
- Laravel's encrypter decrypts with the current key or any key in `app.previous_keys`.
- Sessions and cookies are encrypted too. They don't need re-encrypting; users are signed out once the old key is dropped.

**Passkeys:** the passkey user handle is an HMAC with `fortify.passkeys.user_handle_secret`, which defaults to `env('PASSKEYS_USER_HANDLE_SECRET', config('app.key'))` (`config/fortify.php:148`). Login checks a passkey against the handle stored with it, so existing passkeys keep working after rotation (only new ones get a new handle). Don't change anything here; just mention it in the docs (Step 4).

**Commands exemplar:** `app/Console/Commands/SetPassword.php` uses attributes:

```php
#[Signature('app:set-password {email : The email address of the user} {--password= : The new password (prompted for if left out)}')]
#[Description("Manually set a user's password")]
class SetPassword extends Command
```

and `$this->components->info(...)` / `->error(...)` for output.

**Tests:** `packages/core/tests/Feature/TrackingTest.php:110` "links signed with a previous app key still work after rotation" is the pattern for Step 2. Host command tests: `tests/Feature/SetPasswordCommandTest.php` (`$this->artisan(...)->assertSuccessful()`).

## Commands you will need

From the repo root (Git Bash: `php.bat`).

| Purpose    | Command                                                      | Expected                                      |
| ---------- | ------------------------------------------------------------ | --------------------------------------------- |
| Host tests | `php artisan test --compact`                                 | all pass                                      |
| Core tests | `cd packages/core && vendor/bin/pest`                        | all pass, except the 7 Windows-only SES tests |
| PHPStan    | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` | 0 errors                                      |
| Pint       | `vendor/bin/pint --test`                                     | passes                                        |

## Scope

**In scope:**

- `packages/core/config/marketing.php` (one key)
- `packages/core/src/Sending/TrackingUrls.php` (`verify()` only)
- `packages/core/tests/Feature/TrackingTest.php`
- `app/Console/Commands/ReencryptSecrets.php` (new)
- `tests/Feature/ReencryptSecretsCommandTest.php` (new)
- `DEPLOYING.md` ("Rotating APP_KEY")
- `.env.example` (one commented line)

**Out of scope:**

- Laravel's URL signing and `app.previous_keys`. Nothing changes in how they work.
- A separate signing key for new tracking links. New links keep using `APP_KEY`.
- Rotating `SETUP_TOKEN`, API tokens or provider credentials themselves. If the database leaked too, providers' own keys should be rotated at the provider; the docs say so.

## Git workflow

- Branch off `dev`: `advisor/053-leaked-key-response`.
- Commits like `feat: re-encrypt secrets and keep old link keys separately after a key leak`, ending with the Co-Authored-By trailer you're given.
- Don't push.

## Steps

### Step 1: Old link keys of their own (core)

In `packages/core/config/marketing.php`, next to `tracking_url`, add:

```php
// Old APP_KEYs that still verify tracking and unsubscribe links in emails already sent, without being trusted for
// anything else. Used after a key leak; see DEPLOYING.md.
'previous_link_keys' => array_values(array_filter(explode(',', (string) env('MARKETING_PREVIOUS_LINK_KEYS', '')))),
```

In `TrackingUrls::verify()`, loop over `[config('app.key'), ...config('app.previous_keys', []), ...config('marketing.previous_link_keys', [])]`. Don't change `sign()`.

**Verify**: `cd packages/core && vendor/bin/pest tests/Feature/TrackingTest.php` → all pass.

### Step 2: Test it (core)

In `TrackingTest.php`, next to the test at line 110, add **"links signed with a key kept only for links still work"**. Copy line 110's setup, but put the old key in `marketing.previous_link_keys` instead of `app.previous_keys`, and leave `app.previous_keys` empty. The old link still works. Then, with neither list holding the old key, it's refused.

**Verify**: the new test passes. Revert check: without the `marketing.previous_link_keys` entry in `verify()`, it fails.

### Step 3: The re-encrypt command (host)

Create `app/Console/Commands/ReencryptSecrets.php`:

- `#[Signature('app:reencrypt-secrets')]`, `#[Description('Re-encrypt stored secrets with the current APP_KEY, so old keys can be removed')]`.
- **Providers**: for each `Provider::withoutGlobalScopes()->lazyById()`: read `$provider->credentials` and `$provider->webhook_secret` (the casts decrypt with the current or a previous key), then write them back with `DB::table('providers')->where('id', $provider->id)->update([...])`, using `Crypt::encryptString(json_encode($credentials))` for `credentials` (or `null` when null) and `Crypt::encryptString($secret)` for `webhook_secret` (or `null`). A plain `$provider->save()` doesn't work: Eloquent compares decrypted values and sees no change. Confirm the `encrypted:array` format against `HasAttributes` first (Step 3 check below).
- **Users**: for each user with `two_factor_secret` not null: `encrypt(decrypt($raw))` for `two_factor_secret`, and the same for `two_factor_recovery_codes`, matching how Fortify wrote them. Read the raw column values with `$user->getRawOriginal(...)`, and write with `DB::table('users')->...->update(...)`.
- Wrap each table in a transaction. If any value can't be decrypted (`DecryptException`), stop with an error naming the table and row id (never the value) and return `self::FAILURE`, before writing anything for that table.
- Print the counts: `Re-encrypted 3 providers and 12 users' two-factor settings.`

**Check before writing**: open `HasAttributes.php` around `castAttributeAsEncryptedString` and the `encrypted:array` branch, and `EnableTwoFactorAuthentication.php` plus `GenerateNewRecoveryCodes.php` in Fortify. If either uses a format other than the one described here, match what the vendor code does and say so in your report. If you can't tell, STOP.

**Verify**: `php artisan list app` shows `app:reencrypt-secrets`.

### Step 4: Docs

Rewrite "Rotating APP_KEY" in `DEPLOYING.md` as two subsections:

**Routine rotation** — the existing three steps and paragraph, unchanged except for the opening line ("To rotate the key on a schedule:").

**If the key may have leaked**:

1. Copy the current `APP_KEY` into **both** `APP_PREVIOUS_KEYS` and `MARKETING_PREVIOUS_LINK_KEYS`.
2. `php artisan key:generate`, then `php artisan config:cache`.
3. `php artisan app:reencrypt-secrets`. It re-encrypts provider credentials, webhook secrets and two-factor settings with the new key.
4. Remove the old key from `APP_PREVIOUS_KEYS` (keep it in `MARKETING_PREVIOUS_LINK_KEYS`), then `php artisan config:cache` and `php artisan queue:restart`.

Then explain, in two or three short sentences: everyone is signed out; unsubscribe, pause, open and click links in emails already sent keep working; someone holding the old key can still forge those links (unsubscribing or pausing a recipient), so remove it from `MARKETING_PREVIOUS_LINK_KEYS` once old emails no longer matter. If the database may have leaked too, rotate each provider's API keys at the provider and update them in Settings. Passkeys keep working. Setting `PASSKEYS_USER_HANDLE_SECRET` to its own value keeps passkey handles stable across rotations.

Add to `.env.example`, near `APP_PREVIOUS_KEYS` if it's there (otherwise after `APP_KEY`):

```
# After a key leak: old keys that only tracking and unsubscribe links still accept. See DEPLOYING.md.
# MARKETING_PREVIOUS_LINK_KEYS=
```

**Verify**: `grep -n "reencrypt-secrets\|MARKETING_PREVIOUS_LINK_KEYS" DEPLOYING.md .env.example` → matches in both.

### Step 5: Command tests (host)

Create `tests/Feature/ReencryptSecretsCommandTest.php`. Generate two keys with `'base64:'.base64_encode(random_bytes(32))`.

1. **Secrets survive dropping the old key.**
    - With `app.key` = old key: create a `Provider` with credentials and a webhook secret (copy the create call from `packages/core/tests/Feature/AutomationApprovalTest.php`'s `beforeEach`; set `workspace_id` explicitly or create a workspace first, since `Provider` is workspace-scoped). Give a user a 2FA secret through `encrypt()`.
    - Switch: `config(['app.key' => $new, 'app.previous_keys' => [$old]])`, then `app()->forgetInstance('encrypter')` and `Crypt::clearResolvedInstance('encrypter')` so the new key takes effect. Check that the provider still reads correctly.
    - Run the command → success.
    - Drop the old key: `config(['app.previous_keys' => []])` and forget the encrypter again. Re-read the provider (`Provider::withoutGlobalScopes()->find(...)`) and the user. Credentials, webhook secret and `decrypt($user->fresh()->two_factor_secret)` match the originals.
2. **Without the command**, the same final read throws `DecryptException`. This proves test 1 means something.
3. **A row that can't be decrypted fails cleanly**: put garbage in one provider's `credentials` with `DB::table`. The command fails, and its output contains the row id but not the garbage.

If switching keys mid-test doesn't take effect on the encrypter (test 2 doesn't throw), STOP and report; don't weaken the tests.

**Verify**: host tests, core tests, PHPStan and Pint pass.

## Done criteria

- [ ] `MARKETING_PREVIOUS_LINK_KEYS` keys verify tracking links and nothing else
- [ ] `app:reencrypt-secrets` re-encrypts provider credentials, webhook secrets and 2FA settings; tests 1–3 pass
- [ ] `DEPLOYING.md` separates routine rotation from leak response
- [ ] Revert check in Step 2 fails as described
- [ ] Host tests, core tests, PHPStan and Pint pass
- [ ] `plans/README.md` row updated

## STOP conditions

- The encrypted formats differ from what Step 3 describes and you can't tell what the vendor code does.
- Test 2 doesn't throw (the key switch isn't taking effect).
- Another model in core or the host uses an `encrypted` cast (`grep -rn "'encrypted" app packages/core/src`). Then the command must cover it too; report it instead of guessing.

## Maintenance notes

- **Any new `encrypted` cast** must be added to `app:reencrypt-secrets`, or a leak response will leave it on the old key. Reviewers: check new migrations and casts for this.
- `MARKETING_PREVIOUS_LINK_KEYS` is checked on every tracking request; keep the list short.
- The command is safe to run more than once.
