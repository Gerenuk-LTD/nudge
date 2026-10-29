<?php

use Gerenuk\Marketing\Enums\AutomationTrigger;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\Segment;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
});

test('the field list shows how many contacts have a value and where each field is used', function () {
    CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);
    CustomFieldDefinition::create(['key' => 'birthday', 'label' => 'Birthday', 'type' => 'date']);
    Contact::factory()->create(['custom_fields' => ['plan' => 'pro']]);
    Contact::factory()->create(['custom_fields' => ['plan' => 'basic', 'birthday' => '1990-01-01']]);
    Contact::factory()->archived()->create(['custom_fields' => ['plan' => 'pro']]);
    Contact::factory()->create();
    $segment = Segment::create(['name' => 'Pro', 'definition' => ['match' => 'all', 'conditions' => [
        ['type' => 'group', 'match' => 'any', 'conditions' => [['type' => 'condition', 'field' => 'custom:plan', 'operator' => 'equals', 'value' => 'pro']]],
    ]]]);
    $automation = Automation::factory()->create([
        'name' => 'Birthdays',
        'trigger_type' => AutomationTrigger::DateField,
        'trigger_config' => ['field_key' => 'birthday'],
        'definition' => ['steps' => [['id' => 'set', 'type' => 'update_contact', 'config' => ['fields' => ['plan' => 'gift']]]]],
    ]);

    $this->actingAs($this->owner)->get(route('marketing.custom-fields.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activeContacts', 3)
            ->where('fields.0.key', 'birthday')
            ->where('fields.0.filled_count', 1)
            ->where('fields.0.used_by', [['type' => 'automation', 'id' => $automation->id, 'name' => 'Birthdays']])
            ->where('fields.1.filled_count', 2)
            ->where('fields.1.used_by', [
                ['type' => 'segment', 'id' => $segment->id, 'name' => 'Pro'],
                ['type' => 'automation', 'id' => $automation->id, 'name' => 'Birthdays'],
            ]));
});

test('a new field needs a well formed key that no other field uses', function () {
    CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);

    $this->actingAs($this->owner)->post(route('marketing.custom-fields.store'), ['label' => 'Plan', 'key' => 'plan', 'type' => 'text'])
        ->assertSessionHasErrors(['key' => 'Another field already uses this key.']);
    $this->actingAs($this->owner)->post(route('marketing.custom-fields.store'), ['label' => 'Size', 'key' => 'Company Size', 'type' => 'text'])
        ->assertSessionHasErrors(['key' => 'Use lowercase letters, numbers and underscores, starting with a letter.']);
    $this->actingAs($this->owner)->post(route('marketing.custom-fields.store'), ['label' => 'Size', 'key' => 'company_size', 'type' => 'number'])
        ->assertSessionHasNoErrors();

    expect(CustomFieldDefinition::pluck('key')->sort()->values()->all())->toBe(['company_size', 'plan']);
});
