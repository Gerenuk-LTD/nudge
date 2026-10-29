<?php

use Gerenuk\Marketing\Actions\Campaigns\ScheduleCampaign;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Enums\SuppressionReason;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Sending\MessageHeaders;
use Gerenuk\Marketing\Sending\ProviderTransports;
use Gerenuk\Marketing\Sending\ProviderType;
use Gerenuk\Marketing\Sending\Webhooks\BounceClassifier;
use Gerenuk\Marketing\Tests\Fixtures\CapturingTransports;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
    $this->workspace->update(['postal_address' => '1 High Street']);
    $this->app->instance(ProviderTransports::class, new CapturingTransports);
});

function sentCampaignThrough(Provider $provider, $test, int $contacts = 1): Campaign
{
    Contact::factory()->count($contacts)->create();
    $campaign = Campaign::factory()->create(['provider_id' => $provider->id]);
    app(ScheduleCampaign::class)->handle($campaign, $test->workspace->fresh());
    $test->useWorkspace($test->workspace);

    return $campaign->fresh();
}

function mailgunEvent(string $secret, string $event, string $email, int $recipientId, array $extra = []): array
{
    $timestamp = (string) time();
    $token = bin2hex(random_bytes(16));

    return [
        'signature' => ['timestamp' => $timestamp, 'token' => $token, 'signature' => hash_hmac('sha256', $timestamp.$token, $secret)],
        'event-data' => [
            'id' => 'evt-'.uniqid(),
            'event' => $event,
            'timestamp' => time(),
            'recipient' => $email,
            'user-variables' => [MessageHeaders::RECIPIENT_KEY => (string) $recipientId],
            ...$extra,
        ],
    ];
}

