<?php

use Gerenuk\Nudge\Enums\AutomationStatus;
use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\ContactList;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Models\Role;
use Gerenuk\Nudge\Sending\ProviderTransports;
use Gerenuk\Nudge\Sending\ProviderType;
use Gerenuk\Nudge\Tests\Fixtures\CapturingTransports;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
    $this->workspace->update(['postal_address' => '1 High Street']);

    $this->app->instance(ProviderTransports::class, new CapturingTransports);

    $this->provider = Provider::create([
        'name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 100,
        'credentials' => ['host' => 'smtp.test', 'port' => '587', 'encryption' => 'starttls'],
    ]);

    $this->list = ContactList::factory()->create();

    $count = 0;
    $this->automationsOnlyMember = function (array $extra = []) use (&$count) {
        $role = Role::create([
            'workspace_id' => $this->workspace->id, 'name' => 'Automations only '.++$count,
            'permissions' => ['automations.view', 'automations.manage', ...$extra], 'is_owner' => false,
        ]);

        return $this->addMember($this->workspace, $role->name);
    };
});

function stepsDraft($test, array $steps): Automation
{
    return Automation::create([
        'name' => 'Flow',
        'trigger_type' => AutomationTrigger::ListJoined,
        'trigger_config' => ['list_id' => $test->list->id],
        'definition' => ['steps' => $steps],
    ]);
}

function stepsEmail(string $subject, int $providerId): array
{
    return [
        'id' => 'e1', 'type' => 'send_email',
        'config' => ['subject' => $subject, 'from_name' => 'Acme', 'from_email' => 'hi@acme.test', 'provider_id' => $providerId, 'html' => "<p>{$subject}</p>"],
    ];
}

function stepsPayload(Automation $automation, array $changes = []): array
{
    return [...[
        'name' => $automation->name,
        'trigger_type' => $automation->trigger_type->value,
        'trigger_config' => $automation->trigger_config,
        'allow_reentry' => (bool) $automation->allow_reentry,
        'definition' => $automation->definition,
    ], ...$changes];
}

const WEBHOOK_STEP = ['id' => 'h1', 'type' => 'webhook', 'config' => ['url' => 'https://example.com/a']];
const UPDATE_STEP = ['id' => 'u1', 'type' => 'update_contact', 'config' => ['add_tags' => ['VIP']]];

test('a member without contacts.view cannot save a webhook step', function () {
    $member = ($this->automationsOnlyMember)();
    $automation = stepsDraft($this, [stepsEmail('Hello', $this->provider->id)]);

    $this->actingAs($member)
        ->put(route('nudge.automations.update', $automation), stepsPayload($automation, ['definition' => ['steps' => [WEBHOOK_STEP]]]))
        ->assertSessionHasErrors('definition');

    expect($automation->fresh()->definition['steps'][0]['type'])->toBe('send_email');
});

test('a member without contacts.manage cannot save an update contact step', function () {
    $member = ($this->automationsOnlyMember)();
    $automation = stepsDraft($this, [stepsEmail('Hello', $this->provider->id)]);

    $this->actingAs($member)
        ->put(route('nudge.automations.update', $automation), stepsPayload($automation, ['definition' => ['steps' => [UPDATE_STEP]]]))
        ->assertSessionHasErrors('definition');

    expect($automation->fresh()->definition['steps'][0]['type'])->toBe('send_email');
});

test('an email-only definition saves without contact permissions', function () {
    $member = ($this->automationsOnlyMember)();
    $automation = stepsDraft($this, [stepsEmail('Hello', $this->provider->id)]);

    $this->actingAs($member)
        ->put(route('nudge.automations.update', $automation), stepsPayload($automation, ['definition' => ['steps' => [stepsEmail('Changed', $this->provider->id)]]]))
        ->assertSessionHasNoErrors();

    expect($automation->fresh()->definition['steps'][0]['config']['subject'])->toBe('Changed');
});

test('a member without contacts.view cannot start an automation with a webhook step', function () {
    $member = ($this->automationsOnlyMember)();
    $automation = stepsDraft($this, [stepsEmail('Hello', $this->provider->id), WEBHOOK_STEP]);

    $this->actingAs($member)
        ->post(route('nudge.automations.activate', $automation))
        ->assertSessionHasErrors('definition');

    expect($automation->fresh()->status)->toBe(AutomationStatus::Draft);
});

test('the matching contact permission allows the step', function () {
    $automation = stepsDraft($this, [stepsEmail('Hello', $this->provider->id)]);

    $viewer = ($this->automationsOnlyMember)(['contacts.view']);
    $this->actingAs($viewer)
        ->put(route('nudge.automations.update', $automation), stepsPayload($automation, ['definition' => ['steps' => [WEBHOOK_STEP]]]))
        ->assertSessionHasNoErrors();

    $manager = ($this->automationsOnlyMember)(['contacts.manage']);
    $this->actingAs($manager)
        ->put(route('nudge.automations.update', $automation), stepsPayload($automation, ['definition' => ['steps' => [UPDATE_STEP]]]))
        ->assertSessionHasNoErrors();

    expect($automation->fresh()->definition['steps'][0]['type'])->toBe('update_contact');
});
