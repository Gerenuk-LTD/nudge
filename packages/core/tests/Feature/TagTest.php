<?php

use Gerenuk\Marketing\Enums\AutomationTrigger;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Models\Tag;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
});

/**
 * An automation started by one tag that adds another by name, inside a condition branch that checks the first.
 */
function automationUsing(Tag $trigger, string $adds): Automation
{
    return Automation::factory()->create([
        'name' => 'Welcome VIPs',
        'trigger_type' => AutomationTrigger::TagAdded,
        'trigger_config' => ['tag_id' => $trigger->id],
        'definition' => ['steps' => [[
            'id' => 'check',
            'type' => 'condition',
            'config' => ['definition' => ['match' => 'all', 'conditions' => [['type' => 'condition', 'field' => 'tag', 'operator' => 'has', 'value' => $trigger->id]]]],
            'yes' => [['id' => 'tag', 'type' => 'update_contact', 'config' => ['add_tags' => [$adds]]]],
            'no' => [],
        ]]],
    ]);
}

test('tags can be created, and names stay unique', function () {
    $this->actingAs($this->owner)->post(route('marketing.tags.store'), ['name' => 'vip'])->assertRedirect();
    $this->actingAs($this->owner)->post(route('marketing.tags.store'), ['name' => 'vip'])->assertSessionHasErrors('name');

    expect(Tag::pluck('name')->all())->toBe(['vip']);
});

test('the tag list shows which segments and automations use each tag', function () {
    $vip = Tag::create(['name' => 'vip']);
    $welcomed = Tag::create(['name' => 'welcomed']);
    Tag::create(['name' => 'unused']);
    Segment::create(['name' => 'VIPs', 'definition' => ['match' => 'all', 'conditions' => [['type' => 'condition', 'field' => 'tag', 'operator' => 'has', 'value' => $vip->id]]]]);
    automationUsing($vip, 'Welcomed');

    $this->actingAs($this->owner)->get(route('marketing.tags.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tags.0.name', 'unused')
            ->where('tags.0.used_by', [])
            ->where('tags.1.used_by', [
                ['type' => 'segment', 'id' => Segment::first()->id, 'name' => 'VIPs'],
                ['type' => 'automation', 'id' => Automation::first()->id, 'name' => 'Welcome VIPs'],
            ])
            ->where('tags.2.used_by.0.type', 'automation'));
});

test('renaming a tag keeps automations that add it by name working', function () {
    $vip = Tag::create(['name' => 'vip']);
    $welcomed = Tag::create(['name' => 'welcomed']);
    $automation = automationUsing($vip, 'Welcomed');

    $this->actingAs($this->owner)->put(route('marketing.tags.update', $welcomed), ['name' => 'onboarded'])->assertRedirect();

    expect($automation->refresh()->definition['steps'][0]['yes'][0]['config']['add_tags'])->toBe(['onboarded']);
});

test('merging a tag moves its contacts and references, then deletes it', function () {
    $duplicate = Tag::create(['name' => 'VIP']);
    $vip = Tag::create(['name' => 'vip-customer']);
    [$both, $onlyDuplicate] = Contact::factory()->count(2)->create();
    $duplicate->contacts()->attach([$both->id, $onlyDuplicate->id]);
    $vip->contacts()->attach($both);
    $segment = Segment::create(['name' => 'VIPs', 'definition' => ['match' => 'all', 'conditions' => [['type' => 'condition', 'field' => 'tag', 'operator' => 'has', 'value' => $duplicate->id]]]]);
    $automation = automationUsing($duplicate, 'VIP');

    $this->actingAs($this->owner)->post(route('marketing.tags.merge', $duplicate), ['into' => $vip->id])->assertRedirect();

    $automation->refresh();
    expect(Tag::pluck('name')->all())->toBe(['vip-customer'])
        ->and($vip->contacts()->pluck('contacts.id')->sort()->values()->all())->toBe([$both->id, $onlyDuplicate->id])
        ->and($segment->refresh()->definition['conditions'][0]['value'])->toBe($vip->id)
        ->and($automation->trigger_config['tag_id'])->toBe($vip->id)
        ->and($automation->definition['steps'][0]['config']['definition']['conditions'][0]['value'])->toBe($vip->id)
        ->and($automation->definition['steps'][0]['yes'][0]['config']['add_tags'])->toBe(['vip-customer']);
});

test('a tag cannot be merged into itself or a tag from another workspace', function () {
    $vip = Tag::create(['name' => 'vip']);
    $this->createOwner('Globex');
    $foreign = Tag::create(['name' => 'vip']);

    $this->actingAs($this->owner)->post(route('marketing.tags.merge', $vip), ['into' => $vip->id])->assertSessionHasErrors('into');
    $this->actingAs($this->owner)->post(route('marketing.tags.merge', $vip), ['into' => $foreign->id])->assertSessionHasErrors('into');

    expect(Tag::withoutGlobalScopes()->count())->toBe(2);
});

test('tags given to a contact join existing tags whatever their capitals', function () {
    $vip = Tag::create(['name' => 'vip']);

    $this->actingAs($this->owner)->post(route('marketing.contacts.store'), [
        'email' => 'jane@example.com',
        'status' => 'subscribed',
        'tags' => ['VIP', 'Lead'],
    ])->assertRedirect();

    expect(Tag::orderBy('name')->pluck('name')->all())->toBe(['Lead', 'vip'])
        ->and(Contact::first()->tags()->pluck('tags.id'))->toContain($vip->id);
});
