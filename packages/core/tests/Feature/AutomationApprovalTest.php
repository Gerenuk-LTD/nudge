<?php

use Gerenuk\Nudge\Automations\AutomationApproval;
use Gerenuk\Nudge\Enums\AutomationStatus;
use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\ContactList;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Sending\ProviderTransports;
use Gerenuk\Nudge\Sending\ProviderType;
use Gerenuk\Nudge\Tests\Fixtures\CapturingTransports;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
    $this->workspace->update(['postal_address' => '1 High Street', 'requires_campaign_approval' => true]);

    $this->transports = new CapturingTransports;
    $this->app->instance(ProviderTransports::class, $this->transports);

    $this->provider = Provider::create([
        'name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 100,
        'credentials' => ['host' => 'smtp.test', 'port' => '587', 'encryption' => 'starttls'],
    ]);

    $this->list = ContactList::factory()->create();
    $this->editor = $this->addMember($this->workspace, 'Editor');
});

function approvalEmail(string $subject, int $providerId, array $overrides = []): array
{
    return [
        'id' => 'e1', 'type' => 'send_email',
        'config' => [...[
            'subject' => $subject, 'from_name' => 'Acme', 'from_email' => 'hi@acme.test', 'provider_id' => $providerId,
            'html' => "<p>{$subject}</p>",
        ], ...$overrides],
    ];
}

function approvalDraft($test, array $steps = [], ?array $triggerConfig = null): Automation
{
    return Automation::create([
        'name' => 'Welcome',
        'trigger_type' => AutomationTrigger::ListJoined,
        'trigger_config' => $triggerConfig ?? ['list_id' => $test->list->id],
        'definition' => ['steps' => $steps ?: [approvalEmail('Hello', $test->provider->id)]],
    ]);
}

function approvalPayload(Automation $automation, array $changes = []): array
{
    return [...[
        'name' => $automation->name,
        'trigger_type' => $automation->trigger_type->value,
        'trigger_config' => $automation->trigger_config,
        'allow_reentry' => (bool) $automation->allow_reentry,
        'definition' => $automation->definition,
    ], ...$changes];
}

/** Approve (as the owner) and start (as the editor) a draft automation. */
function approvalLive($test, ?Automation $automation = null): Automation
{
    $automation ??= approvalDraft($test);

    $test->actingAs($test->owner)->post(route('nudge.automations.approve', $automation))->assertRedirect();
    $test->actingAs($test->editor)->post(route('nudge.automations.activate', $automation))->assertSessionHasNoErrors();

    return $automation->fresh();
}

function approvalSubjectChange(Automation $automation, string $subject, int $providerId): array
{
    return approvalPayload($automation, ['definition' => ['steps' => [approvalEmail($subject, $providerId)]]]);
}

test('an unapproved automation cannot start', function () {
    $automation = approvalDraft($this);

    $this->actingAs($this->editor)->post(route('nudge.automations.activate', $automation))->assertSessionHasErrors('automation');

    expect($automation->fresh()->status)->toBe(AutomationStatus::Draft);
});

test('approval lets it start', function () {
    $automation = approvalLive($this);

    expect($automation->status)->toBe(AutomationStatus::Active)
        ->and($automation->approved_by)->toBe($this->owner->id)
        ->and($automation->approved_at)->not->toBeNull();
});

test('editors cannot approve', function () {
    $automation = approvalDraft($this);

    $this->actingAs($this->editor)->post(route('nudge.automations.approve', $automation))->assertForbidden();

    expect($automation->fresh()->approved_at)->toBeNull();
});

test('an editor changing the emails pauses a live approved automation and keeps the old snapshot', function () {
    $automation = approvalLive($this);

    $this->actingAs($this->editor)
        ->put(route('nudge.automations.update', $automation), approvalSubjectChange($automation, 'Changed', $this->provider->id))
        ->assertSessionHasNoErrors();

    $automation->refresh();
    $campaign = Campaign::query()->where('automation_id', $automation->id)->firstOrFail();

    expect($automation->status)->toBe(AutomationStatus::Paused)
        ->and($automation->approved_at)->toBeNull()
        ->and($campaign->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->snapshot->subject)->toBe('Hello');

    $this->actingAs($this->owner)->post(route('nudge.automations.approve', $automation))->assertRedirect();
    $this->actingAs($this->owner)->post(route('nudge.automations.activate', $automation))->assertSessionHasNoErrors();

    $campaign = Campaign::query()->where('automation_id', $automation->id)->firstOrFail();

    expect($automation->fresh()->status)->toBe(AutomationStatus::Active)
        ->and($campaign->snapshot->subject)->toBe('Changed');
});

test('an approver changing the emails keeps it live and re-stamps the approval', function () {
    $automation = approvalLive($this);
    $this->travel(1)->hours();

    $this->actingAs($this->owner)
        ->put(route('nudge.automations.update', $automation), approvalSubjectChange($automation, 'Changed', $this->provider->id))
        ->assertSessionHasNoErrors();

    $fresh = $automation->fresh();
    $campaign = Campaign::query()->where('automation_id', $automation->id)->firstOrFail();

    expect($fresh->status)->toBe(AutomationStatus::Active)
        ->and($fresh->approved_by)->toBe($this->owner->id)
        ->and($fresh->approved_at->gt($automation->approved_at))->toBeTrue()
        ->and($campaign->snapshot->subject)->toBe('Changed');
});

