# Plan 005: Provider webhooks are rejected unless authenticated, and SES only accepts its own SNS topic

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 08eff55..HEAD -- packages/core/src/Sending packages/core/src/Actions/Providers/SaveProvider.php packages/core/src/Models/Provider.php packages/core/database/migrations packages/core/tests/Feature/DeliveryEventsTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition. (Plan 004 also edits
> `WebhookParser.php` and `DeliveryEventsTest.php`. If 004 landed first,
> work around its changes; that alone is not a STOP.)

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW (existing SendGrid providers without a key will start getting 403s until a key is added; that is intended)
- **Depends on**: plans/001-make-ci-a-real-gate.md
- **Category**: security
- **Planned at**: commit `08eff55`, 2026-10-29

## Why this matters

Provider webhooks can suppress addresses, unsubscribe contacts and automatically pause a workspace's sending, so forged webhooks are a real attack.

The only things protecting a webhook are a random token in its URL plus each provider's own signature. Two gaps leave just the URL token:

- **SendGrid with no saved key.** The signing secret is optional when a provider is saved, and the vendored SendGrid parser skips signature checks entirely when the secret is empty. The other signing-secret providers throw on an empty secret, but they surface as a confusing "Malformed payload" 400 and an error report.
- **SES via SNS.** The SNS signature is checked against Amazon's certificate, but any SNS topic in any AWS account produces validly signed messages. The topic is never compared to the one the customer connected.

Anyone who learns a webhook URL (logs, a proxy, a shared screenshot) can currently forge bounces and complaints for these providers. There are also no tests that each provider rejects bad credentials.

## Current state

**Project shape.** The product is `packages/core` (namespace `Gerenuk\Marketing`). The webhook route is `POST webhooks/providers/{token}`, named `marketing.webhooks.provider` (`packages/core/routes/public.php`).

`packages/core/src/Http/Controllers/Public/WebhookController.php:19-43`:

- finds the provider by token (404 if none);
- calls `$parser->parse($provider, $request)`;
- turns a `RejectWebhookException` into a response with that exception's status code;
- turns any other `Throwable` into a 400 "Malformed payload." after calling `report()`;
- otherwise dispatches `ProcessDeliveryEvents` and returns 204.

`packages/core/src/Sending/WebhookVerification.php`, enum cases: `None`, `SigningSecret`, `BasicAuth`, `Sns`.

`packages/core/src/Sending/ProviderType.php:148-155`:

```php
self::Smtp, self::Scaleway => WebhookVerification::None,
self::AmazonSes => WebhookVerification::Sns,
self::Postmark, self::Brevo, self::Mailjet => WebhookVerification::BasicAuth,
self::Mailgun, self::SendGrid, self::Resend, self::MailerSend, self::Mandrill => WebhookVerification::SigningSecret,
```

`packages/core/src/Sending/Webhooks/WebhookParser.php:29-39`:

```php
public function parse(Provider $provider, Request $request): array
{
    if ($provider->type->webhookVerification() === WebhookVerification::Sns) {
        return $this->sns->parse($request);
    }

    $parser = $provider->type->requestParser()
        ?? throw new RejectWebhookException(404, 'This provider does not send webhooks.');

    $result = $parser->parse($request, (string) $provider->webhook_secret);
```

In `vendor/symfony/sendgrid-mailer/Webhook/SendgridRequestParser.php:51`, signature validation runs only `if ($secret)`.

`packages/core/src/Actions/Providers/SaveProvider.php:27-32`:

```php
$webhookSecret = match ($type->webhookVerification()) {
    // We generate the basic auth credentials the provider will send back.
    WebhookVerification::BasicAuth => $provider->webhook_secret ?? 'gmk:'.Str::random(32),
    WebhookVerification::SigningSecret => $data->webhookSecret ?? $provider?->webhook_secret,
    default => null,
};
```

`packages/core/src/Sending/Webhooks/SnsParser.php`:

- `parse(Request $request): array` (line ~26) decodes the message and calls `$this->verify($message)` to check the signature.
- It then handles `SubscriptionConfirmation` (fetching `SubscribeURL`) and `Notification`.
- `TopicArn` is part of the signed string (`stringToSign`, line ~143) but is never compared to anything.
- Its constructor injects an HTTP client factory as `$this->http`.

`packages/core/src/Models/Provider.php`:

- `#[Fillable([... 'webhook_secret', 'webhook_token', ... 'last_webhook_at'])]` and `#[Hidden(['credentials', 'webhook_secret', 'webhook_token'])]`.
- Casts include `'webhook_secret' => 'encrypted'`.
- Its property PHPDoc block lists each column.

**Migrations** live in `packages/core/database/migrations/`, named `YYYY_MM_DD_NNNNNN_description.php`. The latest is `2026_11_01_000002_add_ab_editor_state_b_to_campaigns_table.php`; use it as the exemplar for an "add column" migration (anonymous class, `up` and `down`).

