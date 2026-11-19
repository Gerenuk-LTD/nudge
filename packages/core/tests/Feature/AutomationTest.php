<?php

use Gerenuk\Nudge\Actions\Automations\ActivateAutomation;
use Gerenuk\Nudge\Actions\Contacts\UpsertContact;
use Gerenuk\Nudge\Actions\Workspaces\CreateApiToken;
use Gerenuk\Nudge\Automations\AutomationDefinition;
use Gerenuk\Nudge\Automations\WebhookCaller;
use Gerenuk\Nudge\Enums\AutomationRunStatus;
use Gerenuk\Nudge\Enums\AutomationStatus;
use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Enums\ContactSource;
use Gerenuk\Nudge\Enums\ContactStatus;
use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Jobs\AdvanceAutomationRun;
use Gerenuk\Nudge\Jobs\AdvanceDueAutomationRuns;
use Gerenuk\Nudge\Jobs\EnrollScheduledTriggers;
use Gerenuk\Nudge\Models\ActivityEvent;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\AutomationRun;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\ContactList;
use Gerenuk\Nudge\Models\CustomFieldDefinition;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Models\Tag;
use Gerenuk\Nudge\Sending\ProviderTransports;
use Gerenuk\Nudge\Sending\ProviderType;
use Gerenuk\Nudge\Support\ContactData;
use Gerenuk\Nudge\Tests\Fixtures\CapturingTransports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
    $this->workspace->update(['postal_address' => '1 High Street']);

    $this->transports = new CapturingTransports;
    $this->app->instance(ProviderTransports::class, $this->transports);

    $this->provider = Provider::create([
        'name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 100,
        'credentials' => ['host' => 'smtp.test', 'port' => '587', 'encryption' => 'starttls'],
    ]);
});

function emailStep(string $id, string $subject, int $providerId): array
{
    return ['id' => $id, 'type' => 'send_email', 'config' => [
        'subject' => $subject, 'from_name' => 'Acme', 'from_email' => 'hi@acme.test', 'provider_id' => $providerId,
        'html' => "<p>{$subject} {{ first_name }}</p><a href=\"https://acme.test/start\">Start</a>",
    ]];
}

function liveAutomation($test, AutomationTrigger $trigger, array $triggerConfig, array $steps, bool $reentry = false): Automation
{
    $automation = Automation::create([
        'name' => 'Welcome',
        'trigger_type' => $trigger,
        'trigger_config' => $triggerConfig,
        'definition' => ['steps' => $steps],
        'allow_reentry' => $reentry,
    ]);

    app(ActivateAutomation::class)->handle($automation, $test->workspace->fresh());

    return $automation->fresh();
}

function apiEvent($test, string $email, string $event): void
{
    $token = app(CreateApiToken::class)->handle($test->workspace, $test->owner, 'App', ['contacts.view', 'contacts.manage'])['plainToken'];

    $test->withToken($token)->postJson(route('nudge.api.events.store'), ['email' => $email, 'event' => $event, 'create_contact' => true])->assertAccepted();
    $test->useWorkspace($test->workspace);
}

test('the definition index finds the next step, including out of branches', function () {
    $definition = AutomationDefinition::from(['steps' => [
        ['id' => 'a', 'type' => 'wait'],
        ['id' => 'c', 'type' => 'condition', 'yes' => [['id' => 'y1', 'type' => 'wait'], ['id' => 'y2', 'type' => 'wait']], 'no' => []],
        ['id' => 'z', 'type' => 'exit'],
    ]]);

    expect($definition->first())->toBe('a')
        ->and($definition->next('a'))->toBe('c')
        ->and($definition->branch('c', true))->toBe('y1')
        ->and($definition->next('y2'))->toBe('z')
        ->and($definition->branch('c', false))->toBe('z')
        ->and($definition->next('z'))->toBeNull();
});