describe('Mailgun', function () {
    beforeEach(function () {
        $this->provider = Provider::create([
            'name' => 'Mailgun', 'type' => ProviderType::Mailgun, 'max_per_second' => 100,
            'credentials' => ['api_key' => 'key', 'domain' => 'mg.test', 'region' => 'us'],
            'webhook_secret' => 'signing-key',
        ]);
    });

    test('a permanent failure suppresses the address and counts as a bounce', function () {
        $campaign = sentCampaignThrough($this->provider, $this);
        $recipient = CampaignRecipient::first();

        $this->postJson(route('marketing.webhooks.provider', $this->provider->webhook_token), mailgunEvent('signing-key', 'failed', $recipient->email, $recipient->id, [
            'severity' => 'permanent', 'reason' => 'bounce', 'delivery-status' => ['code' => 550, 'description' => 'No such mailbox', 'message' => ''],
        ]))->assertNoContent();

        $this->useWorkspace($this->workspace);

        expect(Suppression::first()->reason)->toBe(SuppressionReason::Bounce)
            ->and($recipient->fresh()->status)->toBe(RecipientStatus::Bounced)
            ->and($campaign->fresh()->bounced_count)->toBe(1)
            ->and($this->provider->fresh()->last_webhook_at)->not->toBeNull();
    });

    test('a delivery is counted once, however many times it is reported', function () {
        $campaign = sentCampaignThrough($this->provider, $this);
        $recipient = CampaignRecipient::first();

        foreach ([1, 2] as $attempt) {
            $this->postJson(route('marketing.webhooks.provider', $this->provider->webhook_token), mailgunEvent('signing-key', 'delivered', $recipient->email, $recipient->id))
                ->assertNoContent();
        }

        $this->useWorkspace($this->workspace);

        expect($campaign->fresh()->delivered_count)->toBe(1)
            ->and($recipient->fresh()->status)->toBe(RecipientStatus::Delivered);
    });

    test('a bounce reported twice is logged once', function () {
        $campaign = sentCampaignThrough($this->provider, $this);
        $recipient = CampaignRecipient::first();

        foreach ([1, 2] as $attempt) {
            $this->postJson(route('marketing.webhooks.provider', $this->provider->webhook_token), mailgunEvent('signing-key', 'failed', $recipient->email, $recipient->id, [
                'severity' => 'permanent', 'reason' => 'bounce', 'delivery-status' => ['code' => 550, 'description' => 'No such mailbox', 'message' => ''],
            ]))->assertNoContent();
        }

        $this->useWorkspace($this->workspace);

        expect(ActivityEvent::where('type', EventType::Bounced)->count())->toBe(1)
            ->and($campaign->fresh()->bounced_count)->toBe(1);
    });

    test('repeated complaints cannot pause a workspace on their own', function () {
        config(['marketing.sending.auto_pause.minimum_sends' => 2]);
        sentCampaignThrough($this->provider, $this, contacts: 4);
        $recipient = CampaignRecipient::first();

        foreach ([1, 2, 3] as $attempt) {
            $this->postJson(route('marketing.webhooks.provider', $this->provider->webhook_token), mailgunEvent('signing-key', 'complained', $recipient->email, $recipient->id))
                ->assertNoContent();
        }

        $this->useWorkspace($this->workspace);

        expect(ActivityEvent::where('type', EventType::Complained)->count())->toBe(1);
    });

    test('a complaint unsubscribes and suppresses the contact', function () {
        sentCampaignThrough($this->provider, $this);
        $recipient = CampaignRecipient::first();

        $this->postJson(route('marketing.webhooks.provider', $this->provider->webhook_token), mailgunEvent('signing-key', 'complained', $recipient->email, $recipient->id))
            ->assertNoContent();

        $this->useWorkspace($this->workspace);

        expect(Contact::first()->status)->toBe(ContactStatus::Unsubscribed)
            ->and(Suppression::first()->reason)->toBe(SuppressionReason::Complaint);
    });

    test('a request with the wrong signature is rejected', function () {
        sentCampaignThrough($this->provider, $this);
        $recipient = CampaignRecipient::first();

        $this->postJson(route('marketing.webhooks.provider', $this->provider->webhook_token), mailgunEvent('wrong-key', 'complained', $recipient->email, $recipient->id))
            ->assertStatus(406);

        $this->useWorkspace($this->workspace);
        expect(Suppression::count())->toBe(0);
    });

    test('an event cannot touch a recipient in another workspace', function () {
        sentCampaignThrough($this->provider, $this);
        $victim = CampaignRecipient::first();

        [, $other] = $this->createOwner('Attacker');
        $attackerProvider = Provider::create([
            'name' => 'Mailgun', 'type' => ProviderType::Mailgun, 'max_per_second' => 1,
            'credentials' => ['api_key' => 'k', 'domain' => 'd', 'region' => 'us'], 'webhook_secret' => 'attacker-key',
        ]);

        $this->postJson(route('marketing.webhooks.provider', $attackerProvider->webhook_token), mailgunEvent('attacker-key', 'complained', $victim->email, $victim->id))
            ->assertNoContent();

        $this->useWorkspace($this->workspace);
        expect($victim->fresh()->complained_at)->toBeNull()
            ->and(Contact::first()->status)->toBe(ContactStatus::Subscribed);
    });
});

test('temporary bounces are told apart from permanent ones', function (ProviderType $type, array $payload, bool $temporary) {
    expect(BounceClassifier::isTemporary($type, $payload))->toBe($temporary);
})->with([
    'brevo soft' => [ProviderType::Brevo, ['event' => 'soft_bounce'], true],
    'brevo hard' => [ProviderType::Brevo, ['event' => 'hard_bounce'], false],
    'mandrill soft' => [ProviderType::Mandrill, ['event' => 'soft_bounce'], true],
    'mandrill hard' => [ProviderType::Mandrill, ['event' => 'hard_bounce'], false],
    'mailersend soft' => [ProviderType::MailerSend, ['type' => 'activity.soft_bounced'], true],
    'mailersend hard' => [ProviderType::MailerSend, ['type' => 'activity.hard_bounced'], false],
    'postmark soft' => [ProviderType::Postmark, ['Type' => 'SoftBounce'], true],
    'postmark transient' => [ProviderType::Postmark, ['Type' => 'Transient'], true],
    'postmark hard' => [ProviderType::Postmark, ['Type' => 'HardBounce'], false],
    'sendgrid blocked' => [ProviderType::SendGrid, ['type' => 'blocked'], true],
    'sendgrid bounce' => [ProviderType::SendGrid, ['type' => 'bounce'], false],
    'mailjet soft' => [ProviderType::Mailjet, ['hard_bounce' => false], true],
    'mailjet hard' => [ProviderType::Mailjet, ['hard_bounce' => true], false],
    'resend transient' => [ProviderType::Resend, ['data' => ['bounce' => ['type' => 'Transient']]], true],
    'resend permanent' => [ProviderType::Resend, ['data' => ['bounce' => ['type' => 'Permanent']]], false],
    'resend untyped' => [ProviderType::Resend, ['data' => []], false],
    'mailgun default' => [ProviderType::Mailgun, ['event' => 'failed'], false],
]);