**Tests.** `packages/core/tests/Feature/DeliveryEventsTest.php` has a `describe('Amazon SES through SNS', ...)` block with an `snsMessage(array $fields, $key, string $certUrl)` helper. That helper currently puts `...$fields` **first** and then hard-codes `'TopicArn' => 'arn:aws:sns:eu-west-1:1:ses'`, so a test can't override the topic. It also has a Mailgun `describe` block with a `mailgunEvent(...)` helper, and a `sentCampaignThrough(...)` helper. Webhook requests from tests come from `127.0.0.1`, which fails the Brevo and Postmark parsers' provider-IP allowlists; that is fine for rejection tests.

## Commands you will need

| Purpose                           | Command                                                                              | Expected on success |
| --------------------------------- | ------------------------------------------------------------------------------------ | ------------------- |
| Delivery tests                    | `cd packages/core && vendor/bin/pest --compact tests/Feature/DeliveryEventsTest.php` | all pass            |
| Provider tests                    | `cd packages/core && vendor/bin/pest --compact tests/Feature/ProviderTest.php`       | all pass            |
| Core suite                        | `cd packages/core && vendor/bin/pest --compact`                                      | all pass            |
| Host tests (migrations still run) | `php artisan test --compact`                                                         | all pass            |
| PHPStan                           | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`                         | `[OK] No errors`    |
| Pint                              | `vendor/bin/pint --dirty --format agent`                                             | exit 0              |

## Scope

**In scope**:

- `packages/core/src/Sending/Webhooks/WebhookParser.php`
- `packages/core/src/Sending/Webhooks/SnsParser.php`
- `packages/core/src/Actions/Providers/SaveProvider.php`
- `packages/core/src/Models/Provider.php`
- `packages/core/database/migrations/2026_11_02_000001_add_webhook_topic_arn_to_providers_table.php` (create)
- `packages/core/tests/Feature/DeliveryEventsTest.php`

**Out of scope** (do NOT touch):

- `vendor/`.
- `ProviderRequest.php` validation. The secret stays optional at save time, because users often create the provider before the provider dashboard gives them a key. Webhooks are refused until a key is saved.
- Frontend provider pages. A UI warning for "webhooks disabled until you add a key" is a good follow-up but out of scope here.
- The URL token mechanism and the route throttle.

## Git workflow

- Branch: `advisor/005-webhook-auth` from `dev`.
- Commit message example: `fix(security): refuse unsigned provider webhooks and pin the SES SNS topic`
- End commit messages with: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- Do NOT push or open a PR unless instructed.

## Steps

### Step 1: Refuse signing-secret webhooks until a key is saved

In `WebhookParser::parse`, after obtaining `$parser` and before calling it, add:

```php
if ($provider->type->webhookVerification() === WebhookVerification::SigningSecret && blank($provider->webhook_secret)) {
    throw new RejectWebhookException(403, 'Add the webhook signing key to this provider to accept its webhooks.');
}
```

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/DeliveryEventsTest.php` → all pass

### Step 2: Add a `webhook_topic_arn` column

Create `packages/core/database/migrations/2026_11_02_000001_add_webhook_topic_arn_to_providers_table.php`, adding nullable `string('webhook_topic_arn')` after `webhook_token` (use `->after()` only if the exemplar migration does; SQLite ignores it). Its `down()` drops the column.

In `Provider`:

- add `'webhook_topic_arn'` to `#[Fillable]` and to `#[Hidden]`;
- add `@property string|null $webhook_topic_arn` to the class PHPDoc.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/ProviderTest.php` → all pass

### Step 3: Pin the SNS topic after signature verification

Change `SnsParser::parse(Request $request)` to `parse(Request $request, Provider $provider)` and update the one caller in `WebhookParser`. Right after `$this->verify($message);`, call a new private method:

```php
/**
 * Only the SNS topic the provider first confirmed may send events. Any AWS account can sign messages
 * from its own topic, so a valid signature alone does not prove they come from this customer.
 *
 * @param  array<string, mixed>  $message
 */
private function ensureTopic(Provider $provider, array $message): void
{
    $topic = (string) ($message['TopicArn'] ?? '');

    if ($topic === '') {
        throw new RejectWebhookException(406, 'The SNS message has no topic.');
    }

    if ($provider->webhook_topic_arn === null) {
        $provider->forceFill(['webhook_topic_arn' => $topic])->saveQuietly();

        return;
    }

    if (! hash_equals($provider->webhook_topic_arn, $topic)) {
        throw new RejectWebhookException(403, 'This SNS topic is not the one connected to this provider.');
    }
}
```

This pins on first use, and the pin happens only **after** the signature check, so an unsigned message can never set it.

In `SaveProvider::handle`, saving an SES provider clears the pin, so a customer who switches topics can reconnect by re-saving. In the `fill([...])` array, add `'webhook_topic_arn' => null` only when `$type->webhookVerification() === WebhookVerification::Sns`. For example, build the fill array and conditionally add the key; match the file's style.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/DeliveryEventsTest.php` → all pass. The existing SNS tests must still pass: their first message pins the topic.