test('a welcome series sends, waits, then sends again', function () {
    $list = ContactList::factory()->create();
    $automation = liveAutomation($this, AutomationTrigger::ListJoined, ['list_id' => $list->id], [
        emailStep('e1', 'Welcome', $this->provider->id),
        ['id' => 'w1', 'type' => 'wait', 'config' => ['amount' => 2, 'unit' => 'days']],
        emailStep('e2', 'Tips', $this->provider->id),
    ]);

    app(UpsertContact::class)->handle(ContactData::fromValidated(['email' => 'ann@example.com', 'first_name' => 'Ann', 'lists' => [$list->id]]), ContactSource::Api);
    $this->useWorkspace($this->workspace);

    $run = AutomationRun::first();
    expect($this->transports->sent)->toHaveCount(1)
        ->and($this->transports->sent[0]->getSubject())->toBe('Welcome')
        ->and((string) $this->transports->sent[0]->getHtmlBody())->toContain('/t/c/')
        ->and($run->status)->toBe(AutomationRunStatus::Waiting)
        ->and($run->current_step_id)->toBe('e2');

    $this->travel(2)->days();
    $this->travel(1)->minutes();
    (new AdvanceDueAutomationRuns)();
    $this->useWorkspace($this->workspace);

    expect($this->transports->sent)->toHaveCount(2)
        ->and($this->transports->sent[1]->getSubject())->toBe('Tips')
        ->and($run->fresh()->status)->toBe(AutomationRunStatus::Completed)
        ->and($automation->fresh()->completed_count)->toBe(1)
        ->and(Campaign::where('automation_step_id', 'e1')->value('sent_count'))->toBe(1);
});

test('conditions branch on the contact', function () {
    CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);
    liveAutomation($this, AutomationTrigger::ApiEvent, ['event' => 'signed_up'], [
        ['id' => 'c1', 'type' => 'condition', 'config' => ['definition' => ['match' => 'all', 'conditions' => [
            ['type' => 'condition', 'field' => 'custom:plan', 'operator' => 'equals', 'value' => 'pro'],
        ]]], 'yes' => [emailStep('pro', 'Pro tips', $this->provider->id)], 'no' => [emailStep('free', 'Upgrade', $this->provider->id)]],
    ]);

    app(UpsertContact::class)->handle(ContactData::fromValidated(['email' => 'pro@example.com', 'custom_fields' => ['plan' => 'pro']]), ContactSource::Api);
    apiEvent($this, 'pro@example.com', 'Signed_Up');
    apiEvent($this, 'free@example.com', 'signed_up');

    expect(array_map(fn ($email) => $email->getSubject(), $this->transports->sent))->toBe(['Pro tips', 'Upgrade']);
});

test('update steps change tags, lists, fields and can unsubscribe', function () {
    $list = ContactList::factory()->create();
    liveAutomation($this, AutomationTrigger::ApiEvent, ['event' => 'churned'], [
        ['id' => 'u1', 'type' => 'update_contact', 'config' => ['add_tags' => ['Churned'], 'add_list_ids' => [$list->id], 'fields' => ['reason' => 'price'], 'unsubscribe' => true]],
    ]);

    apiEvent($this, 'ann@example.com', 'churned');

    $contact = Contact::first();
    expect($contact->tags()->pluck('name')->all())->toBe(['Churned'])
        ->and($contact->lists()->count())->toBe(1)
        ->and($contact->custom_fields)->toBe(['reason' => 'price'])
        ->and($contact->status)->toBe(ContactStatus::Unsubscribed);
});

test('a tag added by one automation can start another', function () {
    $vip = Tag::create(['name' => 'VIP']);
    liveAutomation($this, AutomationTrigger::ApiEvent, ['event' => 'big_order'], [
        ['id' => 'u1', 'type' => 'update_contact', 'config' => ['add_tags' => ['VIP']]],
    ]);
    $second = liveAutomation($this, AutomationTrigger::TagAdded, ['tag_id' => $vip->id], [emailStep('e1', 'Welcome to VIP', $this->provider->id)]);

    apiEvent($this, 'ann@example.com', 'big_order');

    expect($second->fresh()->entered_count)->toBe(1)
        ->and($this->transports->sent[0]->getSubject())->toBe('Welcome to VIP');
});

