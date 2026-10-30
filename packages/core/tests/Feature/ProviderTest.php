<?php

use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Sending\ProviderTransports;
use Gerenuk\Marketing\Sending\ProviderType;
use Gerenuk\Marketing\Tests\Fixtures\CapturingTransports;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Mailer\Transport;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
});

test('every provider type builds a working transport from its default credentials', function (ProviderType $type) {
    $credentials = [];

    foreach ($type->fields() as $field) {
        $credentials[$field->key] = $field->default ?? 'value-with/special:chars';
    }

    expect(Transport::fromDsn($type->dsn($credentials)))->toBeObject();
})->with(ProviderType::cases());

test('credentials and the webhook secret are encrypted at rest', function () {
    $this->actingAs($this->owner)->post(route('marketing.providers.store'), [
        'name' => 'Main', 'type' => 'mailgun', 'max_per_second' => 10,
        'credentials' => ['api_key' => 'key-123', 'domain' => 'mg.acme.test', 'region' => 'eu'],
        'webhook_secret' => 'signing-456',
    ])->assertRedirect();

    $raw = DB::table('providers')->first();

    expect($raw->credentials)->not->toContain('key-123')
        ->and($raw->webhook_secret)->not->toContain('signing-456')
        ->and(Provider::first()->credentials['api_key'])->toBe('key-123');
});

test('leaving a secret blank on edit keeps the saved one', function () {
    $this->actingAs($this->owner)->post(route('marketing.providers.store'), [
        'name' => 'Main', 'type' => 'postmark', 'max_per_second' => 10,
        'credentials' => ['server_token' => 'secret-token', 'message_stream' => 'broadcast'],
    ]);
    $provider = Provider::first();

    $this->actingAs($this->owner)->get(route('marketing.providers.edit', $provider))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('provider.credentials.server_token', ''));

    $this->actingAs($this->owner)->put(route('marketing.providers.update', $provider), [
        'name' => 'Renamed', 'max_per_second' => 5,
        'credentials' => ['server_token' => '', 'message_stream' => 'newsletters'],
    ])->assertSessionHasNoErrors();

    expect($provider->fresh()->credentials)->toBe(['server_token' => 'secret-token', 'message_stream' => 'newsletters'])
        ->and($provider->fresh()->name)->toBe('Renamed');
});

test('basic-auth providers get generated credentials in their webhook URL', function () {
    $this->actingAs($this->owner)->post(route('marketing.providers.store'), [
        'name' => 'Brevo', 'type' => 'brevo', 'max_per_second' => 10, 'credentials' => ['api_key' => 'k'],
    ]);
    $provider = Provider::first();

    expect($provider->webhook_secret)->toStartWith('gmk:');

    $this->actingAs($this->owner)->get(route('marketing.providers.show', $provider))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('provider.webhook_url', fn (string $url) => str_contains($url, 'gmk:') && str_contains($url, $provider->webhook_token)));
});

test('required credentials are validated per provider type', function () {
    $this->actingAs($this->owner)->post(route('marketing.providers.store'), [
        'name' => 'SES', 'type' => 'ses', 'max_per_second' => 10, 'credentials' => ['access_key_id' => 'AKIA'],
    ])->assertSessionHasErrors(['credentials.secret_access_key', 'credentials.region']);
});

test('a test message can be sent through a saved provider', function () {
    $capture = new CapturingTransports;
    $this->app->instance(ProviderTransports::class, $capture);
    $provider = Provider::create(['name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 1, 'credentials' => ['host' => 'h', 'port' => '25', 'encryption' => 'starttls']]);

    $this->actingAs($this->owner)->post(route('marketing.providers.test', $provider), ['to' => 'me@example.com', 'from' => 'hi@acme.test'])
        ->assertSessionHasNoErrors();

    expect($capture->recipients())->toBe(['me@example.com']);
});

test('only people who can manage providers see them', function () {
    $editor = $this->addMember($this->workspace, 'Editor');

    $this->actingAs($editor)->get(route('marketing.providers.index'))->assertForbidden();
});

test('the provider list shows each provider\'s sends and bounces from the last week', function () {
    $mailgun = Provider::create(['name' => 'Mailgun', 'type' => ProviderType::Mailgun, 'max_per_second' => 10, 'credentials' => ['api_key' => 'k', 'domain' => 'd', 'region' => 'us']]);
    $quiet = Provider::create(['name' => 'Quiet', 'type' => ProviderType::Smtp, 'max_per_second' => 1, 'credentials' => ['host' => 'h', 'port' => '25', 'encryption' => 'starttls']]);
    $campaign = Campaign::factory()->create(['provider_id' => $mailgun->id]);
    $old = Campaign::factory()->create(['provider_id' => $mailgun->id]);

    foreach (Contact::factory()->count(3)->create() as $index => $contact) {
        $recipient = CampaignRecipient::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'email' => $contact->email, 'status' => RecipientStatus::Delivered]);
        ActivityEvent::recordFor($recipient, EventType::Sent);

        if ($index === 0) {
            ActivityEvent::recordFor($recipient, EventType::Bounced);
        }
    }

    $stale = CampaignRecipient::create(['campaign_id' => $old->id, 'contact_id' => Contact::first()->id, 'email' => 'x@example.com', 'status' => RecipientStatus::Delivered]);
    ActivityEvent::recordFor($stale, EventType::Sent, occurredAt: now()->subDays(8));

    $this->actingAs($this->owner)->get(route('marketing.providers.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('providers.0.id', $mailgun->id)
            ->where('providers.0.sent_7d', 3)
            ->where('providers.0.bounced_7d', 1)
            ->where('providers.1.id', $quiet->id)
            ->where('providers.1.sent_7d', 0));
});

test('private smtp hosts can be refused by config', function () {
    $payload = [
        'name' => 'Relay', 'type' => 'smtp', 'max_per_second' => 5,
        'credentials' => ['host' => '127.0.0.1', 'port' => '25', 'encryption' => 'starttls'],
    ];

    config(['marketing.sending.allow_private_smtp_hosts' => false]);
    $this->actingAs($this->owner)->post(route('marketing.providers.store'), $payload)
        ->assertSessionHasErrors('credentials.host');
    expect(Provider::count())->toBe(0);

    config(['marketing.sending.allow_private_smtp_hosts' => true]);
    $this->actingAs($this->owner)->post(route('marketing.providers.store'), $payload)
        ->assertSessionHasNoErrors();
    expect(Provider::count())->toBe(1);
});