test('renaming leaves the fingerprint and approval alone', function () {
    $automation = approvalLive($this);
    $original = AutomationApproval::fingerprint($automation);

    expect(AutomationApproval::fingerprint((clone $automation)->fill(['name' => 'Other'])))->toBe($original);

    $this->actingAs($this->editor)
        ->put(route('nudge.automations.update', $automation), approvalPayload($automation, ['name' => 'Other']))
        ->assertSessionHasNoErrors();

    $fresh = $automation->fresh();

    expect($fresh->name)->toBe('Other')
        ->and($fresh->status)->toBe(AutomationStatus::Active)
        ->and($fresh->approved_at)->not->toBeNull();
});

test('structure changes change the fingerprint', function () {
    $condition = fn (array $yes, array $no = []) => [
        'id' => 'c1', 'type' => 'condition',
        'config' => ['definition' => ['match' => 'all', 'conditions' => [['type' => 'condition', 'field' => 'custom:plan', 'operator' => 'equals', 'value' => 'pro']]]],
        'yes' => $yes, 'no' => $no,
    ];
    $email = approvalEmail('Hello', $this->provider->id);
    $wait = ['id' => 'w1', 'type' => 'wait', 'config' => ['amount' => 2, 'unit' => 'days']];
    $webhook = ['id' => 'h1', 'type' => 'webhook', 'config' => ['url' => 'https://example.com/a']];

    $automation = approvalDraft($this, [$condition([$email])]);
    $original = AutomationApproval::fingerprint($automation);
    $with = fn (array $steps) => AutomationApproval::fingerprint((clone $automation)->fill(['definition' => ['steps' => $steps]]));

    expect($with([$email]))->not->toBe($original, 'condition removed')
        ->and($with([$condition([], [$email])]))->not->toBe($original, 'moved branch')
        ->and(AutomationApproval::fingerprint((clone $automation)->fill(['allow_reentry' => true])))->not->toBe($original, 'reentry')
        ->and($with([$condition([$email]), $webhook]))->not->toBe($original, 'webhook added')
        ->and($with([$condition([$email]), $webhook]))->not->toBe($with([$condition([$email]), [...$webhook, 'config' => ['url' => 'https://example.com/b']]]), 'webhook url')
        ->and($with([$condition([$email]), $wait]))->not->toBe($original, 'wait added')
        ->and($with([$condition([$email]), $wait]))->not->toBe($with([$condition([$email]), [...$wait, 'config' => ['amount' => 3, 'unit' => 'days']]]), 'wait amount')
        ->and($with([$wait, $webhook]))->not->toBe($with([$webhook, $wait]), 'reordered')
        ->and($with([$condition([$email]), ['id' => 'w1', 'type' => 'wait', 'config' => ['unit' => 'days', 'amount' => 2]]]))->toBe($with([$condition([$email]), $wait]), 'config key order');
});

test('an editor adding a webhook pauses a live approved automation', function () {
    $automation = approvalLive($this);
    $steps = [...$automation->definition['steps'], ['id' => 'h1', 'type' => 'webhook', 'config' => ['url' => 'https://example.com/a']]];

    $this->actingAs($this->editor)
        ->put(route('nudge.automations.update', $automation), approvalPayload($automation, ['definition' => ['steps' => $steps]]))
        ->assertSessionHasNoErrors();

    $fresh = $automation->fresh();

    expect($fresh->status)->toBe(AutomationStatus::Paused)
        ->and($fresh->approved_at)->toBeNull();
});

test('changes to what is sent or to whom change the fingerprint', function () {
    $automation = approvalDraft($this, triggerConfig: ['list_id' => 1, 'extra' => ['b' => 1, 'a' => 2]]);
    $original = AutomationApproval::fingerprint($automation);
    $email = $automation->definition['steps'][0];

    $withConfig = fn (array $changes) => (clone $automation)->fill(['definition' => ['steps' => [[...$email, 'config' => [...$email['config'], ...$changes]]]]]);

    foreach (['subject' => 'Other', 'from_email' => 'other@acme.test', 'html' => '<p>Other</p>', 'provider_id' => $this->provider->id + 1] as $field => $value) {
        expect(AutomationApproval::fingerprint($withConfig([$field => $value])))->not->toBe($original, $field);
    }

    $added = (clone $automation)->fill(['definition' => ['steps' => [$email, [...$email, 'id' => 'e2']]]]);
    $trigger = (clone $automation)->fill(['trigger_config' => ['list_id' => 2, 'extra' => ['b' => 1, 'a' => 2]]]);
    $reordered = (clone $automation)->fill(['trigger_config' => ['extra' => ['a' => 2, 'b' => 1], 'list_id' => 1]]);

    expect(AutomationApproval::fingerprint($added))->not->toBe($original)
        ->and(AutomationApproval::fingerprint($trigger))->not->toBe($original)
        ->and(AutomationApproval::fingerprint($reordered))->toBe($original);
});

test('without the workspace setting nothing changes', function () {
    $this->workspace->update(['requires_campaign_approval' => false]);
    $automation = approvalDraft($this);

    $this->actingAs($this->editor)->post(route('nudge.automations.activate', $automation))->assertSessionHasNoErrors();

    expect($automation->fresh()->status)->toBe(AutomationStatus::Active);

    $this->actingAs($this->editor)
        ->put(route('nudge.automations.update', $automation), approvalSubjectChange($automation, 'Changed', $this->provider->id))
        ->assertSessionHasNoErrors();

    expect($automation->fresh()->status)->toBe(AutomationStatus::Active);
});
