<?php

use Gerenuk\Marketing\Enums\AudienceType;
use Gerenuk\Marketing\Enums\AutomationStatus;
use Gerenuk\Marketing\Enums\AutomationTrigger;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Jobs\DispatchDueCampaigns;
use Gerenuk\Marketing\Jobs\PrepareCampaign;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Sending\ProviderTransports;
use Gerenuk\Marketing\Sending\ProviderType;
use Gerenuk\Marketing\Tests\Fixtures\CapturingTransports;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
    $this->workspace->update(['postal_address' => '1 High Street', 'requires_campaign_approval' => true]);

    $this->transports = new CapturingTransports;
    $this->app->instance(ProviderTransports::class, $this->transports);

    $this->provider = Provider::create([
        'name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 100,
        'credentials' => ['host' => 'smtp.test', 'port' => '587', 'encryption' => 'starttls'],
    ]);

    CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);
    $this->segment = Segment::create(['name' => 'Pro', 'definition' => segmentApprovalDefinition('pro')]);
    $this->editor = $this->addMember($this->workspace, 'Editor');

    Contact::factory()->create();
});

function segmentApprovalDefinition(string $plan): array
{
    return ['match' => 'all', 'conditions' => [['type' => 'condition', 'field' => 'custom:plan', 'operator' => 'equals', 'value' => $plan]]];
}

function segmentApprovalCampaign($test, array $overrides = []): Campaign
{
    return Campaign::factory()->create([...[
        'provider_id' => $test->provider->id,
        'audience_type' => AudienceType::Segment,
        'segment_id' => $test->segment->id,
        'approved_at' => now(),
        'approved_by' => $test->owner->id,
    ], ...$overrides]);
}

function segmentApprovalScheduled($test, array $overrides = []): Campaign
{
    return segmentApprovalCampaign($test, [...[
        'status' => CampaignStatus::Scheduled,
        'scheduled_at' => now()->addHour(),
    ], ...$overrides]);
}

function segmentApprovalSave($test, $user, ?array $definition = null, ?string $name = null): void
{
    $test->actingAs($user)->put(route('marketing.segments.update', $test->segment), [
        'name' => $name ?? $test->segment->name,
        'definition' => $definition ?? $test->segment->definition,
    ])->assertSessionHasNoErrors();
}

test("an editor's segment edit withdraws a draft's approval", function () {
    $campaign = segmentApprovalCampaign($this);

    segmentApprovalSave($this, $this->editor, segmentApprovalDefinition('free'));

    $this->useWorkspace($this->workspace);
    expect($campaign->fresh()->approved_at)->toBeNull()->and($campaign->fresh()->approved_by)->toBeNull();
});

test('and returns a scheduled campaign to draft, unsent', function () {
    $campaign = segmentApprovalScheduled($this);

    segmentApprovalSave($this, $this->editor, segmentApprovalDefinition('free'));

    $this->useWorkspace($this->workspace);
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->fresh()->scheduled_at)->toBeNull()
        ->and($campaign->fresh()->approved_at)->toBeNull();

    $this->travel(2)->hours();
    (new DispatchDueCampaigns)();

    $this->useWorkspace($this->workspace);
    expect($this->transports->sent)->toBe([])->and($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
});

test("an approver's edit keeps approval", function () {
    $campaign = segmentApprovalScheduled($this);
    $approvedAt = $campaign->fresh()->approved_at;

    segmentApprovalSave($this, $this->owner, segmentApprovalDefinition('free'));

    $this->useWorkspace($this->workspace);
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled)
        ->and($campaign->fresh()->approved_at->equalTo($approvedAt))->toBeTrue();
});

test('re-saving the same definition changes nothing', function () {
    $campaign = segmentApprovalScheduled($this);

    segmentApprovalSave($this, $this->editor, name: 'Renamed');

    $this->useWorkspace($this->workspace);
    expect($this->segment->fresh()->name)->toBe('Renamed')
        ->and($campaign->fresh()->approved_at)->not->toBeNull()
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled);
});

test('campaigns aimed at other segments, lists or everyone are untouched', function () {
    $otherSegment = Segment::create(['name' => 'Other', 'definition' => segmentApprovalDefinition('other')]);
    $list = ContactList::factory()->create();

    $campaigns = [
        segmentApprovalScheduled($this, ['segment_id' => $otherSegment->id]),
        segmentApprovalScheduled($this, ['audience_type' => AudienceType::Lists, 'segment_id' => null, 'list_ids' => [$list->id]]),
        segmentApprovalScheduled($this, ['audience_type' => AudienceType::AllContacts, 'segment_id' => null]),
    ];

    segmentApprovalSave($this, $this->editor, segmentApprovalDefinition('free'));

    $this->useWorkspace($this->workspace);
    foreach ($campaigns as $campaign) {
        expect($campaign->fresh()->approved_at)->not->toBeNull()->and($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled);
    }
});

test('a live "enters a segment" automation pauses', function () {
    $automation = Automation::create([
        'name' => 'Pro welcome',
        'trigger_type' => AutomationTrigger::SegmentEntered,
        'trigger_config' => ['segment_id' => $this->segment->id],
        'definition' => ['steps' => [[
            'id' => 'e1', 'type' => 'send_email',
            'config' => ['subject' => 'Hello', 'from_name' => 'Acme', 'from_email' => 'hi@acme.test', 'provider_id' => $this->provider->id, 'html' => '<p>Hello</p>'],
        ]]],
    ]);

    $this->actingAs($this->owner)->post(route('marketing.automations.approve', $automation))->assertRedirect();
    $this->actingAs($this->editor)->post(route('marketing.automations.activate', $automation))->assertSessionHasNoErrors();
    expect($automation->fresh()->status)->toBe(AutomationStatus::Active);

    segmentApprovalSave($this, $this->editor, segmentApprovalDefinition('free'));

    $this->useWorkspace($this->workspace);
    expect($automation->fresh()->status)->toBe(AutomationStatus::Paused)
        ->and($automation->fresh()->approved_at)->toBeNull();
});

test('without the workspace setting, a scheduled campaign stays scheduled', function () {
    $this->workspace->update(['requires_campaign_approval' => false]);
    $campaign = segmentApprovalScheduled($this);

    segmentApprovalSave($this, $this->editor, segmentApprovalDefinition('free'));

    $this->useWorkspace($this->workspace);
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled);
});

test('an unapproved scheduled campaign is returned to draft instead of sent', function () {
    $campaign = segmentApprovalScheduled($this, ['approved_at' => null, 'approved_by' => null, 'scheduled_at' => now()->subMinute()]);

    PrepareCampaign::dispatchFor($campaign);

    $this->useWorkspace($this->workspace);
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->fresh()->scheduled_at)->toBeNull()
        ->and($this->transports->sent)->toBe([]);
});

test('approving a stale version is refused', function () {
    $campaign = segmentApprovalCampaign($this, ['approved_at' => null, 'approved_by' => null]);

    $this->actingAs($this->owner)
        ->post(route('marketing.campaigns.approve', $campaign), ['version' => now()->subDay()->toIso8601String()])
        ->assertSessionHasErrors('campaign');

    $this->useWorkspace($this->workspace);
    expect($campaign->fresh()->approved_at)->toBeNull();

    $this->actingAs($this->owner)
        ->post(route('marketing.campaigns.approve', $campaign), ['version' => $campaign->fresh()->updated_at->toIso8601String()])
        ->assertSessionHasNoErrors();

    $this->useWorkspace($this->workspace);
    expect($campaign->fresh()->approved_at)->not->toBeNull();
});
