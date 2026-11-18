<?php

use Gerenuk\Marketing\Actions\Campaigns\ScheduleCampaign;
use Gerenuk\Marketing\Campaigns\AbTestResults;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Jobs\PickAbTestWinner;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Sending\ProviderTransports;
use Gerenuk\Marketing\Sending\ProviderType;
use Gerenuk\Marketing\Tests\Fixtures\CapturingTransports;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
    $this->workspace->update(['postal_address' => '1 High Street']);
    $this->transports = new CapturingTransports;
    $this->app->instance(ProviderTransports::class, $this->transports);
    $this->provider = Provider::create([
        'name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 100,
        'credentials' => ['host' => 'h', 'port' => '25', 'encryption' => 'starttls'],
    ]);
    Contact::factory()->count(20)->create();
});

function abCampaign($test, array $overrides = []): Campaign
{
    return Campaign::factory()->create([
        'provider_id' => $test->provider->id,
        'subject' => 'Subject A',
        'ab_enabled' => true,
        'ab_subject_b' => 'Subject B',
        'ab_sample_percent' => 20,
        'ab_wait_minutes' => 60,
        ...$overrides,
    ]);
}

test('a sample gets both variants and everyone else waits', function () {
    Queue::fake([PickAbTestWinner::class]);
    $campaign = abCampaign($this);

    app(ScheduleCampaign::class)->handle($campaign, $this->workspace->fresh());
    $this->useWorkspace($this->workspace);

    $subjects = array_count_values(array_map(fn ($email) => $email->getSubject(), $this->transports->sent));
    ksort($subjects);

    expect($subjects)->toBe(['Subject A' => 2, 'Subject B' => 2])
        ->and(CampaignRecipient::where('status', RecipientStatus::Held)->count())->toBe(16)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sending);

    Queue::assertPushed(PickAbTestWinner::class, fn ($job) => $job->campaignId === $campaign->id);
});

test('the variant with more clicks is sent to everyone else', function () {
    Queue::fake([PickAbTestWinner::class]);
    $campaign = abCampaign($this);
    app(ScheduleCampaign::class)->handle($campaign, $this->workspace->fresh());
    $this->useWorkspace($this->workspace);

    CampaignRecipient::where('variant', 'b')->limit(1)->update(['clicked_at' => now()]);

    app()->call([new PickAbTestWinner($campaign->id, $this->workspace->id), 'handle']);
    $this->useWorkspace($this->workspace);

    $campaign->refresh();
    $subjects = array_count_values(array_map(fn ($email) => $email->getSubject(), $this->transports->sent));
    ksort($subjects);

    expect($campaign->ab_winner)->toBe('b')
        ->and($subjects)->toBe(['Subject A' => 2, 'Subject B' => 18])
        ->and($campaign->status)->toBe(CampaignStatus::Sent)
        ->and(CampaignRecipient::where('status', RecipientStatus::Held)->count())->toBe(0);
});

test('a tie goes to the original', function () {
    Queue::fake([PickAbTestWinner::class]);
    $campaign = abCampaign($this);
    app(ScheduleCampaign::class)->handle($campaign, $this->workspace->fresh());

    app()->call([new PickAbTestWinner($campaign->id, $this->workspace->id), 'handle']);
    $this->useWorkspace($this->workspace);

    expect($campaign->fresh()->ab_winner)->toBe('a');
});

test('content can be tested instead of the subject', function () {
    Queue::fake([PickAbTestWinner::class]);
    $campaign = abCampaign($this, ['ab_subject_b' => null, 'ab_html_b' => '<p>Version B</p>']);

    app(ScheduleCampaign::class)->handle($campaign, $this->workspace->fresh());

    $bodies = array_map(fn ($email) => str_contains((string) $email->getHtmlBody(), 'Version B'), $this->transports->sent);

    $counts = array_count_values(array_map('intval', $bodies));
    ksort($counts);

    expect($counts)->toBe([0 => 2, 1 => 2]);
});

test('the winner is not picked while the sample is still unsent', function () {
    Queue::fake([PickAbTestWinner::class]);
    $campaign = abCampaign($this);
    app(ScheduleCampaign::class)->handle($campaign, $this->workspace->fresh());
    $this->useWorkspace($this->workspace);

    CampaignRecipient::whereIn('variant', ['a', 'b'])->update(['status' => RecipientStatus::Queued, 'sent_at' => null]);

    app()->call([new PickAbTestWinner($campaign->id, $this->workspace->id), 'handle']);
    $this->useWorkspace($this->workspace);

    expect($campaign->fresh()->ab_winner)->toBeNull();

    Queue::assertPushed(PickAbTestWinner::class, 2);
});

test('picking the winner now decides even without data', function () {
    Queue::fake([PickAbTestWinner::class]);
    $campaign = abCampaign($this);
    app(ScheduleCampaign::class)->handle($campaign, $this->workspace->fresh());
    $this->useWorkspace($this->workspace);

    CampaignRecipient::whereIn('variant', ['a', 'b'])->update(['status' => RecipientStatus::Queued, 'sent_at' => null]);

    app()->call([new PickAbTestWinner($campaign->id, $this->workspace->id, force: true), 'handle']);
    $this->useWorkspace($this->workspace);

    expect($campaign->fresh()->ab_winner)->toBe('a');
});

test('the test results stay as they were when the winner was chosen', function () {
    Queue::fake([PickAbTestWinner::class]);
    $campaign = abCampaign($this);
    app(ScheduleCampaign::class)->handle($campaign, $this->workspace->fresh());
    $this->useWorkspace($this->workspace);

    CampaignRecipient::where('variant', 'b')->limit(1)->update(['clicked_at' => now()]);

    app()->call([new PickAbTestWinner($campaign->id, $this->workspace->id), 'handle']);
    $this->useWorkspace($this->workspace);

    $campaign = $campaign->fresh();

    expect($campaign->ab_winner)->toBe('b')
        ->and(app(AbTestResults::class)->for($campaign)['b']['sent'])->toBe(2);
});

test('an A/B test without a variant B cannot be sent', function () {
    $campaign = abCampaign($this, ['ab_subject_b' => null]);

    $this->actingAs($this->owner)->post(route('marketing.campaigns.schedule', $campaign))->assertSessionHasErrors('campaign');
});

test('duplicating a campaign does not copy its frozen a/b results', function () {
    $campaign = abCampaign($this, [
        'status' => CampaignStatus::Sent,
        'ab_winner' => 'a',
        'ab_results' => ['a' => ['sent' => 2, 'opened' => 1, 'clicked' => 0, 'rate' => 0.5], 'b' => ['sent' => 2, 'opened' => 0, 'clicked' => 0, 'rate' => 0.0]],
    ]);

    $this->actingAs($this->owner)->post(route('marketing.campaigns.duplicate', $campaign))->assertRedirect();

    $copy = Campaign::query()->whereKeyNot($campaign->id)->sole();

    expect($copy->ab_results)->toBeNull()
        ->and($copy->ab_winner)->toBeNull()
        ->and($campaign->fresh()->ab_results)->not->toBeNull();
});