test('a soft bounce is recorded as a deferral and does not suppress', function () {
    $provider = Provider::create([
        'name' => 'Mailjet', 'type' => ProviderType::Mailjet, 'max_per_second' => 100,
        'credentials' => ['api_key' => 'key', 'secret_key' => 'secret'],
        'webhook_secret' => 'gmk:test',
    ]);
    $campaign = sentCampaignThrough($provider, $this);
    $recipient = CampaignRecipient::first();

    $this->postJson(route('marketing.webhooks.provider', $provider->webhook_token), [
        'event' => 'bounce',
        'time' => time(),
        'email' => $recipient->email,
        'MessageID' => 12345,
        'hard_bounce' => false,
        'smtp_reply' => 'mailbox full',
        MessageHeaders::RECIPIENT_KEY => (string) $recipient->id,
    ], ['Authorization' => 'Basic '.base64_encode('gmk:test')])->assertNoContent();

    $this->useWorkspace($this->workspace);

    expect(Suppression::count())->toBe(0)
        ->and($recipient->fresh()->status)->not->toBe(RecipientStatus::Bounced)
        ->and($campaign->fresh()->bounced_count)->toBe(0)
        ->and(ActivityEvent::where('type', EventType::Deferred)->count())->toBe(1)
        ->and(ActivityEvent::where('type', EventType::Bounced)->count())->toBe(0);
});

test('unknown webhook tokens are not found', function () {
    $this->postJson(route('marketing.webhooks.provider', 'nope'), [])->assertNotFound();
});

