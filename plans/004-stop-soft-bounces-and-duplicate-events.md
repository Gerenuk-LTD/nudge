# Plan 004: Soft bounces no longer suppress contacts, and duplicate webhooks no longer inflate auto-pause

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 08eff55..HEAD -- packages/core/src/Sending/Webhooks packages/core/src/Actions/Campaigns/RecordDeliveryEvent.php packages/core/tests/Feature/DeliveryEventsTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition. (Plan 005 also edits
> `WebhookParser.php` and `DeliveryEventsTest.php`; if 005 landed first,
> merge around its changes. That is not a STOP.)

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW
- **Depends on**: plans/001-make-ci-a-real-gate.md
- **Category**: bug
- **Planned at**: commit `08eff55`, 2026-10-29

## Why this matters

The platform treats every sending provider as an equal: delivery webhooks for each go through Symfony's mailer bridges. Several of those bridges report **soft** (temporary) bounces, such as a full mailbox or greylisting, under the same name as hard bounces. The platform turns every `bounce` into a permanent suppression, so a valid subscriber with a full inbox is never emailed again. Those soft bounces also count toward the 7-day bounce rate that can automatically pause the whole workspace.

A second problem: providers deliver webhooks at least once and retry. Every delivery writes another bounce or complaint row to the event log, even though the recipient and campaign counters correctly count once. Auto-pause counts those raw rows. With the defaults, a single complaint repeated a few times can cross the 0.3% complaint threshold and pause sending.

## Current state

**Project shape.** The product is `packages/core` (namespace `Gerenuk\Marketing`). Provider types are defined in `packages/core/src/Sending/ProviderType.php`. Mailgun, Postmark, SendGrid, Brevo, Mailjet, Resend, MailerSend and Mandrill have Symfony webhook parsers; SES uses a custom SNS parser; SMTP and Scaleway have none.

`packages/core/src/Sending/Webhooks/WebhookParser.php:29-71`:

```php
public function parse(Provider $provider, Request $request): array
{
    if ($provider->type->webhookVerification() === WebhookVerification::Sns) {
        return $this->sns->parse($request);
    }

    $parser = $provider->type->requestParser()
        ?? throw new RejectWebhookException(404, 'This provider does not send webhooks.');

    $result = $parser->parse($request, (string) $provider->webhook_secret);
    $events = is_array($result) ? $result : ($result === null ? [] : [$result]);

    $normalized = [];

    foreach ($events as $event) {
        if ($event instanceof AbstractMailerEvent && ($type = self::typeFor($event)) !== null) {
            $normalized[] = new DeliveryEvent(/* type: $type, email, providerMessageId, metadata, occurredAt, reason */);
        }
    }

    return $normalized;
}

private static function typeFor(RemoteEvent $event): ?EventType
{
    return match ($event->getName()) {
        MailerDeliveryEvent::DELIVERED => EventType::Delivered,
        MailerDeliveryEvent::DEFERRED => EventType::Deferred,
        MailerDeliveryEvent::BOUNCE => EventType::Bounced,
        MailerDeliveryEvent::DROPPED => EventType::Dropped,
        MailerEngagementEvent::SPAM => EventType::Complained,
        MailerEngagementEvent::UNSUBSCRIBE => EventType::Unsubscribed,
        // Opens and clicks are tracked by our own pixel and redirects, so provider copies would double count.
        default => null,
    };
}
```

`$event->getPayload()` returns the provider's raw payload array.

**What the vendored converters do with soft bounces** (read at planning time from `vendor/symfony/*-mailer/RemoteEvent/*PayloadConverter.php`):

