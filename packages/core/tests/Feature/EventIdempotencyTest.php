<?php

use Gerenuk\Marketing\Actions\Automations\ActivateAutomation;
use Gerenuk\Marketing\Actions\Workspaces\CreateApiToken;
use Gerenuk\Marketing\Enums\AutomationTrigger;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Jobs\StartAutomationsForTrigger;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\AutomationRun;
use Gerenuk\Marketing\Models\Contact;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
    $this->token = eventToken($this->workspace, $this->owner);
});

function eventToken($workspace, $user): string
{
    return app(CreateApiToken::class)->handle($workspace, $user, 'Test', ['contacts.view', 'contacts.manage'])['plainToken'];
}

function postEvent($test, array $body = [], ?string $key = null, ?string $token = null)
{
    $request = $test->withToken($token ?? $test->token);

    if ($key !== null) {
        $request = $request->withHeader('Idempotency-Key', $key);
    }

    return $request->postJson(route('marketing.api.events.store'), ['email' => 'ann@example.com', 'event' => 'ping', 'create_contact' => true, ...$body]);
}

function customEventCount(): int
{
    return ActivityEvent::query()->where('type', EventType::Custom)->count();
}

function completingAutomation($test): Automation
{
    $automation = Automation::create([
        'name' => 'Ping',
        'trigger_type' => AutomationTrigger::ApiEvent,
        'trigger_config' => ['event' => 'ping'],
        'definition' => ['steps' => [['id' => 'x', 'type' => 'exit', 'config' => []]]],
        'allow_reentry' => true,
    ]);

    app(ActivateAutomation::class)->handle($automation, $test->workspace->fresh());

    return $automation->fresh();
}

test('a repeat with the same key returns the same event and adds no row', function () {
    $first = postEvent($this, key: 'key-1')->assertAccepted();
    $second = postEvent($this, key: 'key-1')->assertAccepted();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('data.event'))->toBe('ping')
        ->and($second->json('data.email'))->toBe('ann@example.com')
        ->and(customEventCount())->toBe(1);
});

test('a repeat with the same key does not enrol again', function () {
    completingAutomation($this);

    postEvent($this, key: 'key-2')->assertAccepted();
    postEvent($this, key: 'key-2')->assertAccepted();

    expect(AutomationRun::count())->toBe(1);
});

test('without a key nothing changes', function () {
    $first = postEvent($this)->assertAccepted();
    $second = postEvent($this)->assertAccepted();

    expect($second->json('data.id'))->not->toBe($first->json('data.id'))
        ->and(customEventCount())->toBe(2);
});

test('the same key in two workspaces gives two events', function () {
    $first = postEvent($this, key: 'shared')->assertAccepted();

    [$otherOwner, $otherWorkspace] = $this->createOwner('Other');
    $otherToken = eventToken($otherWorkspace, $otherOwner);
    $second = postEvent($this, key: 'shared', token: $otherToken)->assertAccepted();

    expect($second->json('data.id'))->not->toBe($first->json('data.id'))
        ->and(ActivityEvent::withoutGlobalScopes()->where('idempotency_key', 'shared')->count())->toBe(2);
});

test('a repeat after a failed dispatch dispatches again', function () {
    $contact = Contact::factory()->create(['email' => 'ann@example.com']);
    $row = ActivityEvent::create([
        'contact_id' => $contact->id,
        'type' => EventType::Custom,
        'data' => ['name' => 'ping', 'properties' => []],
        'idempotency_key' => 'key-5',
        'occurred_at' => now(),
    ]);

    Queue::fake();
    postEvent($this, key: 'key-5')->assertAccepted()->assertJsonPath('data.id', $row->id);

    Queue::assertPushed(StartAutomationsForTrigger::class, fn ($job) => $job->eventId === $row->id);
    expect(customEventCount())->toBe(1);
});

test('a job run twice for one event enrols once', function () {
    completingAutomation($this);
    $contact = Contact::factory()->create(['email' => 'ann@example.com']);
    $event = ActivityEvent::create([
        'contact_id' => $contact->id,
        'type' => EventType::Custom,
        'data' => ['name' => 'ping', 'properties' => []],
        'idempotency_key' => 'key-6',
        'occurred_at' => now(),
    ]);

    $job = fn () => new StartAutomationsForTrigger($this->workspace->id, $contact->id, AutomationTrigger::ApiEvent, 'ping', ['event' => 'ping'], $event->id);

    app()->call([$job(), 'handle']);
    expect(AutomationRun::count())->toBe(1)->and($event->fresh()->triggered_at)->not->toBeNull();

    app()->call([$job(), 'handle']);
    expect(AutomationRun::count())->toBe(1);
});

test('a bad key is refused', function () {
    foreach (['', str_repeat('a', 256), 'has space'] as $key) {
        $request = $this->withToken($this->token)->withHeader('Idempotency-Key', $key);
        $request->postJson(route('marketing.api.events.store'), ['email' => 'ann@example.com', 'event' => 'ping', 'create_contact' => true])
            ->assertStatus(422);
    }

    expect(customEventCount())->toBe(0);
});

test('create_contact with a repeat creates nothing new', function () {
    postEvent($this, key: 'key-8')->assertAccepted();
    postEvent($this, key: 'key-8')->assertAccepted();

    expect(Contact::count())->toBe(1)->and(customEventCount())->toBe(1);
});