describe('Amazon SES through SNS', function () {
    beforeEach(function () {
        $this->provider = Provider::create([
            'name' => 'SES', 'type' => ProviderType::AmazonSes, 'max_per_second' => 14,
            'credentials' => ['access_key_id' => 'AKIA', 'secret_access_key' => 's', 'region' => 'eu-west-1', 'configuration_set' => 'marketing'],
        ]);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key), null, $key, 1);
        openssl_x509_export($certificate, $pem);
        $this->snsKey = $key;
        $this->certUrl = 'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-test.pem';

        Cache::flush();
        Http::fake([
            $this->certUrl => Http::response($pem),
            'https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription*' => Http::response('<ok/>'),
        ]);
    });

    function snsMessage(array $fields, $key, string $certUrl): array
    {
        $message = [...$fields, 'SignatureVersion' => '2', 'SigningCertURL' => $certUrl, 'Timestamp' => now()->toIso8601String(), 'TopicArn' => 'arn:aws:sns:eu-west-1:1:ses', 'MessageId' => uniqid()];
        $keys = $message['Type'] === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $signed = '';

        foreach ($keys as $name) {
            if (array_key_exists($name, $message)) {
                $signed .= $name."\n".$message[$name]."\n";
            }
        }

        openssl_sign($signed, $signature, $key, OPENSSL_ALGO_SHA256);

        return [...$message, 'Signature' => base64_encode($signature)];
    }

    test('a signed bounce notification is applied', function () {
        sentCampaignThrough($this->provider, $this);
        $recipient = CampaignRecipient::first();

        $notification = json_encode([
            'eventType' => 'Bounce',
            'mail' => ['messageId' => 'ses-1', 'tags' => [MessageHeaders::RECIPIENT_KEY => [(string) $recipient->id]]],
            'bounce' => ['bounceType' => 'Permanent', 'bounceSubType' => 'General', 'timestamp' => now()->toIso8601String(), 'bouncedRecipients' => [['emailAddress' => $recipient->email]]],
        ]);

        $this->call('POST', route('marketing.webhooks.provider', $this->provider->webhook_token), [], [], [], ['CONTENT_TYPE' => 'text/plain'],
            json_encode(snsMessage(['Type' => 'Notification', 'Message' => $notification], $this->snsKey, $this->certUrl)))
            ->assertNoContent();

        $this->useWorkspace($this->workspace);
        expect($recipient->fresh()->status)->toBe(RecipientStatus::Bounced)
            ->and(Suppression::first()->detail)->toBe('Permanent General');
    });

    test('a tampered message is rejected', function () {
        $message = snsMessage(['Type' => 'Notification', 'Message' => '{"eventType":"Complaint"}'], $this->snsKey, $this->certUrl);
        $message['Message'] = '{"eventType":"Bounce"}';

        $this->call('POST', route('marketing.webhooks.provider', $this->provider->webhook_token), [], [], [], [], json_encode($message))
            ->assertStatus(406);
    });

    test('certificates from outside Amazon are refused', function () {
        $message = snsMessage(['Type' => 'Notification', 'Message' => '{}'], $this->snsKey, 'https://evil.example.com/cert.pem');

        $this->call('POST', route('marketing.webhooks.provider', $this->provider->webhook_token), [], [], [], [], json_encode($message))
            ->assertStatus(406);

        Http::assertNothingSent();
    });

    test('subscription confirmations are accepted automatically', function () {
        $subscribeUrl = 'https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc';

        $this->call('POST', route('marketing.webhooks.provider', $this->provider->webhook_token), [], [], [], [],
            json_encode(snsMessage(['Type' => 'SubscriptionConfirmation', 'Message' => 'Confirm', 'SubscribeURL' => $subscribeUrl, 'Token' => 'abc'], $this->snsKey, $this->certUrl)))
            ->assertNoContent();

        Http::assertSent(fn ($request) => $request->url() === $subscribeUrl);
    });
});

test('too many bounces pause the workspace', function () {
    config(['marketing.sending.auto_pause.minimum_sends' => 2]);
    $provider = Provider::create([
        'name' => 'Mailgun', 'type' => ProviderType::Mailgun, 'max_per_second' => 100,
        'credentials' => ['api_key' => 'key', 'domain' => 'mg.test', 'region' => 'us'], 'webhook_secret' => 'k',
    ]);
    sentCampaignThrough($provider, $this, contacts: 4);
    $recipient = CampaignRecipient::first();

    $this->postJson(route('marketing.webhooks.provider', $provider->webhook_token), mailgunEvent('k', 'failed', $recipient->email, $recipient->id, [
        'severity' => 'permanent', 'reason' => 'bounce', 'delivery-status' => ['code' => 550, 'description' => 'gone', 'message' => ''],
    ]))->assertNoContent();

    expect($this->workspace->fresh()->isSendingPaused())->toBeTrue()
        ->and($this->workspace->fresh()->sending_paused_reason)->toContain('Bounce rate');
});

test('a payload the parser cannot read is rejected rather than erroring', function () {
    $provider = Provider::create([
        'name' => 'Mailgun', 'type' => ProviderType::Mailgun, 'max_per_second' => 1,
        'credentials' => ['api_key' => 'k', 'domain' => 'd', 'region' => 'us'], 'webhook_secret' => 'k',
    ]);
    $payload = mailgunEvent('k', 'failed', 'a@example.com', 1, ['severity' => 'permanent', 'reason' => 'bounce']);

    $this->postJson(route('marketing.webhooks.provider', $provider->webhook_token), $payload)->assertStatus(400);
});