test('contacts enter once unless re-entry is allowed, and never twice at the same time', function () {
    $once = liveAutomation($this, AutomationTrigger::ApiEvent, ['event' => 'ping'], [['id' => 'w', 'type' => 'wait', 'config' => ['amount' => 1, 'unit' => 'days']]]);
    $again = liveAutomation($this, AutomationTrigger::ApiEvent, ['event' => 'ping'], [['id' => 'x', 'type' => 'exit', 'config' => []]], reentry: true);

    apiEvent($this, 'ann@example.com', 'ping');
    apiEvent($this, 'ann@example.com', 'ping');

    expect($once->fresh()->entered_count)->toBe(1)
        ->and($again->fresh()->entered_count)->toBe(2);
});

test('a paused automation holds contacts and takes no new ones', function () {
    $automation = liveAutomation($this, AutomationTrigger::ApiEvent, ['event' => 'ping'], [
        ['id' => 'w', 'type' => 'wait', 'config' => ['amount' => 1, 'unit' => 'hours']],
        emailStep('e1', 'After the wait', $this->provider->id),
    ]);
    apiEvent($this, 'ann@example.com', 'ping');

    $this->actingAs($this->owner)->post(route('nudge.automations.pause', $automation));
    apiEvent($this, 'bob@example.com', 'ping');
    $this->travel(2)->hours();
    (new AdvanceDueAutomationRuns)();
    $this->useWorkspace($this->workspace);

    expect($automation->fresh()->entered_count)->toBe(1)->and($this->transports->sent)->toBe([]);

    $this->actingAs($this->owner)->post(route('nudge.automations.activate', $automation))->assertSessionHasNoErrors();
    (new AdvanceDueAutomationRuns)();
    $this->useWorkspace($this->workspace);

    expect($this->transports->recipients())->toBe(['ann@example.com']);
});

test('removing the step a contact is waiting on moves them out', function () {
    $automation = liveAutomation($this, AutomationTrigger::ApiEvent, ['event' => 'ping'], [
        ['id' => 'w', 'type' => 'wait', 'config' => ['amount' => 1, 'unit' => 'hours']],
        emailStep('e1', 'Later', $this->provider->id),
    ]);
    apiEvent($this, 'ann@example.com', 'ping');

    $automation->update(['definition' => ['steps' => [['id' => 'w', 'type' => 'wait', 'config' => ['amount' => 1, 'unit' => 'hours']]]]]);
    $this->travel(2)->hours();
    (new AdvanceDueAutomationRuns)();
    $this->useWorkspace($this->workspace);

    expect(AutomationRun::first()->status)->toBe(AutomationRunStatus::Exited);
});

test('date-field automations start on the anniversary, once a year', function () {
    CustomFieldDefinition::create(['key' => 'birthday', 'label' => 'Birthday', 'type' => 'date']);
    $automation = liveAutomation($this, AutomationTrigger::DateField, ['field_key' => 'birthday', 'offset_days' => 0, 'repeat' => 'yearly'], [emailStep('e1', 'Happy birthday', $this->provider->id)]);

    app(UpsertContact::class)->handle(ContactData::fromValidated(['email' => 'today@example.com', 'custom_fields' => ['birthday' => now()->subYears(30)->toDateString()]]), ContactSource::Api);
    app(UpsertContact::class)->handle(ContactData::fromValidated(['email' => 'other@example.com', 'custom_fields' => ['birthday' => now()->addDays(3)->subYears(30)->toDateString()]]), ContactSource::Api);

    app()->call([new EnrollScheduledTriggers([AutomationTrigger::DateField]), 'handle']);
    app()->call([new EnrollScheduledTriggers([AutomationTrigger::DateField]), 'handle']);
    $this->useWorkspace($this->workspace);

    expect($this->transports->recipients())->toBe(['today@example.com'])->and($automation->fresh()->entered_count)->toBe(1);
});