| Provider (`ProviderType` case) | Soft bounce arrives as `BOUNCE` when…                                   | How to detect it in the raw payload                                                        |
| ------------------------------ | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Brevo (`Brevo`)                | `event` is `soft_bounce` (`BrevoPayloadConverter.php:29`)               | `$payload['event'] === 'soft_bounce'`                                                      |
| Mandrill (`Mandrill`)          | `event` is `soft_bounce` (`MailchimpPayloadConverter.php:28`)           | `$payload['event'] === 'soft_bounce'`                                                      |
| MailerSend (`MailerSend`)      | `type` is `activity.soft_bounced` (`MailerSendPayloadConverter.php:31`) | `$payload['type'] === 'activity.soft_bounced'`                                             |
| Postmark (`Postmark`)          | every `RecordType: Bounce` (`PostmarkPayloadConverter.php:27`)          | soft unless `$payload['Type']` is `HardBounce`, `BadEmailAddress` or `ManuallyDeactivated` |
| SendGrid (`SendGrid`)          | `event: bounce` covers both bounce and block                            | soft when `$payload['type'] === 'blocked'`                                                 |
| Mailjet (`Mailjet`)            | `event: bounce`                                                         | soft when `($payload['hard_bounce'] ?? true) === false`                                    |
| Resend (`Resend`)              | `email.bounced`                                                         | soft when `$payload['data']['bounce']['type']` is set and is not `Permanent`               |
| Mailgun (`Mailgun`)            | already maps `severity: temporary` to `DEFERRED`                        | nothing to do                                                                              |
| SES (`AmazonSes`)              | `SnsParser` already maps non-`Permanent` to `Deferred`                  | nothing to do                                                                              |

`packages/core/src/Actions/Campaigns/RecordDeliveryEvent.php:23-63` runs one transaction per event. It has a `switch` on `$event->type` that calls `bounced()` / `complained()` / `unsubscribed()` / a `Delivered` branch / a `Dropped` branch, and then **always** creates an event row:

```php
ActivityEvent::create([
    'contact_id' => $recipient->contact_id ?? Contact::query()->forEmail($email)->value('id'),
    'campaign_id' => $recipient?->campaign_id,
    'campaign_recipient_id' => $recipient?->id,
    'type' => $event->type,
    'data' => array_filter(['reason' => $event->reason, 'provider_message_id' => $event->providerMessageId]),
    'occurred_at' => $event->occurredAt,
]);
```

The private `markFirst(...)` (around lines 103-120) already does a compare-and-set (`whereNull($column)->update(...) === 1`) and returns early when nothing changed, but it returns `void`. `bounced()`, `complained()` and `unsubscribed()` call it only when `$recipient` is not null.

`packages/core/src/Sending/AutoPause.php:21-26` counts `events` rows of type `Sent`, `Bounced` and `Complained` from the last 7 days.

**Test conventions.** `packages/core/tests/Feature/DeliveryEventsTest.php` uses Pest `describe('Mailgun', ...)` blocks, a `sentCampaignThrough($provider, $test, $contacts)` helper and a `mailgunEvent($secret, $event, $email, $recipientId, $extra)` helper. It posts to `route('marketing.webhooks.provider', $provider->webhook_token)`. Brevo and Postmark parsers also enforce the provider's source-IP allowlist, so classification is tested **directly** rather than over HTTP (see Test plan).

## Commands you will need

| Purpose        | Command                                                                              | Expected on success |
| -------------- | ------------------------------------------------------------------------------------ | ------------------- |
| Delivery tests | `cd packages/core && vendor/bin/pest --compact tests/Feature/DeliveryEventsTest.php` | all pass            |
| Core suite     | `cd packages/core && vendor/bin/pest --compact`                                      | all pass            |
| PHPStan        | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`                         | `[OK] No errors`    |
| Pint           | `vendor/bin/pint --dirty --format agent`                                             | exit 0              |

## Scope

**In scope**:

- `packages/core/src/Sending/Webhooks/BounceClassifier.php` (create)
- `packages/core/src/Sending/Webhooks/WebhookParser.php` (`parse` loop and `typeFor`)
- `packages/core/src/Actions/Campaigns/RecordDeliveryEvent.php`
- `packages/core/tests/Feature/DeliveryEventsTest.php` (add tests)

**Out of scope** (do NOT touch):

- `vendor/`: never patch Symfony converters.
- `SnsParser.php` and Mailgun handling: already correct.
- `AutoPause.php`: it stays a count of event rows; this plan makes those rows accurate.
- Back-filling or un-suppressing contacts already suppressed by past soft bounces. That's a data decision for the maintainer (see Maintenance notes).
- `Dropped` handling and counter drift for dropped events: a separate, smaller issue.

## Git workflow

- Branch: `advisor/004-soft-bounces` from `dev`.
- Commit message example: `fix: treat soft bounces as deferrals and record each delivery event once`
- End commit messages with: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- Do NOT push or open a PR unless instructed.

## Steps

### Step 1: Add `BounceClassifier`

Create `packages/core/src/Sending/Webhooks/BounceClassifier.php`:

```php
/**
 * Tells a temporary bounce from a permanent one. Several providers report both under one "bounce"
 * event, and only permanent bounces should suppress an address.
 */
