<?php

use Gerenuk\Marketing\Actions\Campaigns\ScheduleCampaign;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignLink;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Sending\ProviderTransports;
use Gerenuk\Marketing\Sending\ProviderType;
use Gerenuk\Marketing\Sending\TrackingUrls;
use Gerenuk\Marketing\Tests\Fixtures\CapturingTransports;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
    $this->workspace->update(['postal_address' => '1 High Street']);
    $this->app->instance(ProviderTransports::class, new CapturingTransports);

    $provider = Provider::create([
        'name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 100,
        'credentials' => ['host' => 'smtp.test', 'port' => '587', 'encryption' => 'starttls'],
    ]);
    Contact::factory()->create(['email' => 'ann@example.com']);
    $this->campaign = Campaign::factory()->create(['provider_id' => $provider->id]);
    app(ScheduleCampaign::class)->handle($this->campaign, $this->workspace->fresh());
    $this->useWorkspace($this->workspace);

    $this->recipient = CampaignRecipient::first();
    $this->link = CampaignLink::first();
    $this->urls = app(TrackingUrls::class);

    // Clicks within seconds of sending look like security scanners, so move past that window.
    $this->travel(1)->minutes();
});

function pathOf(string $url): string
{
    return (string) parse_url($url, PHP_URL_PATH);
}

test('a click redirects to the original link and is counted once per person', function () {
    $url = pathOf($this->urls->click($this->recipient, $this->link));

    $this->get($url)->assertRedirect('https://acme.test/offer?a=1&b=2');
    $this->get($url)->assertRedirect();

    $this->useWorkspace($this->workspace);

    expect($this->campaign->fresh()->clicked_count)->toBe(1)
        ->and($this->link->fresh()->clicks_count)->toBe(2)
        ->and($this->link->fresh()->unique_clicks_count)->toBe(1);
});

test('clicks from security scanners are recorded but not counted', function () {
    $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Barracuda Sentinel)')
        ->get(pathOf($this->urls->click($this->recipient, $this->link)))
        ->assertRedirect();

    $this->useWorkspace($this->workspace);

    expect($this->campaign->fresh()->clicked_count)->toBe(0)
        ->and($this->link->fresh()->clicks_count)->toBe(0);
});

test('tampered tracking links are not found', function () {
    $this->get("/t/c/{$this->recipient->id}/{$this->link->id}/notasignature")->assertNotFound();
});

test('the open pixel is a gif and counts unique opens', function () {
    $url = pathOf($this->urls->open($this->recipient));

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/gif');
    $this->get($url)->assertOk();

    $this->useWorkspace($this->workspace);
    expect($this->campaign->fresh()->opened_count)->toBe(1);
});

test('the unsubscribe page confirms before unsubscribing', function () {
    $url = pathOf($this->urls->unsubscribe($this->recipient));

    $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page
        ->component('marketing/public/unsubscribe')
        ->where('email', 'ann@example.com')
        ->where('unsubscribed', false));

    $this->post($url)->assertRedirect();

    $this->useWorkspace($this->workspace);
    expect(Contact::first()->status)->toBe(ContactStatus::Unsubscribed)
        ->and($this->campaign->fresh()->unsubscribed_count)->toBe(1);
});

test('one-click unsubscribe from a mailbox provider works without a session', function () {
    $this->post(pathOf($this->urls->unsubscribe($this->recipient)), ['List-Unsubscribe' => 'One-Click'])->assertOk();

    $this->useWorkspace($this->workspace);
    expect(Contact::first()->status)->toBe(ContactStatus::Unsubscribed);
});

test('a tracking link signed for one recipient does not work for another', function () {
    $signature = basename(pathOf($this->urls->unsubscribe($this->recipient)));
    $other = $this->recipient->id + 1;

    $this->post("/u/{$other}/{$signature}")->assertNotFound();
});