test('segment automations only take people who join after it starts, unless told otherwise', function () {
    CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);
    $segment = Segment::create(['name' => 'Pro', 'definition' => ['match' => 'all', 'conditions' => [['type' => 'condition', 'field' => 'custom:plan', 'operator' => 'equals', 'value' => 'pro']]]]);
    app(UpsertContact::class)->handle(ContactData::fromValidated(['email' => 'early@example.com', 'custom_fields' => ['plan' => 'pro']]), ContactSource::Api);

    $automation = liveAutomation($this, AutomationTrigger::SegmentEntered, ['segment_id' => $segment->id], [emailStep('e1', 'Welcome to Pro', $this->provider->id)]);
    app(UpsertContact::class)->handle(ContactData::fromValidated(['email' => 'late@example.com', 'custom_fields' => ['plan' => 'pro']]), ContactSource::Api);

    app()->call([new EnrollScheduledTriggers([AutomationTrigger::SegmentEntered]), 'handle']);
    $this->useWorkspace($this->workspace);

    expect($this->transports->recipients())->toBe(['late@example.com'])->and($automation->fresh()->entered_count)->toBe(1);
});

test('re-engagement picks up contacts who were emailed but stopped opening or clicking', function () {
    $automation = liveAutomation($this, AutomationTrigger::Inactivity, ['days' => 30], [emailStep('e1', 'We miss you', $this->provider->id)]);

    $quiet = Contact::factory()->create(['email' => 'quiet@example.com', 'created_at' => now()->subDays(90)]);
    $engaged = Contact::factory()->create(['email' => 'engaged@example.com', 'created_at' => now()->subDays(90)]);
    Contact::factory()->create(['email' => 'new@example.com']);

    foreach ([$quiet, $engaged] as $contact) {
        ActivityEvent::create(['contact_id' => $contact->id, 'type' => EventType::Sent, 'occurred_at' => now()->subDays(5)]);
    }
    ActivityEvent::create(['contact_id' => $engaged->id, 'type' => EventType::Clicked, 'occurred_at' => now()->subDays(4)]);

    app()->call([new EnrollScheduledTriggers([AutomationTrigger::Inactivity]), 'handle']);
    $this->useWorkspace($this->workspace);

    expect($this->transports->recipients())->toBe(['quiet@example.com']);
});

test('an automation cannot start until its trigger and emails are complete', function () {
    $automation = Automation::create([
        'name' => 'Incomplete', 'trigger_type' => AutomationTrigger::ListJoined, 'trigger_config' => [],
        'definition' => ['steps' => [['id' => 'e1', 'type' => 'send_email', 'config' => ['subject' => 'Hi']]]],
    ]);

    app(ActivateAutomation::class)->handle($automation, $this->workspace->fresh());
})->throws(ValidationException::class);

test('the builder rejects malformed definitions', function () {
    $automation = Automation::factory()->create();

    $this->actingAs($this->owner)->put(route('nudge.automations.update', $automation), [
        'name' => 'X', 'trigger_type' => 'api_event', 'trigger_config' => ['event' => 'x'], 'allow_reentry' => false,
        'definition' => ['steps' => [['id' => 'a', 'type' => 'wait', 'config' => ['amount' => 0, 'unit' => 'days']], ['id' => 'a', 'type' => 'exit']]],
    ])->assertSessionHasErrors(['definition.steps.0', 'definition.steps.1']);
});

