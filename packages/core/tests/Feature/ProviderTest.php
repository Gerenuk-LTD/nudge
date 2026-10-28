<?php

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