class BounceClassifier
{
    /**
     * @param  array<string, mixed>  $payload  The provider's raw webhook payload for one event
     */
    public static function isTemporary(ProviderType $type, array $payload): bool
    {
        return match ($type) {
            ProviderType::Brevo, ProviderType::Mandrill => ($payload['event'] ?? null) === 'soft_bounce',
            ProviderType::MailerSend => ($payload['type'] ?? null) === 'activity.soft_bounced',
            ProviderType::Postmark => ! in_array($payload['Type'] ?? null, ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'], true),
            ProviderType::SendGrid => ($payload['type'] ?? null) === 'blocked',
            ProviderType::Mailjet => ($payload['hard_bounce'] ?? true) === false,
            ProviderType::Resend => isset($payload['data']['bounce']['type']) && $payload['data']['bounce']['type'] !== 'Permanent',
            default => false,
        };
    }
}
```

Use the exact `ProviderType` case names from `packages/core/src/Sending/ProviderType.php`. If a name differs (for example `Sendgrid` vs `SendGrid`), use the real one. If a provider in the table has no case at all, STOP.

**Verify**: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress packages/core/src/Sending/Webhooks` → no errors

### Step 2: Use it in `WebhookParser`

Change `typeFor` to take the provider type: `private static function typeFor(RemoteEvent $event, ProviderType $type): ?EventType`. Make the `BOUNCE` arm:

```php
MailerDeliveryEvent::BOUNCE => BounceClassifier::isTemporary($type, $event->getPayload()) ? EventType::Deferred : EventType::Bounced,
```

Pass `$provider->type` from `parse()`. Keep the existing comment about opens and clicks.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/DeliveryEventsTest.php` → all pass

### Step 3: Record each first-time event once

In `RecordDeliveryEvent`:

1. Change `markFirst` to return `bool`: `false` when the compare-and-set changed nothing, `true` otherwise. Update its PHPDoc.
2. Make `bounced()`, `complained()` and `unsubscribed()` return `bool`: the result of `markFirst` when there is a recipient, `true` when there isn't one (unmatched events are still logged).
3. In `handle()`, track `$firstTime = true;` and set it from those calls and from the `Delivered` branch (`$firstTime = $this->markFirst(...)`). Then skip the `ActivityEvent::create` when `! $firstTime`.

Suppression and unsubscribing stay as they are; both are already idempotent. `Deferred`, `Dropped` and other types always record (deferrals legitimately repeat).

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/DeliveryEventsTest.php` → all pass

### Step 4: Tests

Add the tests below to `DeliveryEventsTest.php`.

**Verify**: `cd packages/core && vendor/bin/pest --compact tests/Feature/DeliveryEventsTest.php` → all pass, including the new ones

## Test plan

In `packages/core/tests/Feature/DeliveryEventsTest.php`:

1. A dataset test, `test('temporary bounces are told apart from permanent ones', function (ProviderType $type, array $payload, bool $temporary) { expect(BounceClassifier::isTemporary($type, $payload))->toBe($temporary); })->with([...])`, with these rows:
    - Brevo `['event' => 'soft_bounce']` → true; Brevo `['event' => 'hard_bounce']` → false
    - Mandrill `['event' => 'soft_bounce']` → true; `hard_bounce` → false
    - MailerSend `['type' => 'activity.soft_bounced']` → true; `activity.hard_bounced` → false
    - Postmark `['Type' => 'SoftBounce']` → true; `['Type' => 'Transient']` → true; `['Type' => 'HardBounce']` → false
    - SendGrid `['type' => 'blocked']` → true; `['type' => 'bounce']` → false
    - Mailjet `['hard_bounce' => false]` → true; `['hard_bounce' => true]` → false
    - Resend `['data' => ['bounce' => ['type' => 'Transient']]]` → true; `Permanent` → false; `['data' => []]` → false
    - Mailgun `['event' => 'failed']` → false (default arm)
2. `test('a soft bounce is recorded as a deferral and does not suppress')`. Use Mailjet (no IP allowlist). Create a Mailjet provider with `Provider::create([...])`, reading the required credential keys from `ProviderType::Mailjet->fields()`; its `webhook_secret` is auto-generated only by `SaveProvider`, so set one explicitly, e.g. `'webhook_secret' => 'gmk:test'`. Send a campaign via `sentCampaignThrough`, then POST a Mailjet bounce payload with `hard_bounce => false` and header `Authorization: Basic base64('gmk:test')`. Read `vendor/symfony/mailjet-mailer/RemoteEvent/MailjetPayloadConverter.php` for the keys it requires (event, time, email, message id fields) and include them, plus the recipient-id metadata the other tests use if Mailjet supports it; otherwise match by message id. Expect: 204, `Suppression::count() === 0`, recipient status not `Bounced`, one `events` row of type `deferred`.
3. `test('a bounce reported twice is logged once')`. Use the Mailgun `describe` block and post the permanent-failure payload from its first test twice. Expect `ActivityEvent::where('type', EventType::Bounced)->count() === 1` and `bounced_count === 1`.
4. `test('repeated complaints cannot pause a workspace on their own')`. Set `config(['marketing.sending.auto_pause.minimum_sends' => 2])`, send to 4 contacts through Mailgun, and post the **same** complaint for one recipient 3 times. Expect `ActivityEvent::where('type', EventType::Complained)->count() === 1`. (Whether 1 of 4 pauses depends on the threshold; assert only the count here.)

## Done criteria

- [ ] `test -f packages/core/src/Sending/Webhooks/BounceClassifier.php`
- [ ] `grep -n "BounceClassifier::isTemporary" packages/core/src/Sending/Webhooks/WebhookParser.php` → match
- [ ] `cd packages/core && vendor/bin/pest --compact` passes, including the new dataset and 3 new tests
- [ ] PHPStan clean; `vendor/bin/pint --test` exits 0
- [ ] `git status` shows only in-scope files changed

## STOP conditions

- `$event->getPayload()` for one of these providers does not hold the raw payload (for example it's empty, or nested differently than the table says). Check by reading the converter in `vendor/symfony/<provider>-mailer/RemoteEvent/`; if it differs, report the actual shape instead of guessing.
- A `ProviderType` case for a provider in the table doesn't exist.
- An existing test expects a duplicate event row (for example it counts events after posting twice). Report it.
- Building a valid Mailjet test request needs more than about 30 minutes of payload reverse-engineering. Drop test 2, note it in your report, and keep the dataset test.

## Maintenance notes

- Contacts already wrongly suppressed by past soft bounces stay suppressed. A maintainer may want a one-off review of suppressions with reason `bounce` whose `detail` looks temporary (for example "mailbox full", 4xx codes). That is deliberately not automated.
- When a new provider bridge is added, add its soft-bounce rule to `BounceClassifier` and a dataset row. Reviewers should check this.
- The Resend and Postmark rules rely on provider payload fields documented by those providers, not on Symfony's converter; confidence is medium. If either provider changes its payload, these are the lines to revisit.
- Deferred: de-duplicating events that match no recipient (for example sends from outside the platform). Those are rare and are still logged each time.