### Step 4: Tests

Add the tests below.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/DeliveryEventsTest.php` → all pass, including the new ones

## Test plan

In `packages/core/tests/Feature/DeliveryEventsTest.php`:

1. A dataset test, `test('a signing-secret provider without a key refuses webhooks')`, over `[ProviderType::Mailgun, ProviderType::SendGrid, ProviderType::Resend, ProviderType::MailerSend, ProviderType::Mandrill]`. For each:
    - create a provider with `webhook_secret` null, and credentials built from `$type->fields()` (fill each required field key with `'x'`);
    - `Queue::fake([ProcessDeliveryEvents::class])`;
    - POST `['any' => 'payload']` as JSON to its webhook URL.

    Expect status 403 and `Queue::assertNotPushed(ProcessDeliveryEvents::class)`.

2. A dataset test, `test('every webhook provider rejects requests with the wrong credentials')`, over every `ProviderType` whose `webhookVerification()` is `SigningSecret` or `BasicAuth`. Create the provider with `webhook_secret` set to a wrong value (`'wrong'` for signing secrets, `'gmk:wrong'` for basic auth). POST a JSON body with no signature headers and an `Authorization: Basic ` header for a _different_ secret. Expect `$response->status()` between 400 and 499, and `ProcessDeliveryEvents` not pushed. (The exact code varies: 403 for bad basic auth, 406 for bad signatures or IP mismatch, 400 for parser exceptions. Assert the range, not the code.)
3. Edit the `snsMessage` helper so a test can override `TopicArn`: put `'TopicArn' => 'arn:aws:sns:eu-west-1:1:ses'` **before** `...$fields` in the array literal. Leave the other fixed keys after. Then add, inside the SNS `describe`:
    - `test('the first signed message pins the SNS topic')`: post a valid Notification. Expect 204 and `$this->provider->fresh()->webhook_topic_arn === 'arn:aws:sns:eu-west-1:1:ses'`.
    - `test('messages from another SNS topic are refused')`: set `webhook_topic_arn` on the provider to the default topic, then post a validly signed Notification with `'TopicArn' => 'arn:aws:sns:eu-west-1:999:attacker'`. Expect 403, and no suppression or recipient change.
    - `test('saving an SES provider clears the pinned topic')`: set a pin, then save through HTTP as in `packages/core/tests/Feature/ProviderTest.php:55-58`: `$this->actingAs($this->owner)->put(route('marketing.providers.update', $this->provider), ['name' => 'SES', 'max_per_second' => 14, 'credentials' => ['access_key_id' => '', 'secret_access_key' => '', 'region' => 'eu-west-1', 'configuration_set' => 'marketing']])->assertSessionHasNoErrors();`. Blank secret fields mean "keep". Expect `webhook_topic_arn` null.

## Done criteria

- [ ] `grep -n "Add the webhook signing key" packages/core/src/Sending/Webhooks/WebhookParser.php` → match
- [ ] `test -f packages/core/database/migrations/2026_11_02_000001_add_webhook_topic_arn_to_providers_table.php`
- [ ] `grep -n "webhook_topic_arn" packages/core/src/Models/Provider.php` → matches in Fillable, Hidden and the PHPDoc
- [ ] `cd packages/core && vendor/bin/pest --compact` passes, including the 2 dataset tests and 3 SNS tests
- [ ] `php artisan test --compact` passes (host migrations still run)
- [ ] PHPStan clean; `vendor/bin/pint --test` exits 0
- [ ] `git status` shows only in-scope files changed

## STOP conditions

- `SnsParser::parse` has another caller besides `WebhookParser` (`grep -rn "sns->parse\|SnsParser" packages/core/src`). Report it instead of changing that caller's behaviour.
- An existing SNS test fails because of pinning (for example it posts two messages with different topics). Report it; don't loosen the pin.
- The provider update request in the last SNS test fails validation for a reason unrelated to this plan. Drop that one test and note it in your report.
- The 403 for a missing key breaks an existing test that posts to a signing-secret provider without a secret. Report which test.

## Maintenance notes

- Pinning on first use has a window. If an attacker who already knows the URL sends a validly signed message from their own topic before the customer's real subscription confirms, the attacker's topic gets pinned. The customer would then see 403s and can re-save the provider to reset the pin. A stronger version lets the customer paste their topic ARN; that's deferred because it adds a form field.
- Follow-up: on the provider page, show "Webhooks are refused until you add a signing key" when `webhook_verification === 'signing_secret'` and `has_webhook_secret` is false (`packages/core/src/Http/Controllers/ProviderController.php` already exposes `has_webhook_secret`).
- When a new provider type is added, add it to the dataset in test 2. Reviewers should check that no provider can accept a webhook without some credential check.
