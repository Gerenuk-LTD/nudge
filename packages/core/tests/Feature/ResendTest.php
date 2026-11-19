<?php

use Gerenuk\Nudge\Actions\Campaigns\ScheduleCampaign;
use Gerenuk\Nudge\Campaigns\CampaignAudience;
use Gerenuk\Nudge\Enums\AudienceType;
use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\CampaignRecipient;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Sending\ProviderTransports;
use Gerenuk\Nudge\Sending\ProviderType;
use Gerenuk\Nudge\Tests\Fixtures\CapturingTransports;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
    $this->workspace->update(['postal_address' => "1 High Street\nLondon"]);
    $this->app->instance(ProviderTransports::class, new CapturingTransports);
    $this->provider = Provider::create([
        'name' => 'Mailgun', 'type' => ProviderType::Mailgun, 'max_per_second' => 100,
        'credentials' => ['api_key' => 'key', 'domain' => 'mg.acme.test', 'region' => 'eu'],
    ]);
});

function sentToThreeWithOneOpen($test): Campaign
{
    foreach (['ann', 'bob', 'cat'] as $name) {
        Contact::factory()->create(['email' => "{$name}@example.com"]);
    }

    $campaign = Campaign::factory()->create(['name' => 'November news', 'provider_id' => $test->provider->id]);
    app(ScheduleCampaign::class)->handle($campaign, $test->workspace->fresh());
    $test->useWorkspace($test->workspace);

    CampaignRecipient::query()->where('email', 'ann@example.com')->update(['opened_at' => now()]);

    return $campaign->fresh();
}

test('resending to non-openers drafts a copy aimed at the right segment', function () {
    $campaign = sentToThreeWithOneOpen($this);
    expect($campaign->status)->toBe(CampaignStatus::Sent);

    $response = $this->actingAs($this->owner)->post(route('nudge.campaigns.resend', $campaign));

    $copy = Campaign::query()->whereKeyNot($campaign->id)->sole();
    $response->assertRedirect(route('nudge.campaigns.edit', $copy));

    expect($copy->status)->toBe(CampaignStatus::Draft)
        ->and($copy->name)->toBe('November news (resend)')
        ->and($copy->audience_type)->toBe(AudienceType::Segment)
        ->and($copy->segment->name)->toBe("Didn't open: November news")
        ->and(app(CampaignAudience::class)->count($copy))->toBe(2);
});

test('resending twice reuses the segment', function () {
    $campaign = sentToThreeWithOneOpen($this);

    $this->actingAs($this->owner)->post(route('nudge.campaigns.resend', $campaign))->assertRedirect();
    $this->actingAs($this->owner)->post(route('nudge.campaigns.resend', $campaign))->assertRedirect();

    expect(Segment::count())->toBe(1)
        ->and(Campaign::query()->whereKeyNot($campaign->id)->pluck('segment_id')->unique()->all())->toBe([Segment::first()->id]);
});

test('only sent broadcasts can be resent', function () {
    $draft = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    $this->actingAs($this->owner)->post(route('nudge.campaigns.resend', $draft))->assertNotFound();

    expect(Segment::count())->toBe(0);
});

test('viewers cannot resend', function () {
    $campaign = sentToThreeWithOneOpen($this);
    $viewer = $this->addMember($this->workspace, 'Viewer');

    $this->actingAs($viewer)->post(route('nudge.campaigns.resend', $campaign))->assertForbidden();

    expect(Campaign::count())->toBe(1);
});