test('webhook steps refuse private addresses', function () {
    Http::fake();

    expect(fn () => app(WebhookCaller::class)->post('https://127.0.0.1/hook', []))->toThrow(RuntimeException::class)
        ->and(fn () => app(WebhookCaller::class)->post('http://example.com/hook', []))->toThrow(RuntimeException::class)
        ->and(fn () => app(WebhookCaller::class)->post('https://[::1]/hook', []))->toThrow(RuntimeException::class)
        ->and(fn () => app(WebhookCaller::class)->post('https://[fd00::1]/hook', []))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

test('webhook steps still post to a public address', function () {
    Http::fake(['*' => Http::response([], 202)]);

    expect(app(WebhookCaller::class)->post('https://8.8.8.8/hook', []))->toBe(202);

    Http::assertSentCount(1);
});

test('custom events land on the contact timeline', function () {
    apiEvent($this, 'ann@example.com', 'order_placed');
    $contact = Contact::first();

    $this->actingAs($this->owner)->get(route('nudge.contacts.show', $contact))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload->where('timeline.0.title', 'Event: order_placed')));
});

test('a contact queued just before a pause waits and carries on when resumed', function () {
    Queue::fake([AdvanceAutomationRun::class]);
    $automation = liveAutomation($this, AutomationTrigger::ApiEvent, ['event' => 'ping'], [emailStep('e1', 'Hello', $this->provider->id)]);
    apiEvent($this, 'ann@example.com', 'ping');
    $run = AutomationRun::first();

    $automation->update(['status' => AutomationStatus::Paused]);
    app()->call([new AdvanceAutomationRun($run->id, $this->workspace->id), 'handle']);
    $this->useWorkspace($this->workspace);

    expect($run->fresh()->status)->toBe(AutomationRunStatus::Waiting)->and($this->transports->sent)->toBe([]);
});

test('list automations start only after the contact is saved', function () {
    $list = ContactList::factory()->create();
    liveAutomation($this, AutomationTrigger::ListJoined, ['list_id' => $list->id], [
        emailStep('e1', 'Welcome', $this->provider->id),
    ]);

    DB::transaction(function () use ($list) {
        app(UpsertContact::class)->handle(ContactData::fromValidated(['email' => 'new@example.com', 'lists' => [$list->id]]), ContactSource::Api);
        $this->useWorkspace($this->workspace);

        expect(AutomationRun::count())->toBe(0);
    });

    $this->useWorkspace($this->workspace);

    expect(AutomationRun::count())->toBe(1);
});

test('triggers from a rolled back save are dropped', function () {
    $list = ContactList::factory()->create();
    liveAutomation($this, AutomationTrigger::ListJoined, ['list_id' => $list->id], [
        emailStep('e1', 'Welcome', $this->provider->id),
    ]);

    try {
        DB::transaction(function () use ($list) {
            app(UpsertContact::class)->handle(ContactData::fromValidated(['email' => 'gone@example.com', 'lists' => [$list->id]]), ContactSource::Api);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    $this->useWorkspace($this->workspace);

    expect(AutomationRun::count())->toBe(0)
        ->and(Contact::count())->toBe(0);
});

test('automation emails cannot be deleted or cancelled as campaigns', function () {
    liveAutomation($this, AutomationTrigger::ApiEvent, ['event' => 'signed_up'], [emailStep('e1', 'Welcome', $this->provider->id)]);
    $stepCampaign = Campaign::where('automation_step_id', 'e1')->firstOrFail();

    $this->actingAs($this->owner)->delete(route('nudge.campaigns.destroy', $stepCampaign))->assertNotFound();
    $this->actingAs($this->owner)->post(route('nudge.campaigns.cancel', $stepCampaign))->assertNotFound();
    $this->actingAs($this->owner)->post(route('nudge.campaigns.unschedule', $stepCampaign))->assertNotFound();

    expect($stepCampaign->fresh())->not->toBeNull()
        ->and($stepCampaign->fresh()->status)->toBe($stepCampaign->status);
});
