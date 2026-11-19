<?php

use Gerenuk\Nudge\Actions\Campaigns\ScheduleCampaign;
use Gerenuk\Nudge\Enums\AutomationRunStatus;
use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Enums\RecipientStatus;
use Gerenuk\Nudge\Jobs\AdvanceAutomationRun;
use Gerenuk\Nudge\Jobs\PickAbTestWinner;
use Gerenuk\Nudge\Jobs\RecoverStalledSends;
use Gerenuk\Nudge\Jobs\SendCampaignBatch;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\AutomationRun;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\CampaignRecipient;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Sending\ProviderTransports;
use Gerenuk\Nudge\Sending\ProviderType;
use Gerenuk\Nudge\Tests\Fixtures\CapturingTransports;
use Illuminate\Database\Eloquent\Builder;
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
});

function recoveryStart($test, int $contacts = 3, array $overrides = []): Campaign
{
    Contact::factory()->count($contacts)->create();
    $campaign = Campaign::factory()->create(['provider_id' => $test->provider->id, ...$overrides]);
    app(ScheduleCampaign::class)->handle($campaign, $test->workspace->fresh());
    $test->useWorkspace($test->workspace);

    return $campaign->fresh();
}

function recoverySweep($test): void
{
    (new RecoverStalledSends)();
    $test->useWorkspace($test->workspace);
}

function recoveryAge(Builder $query, int $minutes): void
{
    $query->update(['updated_at' => now()->subMinutes($minutes)]);
}

test('a recipient left sending by a crash is failed and the campaign finishes', function () {
    $campaign = recoveryStart($this, 2);
    Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Sending]);
    $recipient = CampaignRecipient::query()->first();
    CampaignRecipient::query()->whereKey($recipient->id)
        ->update(['status' => RecipientStatus::Sending, 'updated_at' => now()->subMinutes(60)]);
    $failedBefore = $campaign->fresh()->failed_count;

    recoverySweep($this);

    expect($recipient->fresh()->status)->toBe(RecipientStatus::Failed)
        ->and($recipient->fresh()->error)->not->toBeEmpty()
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sent)
        ->and($campaign->fresh()->failed_count)->toBe($failedBefore + 1);
});

test('a recipient claimed moments ago is left alone', function () {
    $campaign = recoveryStart($this, 2);
    Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Sending]);
    $recipient = CampaignRecipient::query()->first();
    CampaignRecipient::query()->whereKey($recipient->id)
        ->update(['status' => RecipientStatus::Sending, 'updated_at' => now()]);

    recoverySweep($this);

    expect($recipient->fresh()->status)->toBe(RecipientStatus::Sending)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sending);
});

test('a sending campaign with nothing left waiting is finished', function () {
    $campaign = recoveryStart($this, 2);
    Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Sending]);

    recoverySweep($this);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Sent);
});

test('a paused workspace does not burn batch attempts', function () {
    Queue::fake([SendCampaignBatch::class]);
    $campaign = recoveryStart($this, 3);
    $this->workspace->update(['sending_paused_at' => now(), 'sending_paused_reason' => 'Test']);

    $job = (new SendCampaignBatch($campaign->id, $this->workspace->id, CampaignRecipient::pluck('id')->all()))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $this->useWorkspace($this->workspace);

    $job->assertNotReleased();

    expect($this->transports->sent)->toBeEmpty()
        ->and(CampaignRecipient::where('status', RecipientStatus::Queued)->count())->toBe(3);
});

test('resuming re-queues campaigns that were sending', function () {
    Queue::fake([SendCampaignBatch::class]);
    recoveryStart($this, 3);
    $this->workspace->update(['sending_paused_at' => now(), 'sending_paused_reason' => 'Test']);

    $this->actingAs($this->owner)->post(route('nudge.sending.resume'));

    Queue::assertPushed(SendCampaignBatch::class, 2);
});

test('a sending campaign with no progress is queued again by the sweep, at most once per window', function () {
    Queue::fake([SendCampaignBatch::class]);
    recoveryStart($this, 3);
    recoveryAge(CampaignRecipient::query(), 180);

    recoverySweep($this);
    recoverySweep($this);

    Queue::assertPushed(SendCampaignBatch::class, 2);
});

test('the sweep leaves paused workspaces alone', function () {
    Queue::fake([SendCampaignBatch::class]);
    recoveryStart($this, 3);
    $this->workspace->update(['sending_paused_at' => now(), 'sending_paused_reason' => 'Test']);
    recoveryAge(CampaignRecipient::query(), 180);

    recoverySweep($this);

    Queue::assertPushed(SendCampaignBatch::class, 1);
});

test('an A/B winner job that died after choosing still releases the held recipients', function () {
    Queue::fake([PickAbTestWinner::class]);
    $campaign = recoveryStart($this, 20, [
        'subject' => 'Subject A', 'ab_enabled' => true, 'ab_subject_b' => 'Subject B',
        'ab_sample_percent' => 20, 'ab_wait_minutes' => 60,
    ]);
    Campaign::query()->whereKey($campaign->id)->update(['ab_winner' => 'a']);

    app()->call([new PickAbTestWinner($campaign->id, $this->workspace->id), 'handle']);
    $this->useWorkspace($this->workspace);

    expect(CampaignRecipient::where('status', RecipientStatus::Held)->count())->toBe(0)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sent);
});

test('a lost A/B winner job is dispatched again after its window', function () {
    Queue::fake([PickAbTestWinner::class]);
    $campaign = recoveryStart($this, 20, [
        'subject' => 'Subject A', 'ab_enabled' => true, 'ab_subject_b' => 'Subject B',
        'ab_sample_percent' => 20, 'ab_wait_minutes' => 60,
    ]);
    Campaign::query()->whereKey($campaign->id)->update(['sending_started_at' => now()->subMinutes(60 + 121)]);
    recoveryAge(CampaignRecipient::query(), 180);

    recoverySweep($this);

    Queue::assertPushed(PickAbTestWinner::class, 2);
});

test('a failed automation run is exited and a long-stuck active run is woken', function () {
    $automation = Automation::factory()->create(['name' => 'Welcome']);
    $contacts = Contact::factory()->count(2)->create();
    $run1 = AutomationRun::create(['automation_id' => $automation->id, 'contact_id' => $contacts[0]->id, 'status' => AutomationRunStatus::Active]);
    $run2 = AutomationRun::create(['automation_id' => $automation->id, 'contact_id' => $contacts[1]->id, 'status' => AutomationRunStatus::Active]);

    (new AdvanceAutomationRun($run1->id, $this->workspace->id))->failed(new RuntimeException('boom'));
    $this->useWorkspace($this->workspace);

    expect($run1->fresh()->status)->toBe(AutomationRunStatus::Exited)
        ->and($run1->fresh()->exit_reason)->toBe('Stopped after an error');

    recoveryAge(AutomationRun::query()->whereKey($run2->id), 180);
    recoverySweep($this);

    expect($run2->fresh()->status)->toBe(AutomationRunStatus::Waiting);
});
