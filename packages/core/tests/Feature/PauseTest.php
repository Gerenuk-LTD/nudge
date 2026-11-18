<?php

use Gerenuk\Marketing\Actions\Campaigns\ScheduleCampaign;
use Gerenuk\Marketing\Campaigns\CampaignAudience;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Jobs\SendCampaignBatch;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Sending\ProviderTransports;
use Gerenuk\Marketing\Sending\ProviderType;
use Gerenuk\Marketing\Sending\TrackingUrls;
use Gerenuk\Marketing\Tests\Fixtures\CapturingTransports;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
    $this->workspace->update(['postal_address' => "1 High Street\nLondon"]);
    $this->transports = new CapturingTransports;
    $this->app->instance(ProviderTransports::class, $this->transports);
    $this->provider = Provider::create([
        'name' => 'Mailgun', 'type' => ProviderType::Mailgun, 'max_per_second' => 100,
        'credentials' => ['api_key' => 'key', 'domain' => 'mg.acme.test', 'region' => 'eu'],
    ]);

    foreach (['ann', 'bob', 'cat'] as $name) {
        Contact::factory()->create(['email' => "{$name}@example.com"]);
    }
});

function sendCampaignNow($test): Campaign
{
    $campaign = Campaign::factory()->create(['provider_id' => $test->provider->id]);
    app(ScheduleCampaign::class)->handle($campaign, $test->workspace->fresh());
    $test->useWorkspace($test->workspace);

    return $campaign->fresh();
}

function pausePath(CampaignRecipient $recipient): string
{
    return parse_url(app(TrackingUrls::class)->pause($recipient), PHP_URL_PATH);
}

test('a recipient can pause emails from the unsubscribe page', function () {
    sendCampaignNow($this);
    $recipient = CampaignRecipient::query()->where('email', 'ann@example.com')->sole();

    $this->post(pausePath($recipient), ['days' => 30])
        ->assertRedirect(app(TrackingUrls::class)->unsubscribe($recipient));

    $contact = $recipient->contact->fresh();

    expect($contact->status)->toBe(ContactStatus::Subscribed)
        ->and($contact->isPaused())->toBeTrue()
        ->and(abs($contact->paused_until->diffInHours(now()->addDays(30))))->toBeLessThan(1)
        ->and($contact->events()->where('type', EventType::Paused)->sole()->data)->toBe(['days' => 30]);

    $this->get(parse_url(app(TrackingUrls::class)->unsubscribe($recipient), PHP_URL_PATH))
        ->assertInertia(fn ($page) => $page->where('pausedUntil', $contact->paused_until->toIso8601String()));
});

test('paused contacts are left out of new campaigns and their counts', function () {
    Contact::query()->where('email', 'bob@example.com')->update(['paused_until' => now()->addDays(30)]);
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    expect(app(CampaignAudience::class)->count($campaign))->toBe(2)
        ->and(app(CampaignAudience::class)->sizes()['everyone'])->toBe(2);

    sendCampaignNow($this);

    expect($this->transports->recipients())->toEqualCanonicalizing(['ann@example.com', 'cat@example.com']);
});

test('a contact paused after the audience was built is skipped at send time', function () {
    Queue::fake([SendCampaignBatch::class]);
    $campaign = sendCampaignNow($this);
    expect(CampaignRecipient::count())->toBe(3);

    Contact::query()->where('email', 'bob@example.com')->update(['paused_until' => now()->addDays(30)]);

    Queue::assertPushed(SendCampaignBatch::class, function (SendCampaignBatch $job): bool {
        app()->call([$job, 'handle']);

        return true;
    });

    expect(CampaignRecipient::query()->where('email', 'bob@example.com')->value('status'))->toBe(RecipientStatus::Skipped)
        ->and($this->transports->recipients())->toEqualCanonicalizing(['ann@example.com', 'cat@example.com']);
});

test('a pause ends by itself', function () {
    $contact = Contact::query()->where('email', 'bob@example.com')->sole();
    $contact->update(['paused_until' => now()->subMinute()]);

    expect($contact->fresh()->isPaused())->toBeFalse()
        ->and(app(CampaignAudience::class)->reachable()->count())->toBe(3);
});

test('pausing needs a valid signature and an allowed length', function () {
    sendCampaignNow($this);
    $recipient = CampaignRecipient::query()->where('email', 'ann@example.com')->sole();

    $this->post("/u/{$recipient->id}/not-a-real-signature/pause", ['days' => 30])->assertNotFound();
    $this->post(pausePath($recipient), ['days' => 7])->assertSessionHasErrors('days');

    expect($recipient->contact->fresh()->paused_until)->toBeNull();
});

test('pausing never resubscribes someone who unsubscribed', function () {
    sendCampaignNow($this);
    $recipient = CampaignRecipient::query()->where('email', 'ann@example.com')->sole();
    $recipient->contact->update(['status' => ContactStatus::Unsubscribed, 'unsubscribed_at' => now()]);

    $this->post(pausePath($recipient), ['days' => 90])->assertRedirect();

    $contact = $recipient->contact->fresh();

    expect($contact->status)->toBe(ContactStatus::Unsubscribed)
        ->and($contact->paused_until)->toBeNull()
        ->and($contact->events()->where('type', EventType::Paused)->exists())->toBeFalse();
});

test('one-click unsubscribe still unsubscribes a paused contact', function () {
    sendCampaignNow($this);
    $recipient = CampaignRecipient::query()->where('email', 'ann@example.com')->sole();
    $this->post(pausePath($recipient), ['days' => 30]);

    $this->post(parse_url(app(TrackingUrls::class)->unsubscribe($recipient), PHP_URL_PATH), ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect($recipient->contact->fresh()->status)->toBe(ContactStatus::Unsubscribed);
});
