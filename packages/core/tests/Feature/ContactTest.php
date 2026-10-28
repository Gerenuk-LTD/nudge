<?php

use Gerenuk\Marketing\Actions\Contacts\UpsertContact;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Support\ContactData;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
});

test('contacts are unique per workspace by lowercased email', function () {
    $this->actingAs($this->owner)->post(route('marketing.contacts.store'), [
        'email' => ' Jane@Example.COM ',
        'status' => 'subscribed',
    ])->assertRedirect();

    $this->actingAs($this->owner)->post(route('marketing.contacts.store'), [
        'email' => 'jane@example.com',
        'status' => 'subscribed',
    ])->assertSessionHasErrors('email');

    expect(Contact::pluck('email')->all())->toBe(['jane@example.com']);
});

test('the same email can exist in two workspaces', function () {
    Contact::factory()->create(['email' => 'jane@example.com']);
    $this->createOwner('Globex');

    Contact::factory()->create(['email' => 'jane@example.com']);

    expect(Contact::withoutGlobalScopes()->where('email', 'jane@example.com')->count())->toBe(2);
});

test('upsert merges custom fields and adds tags without removing existing ones', function () {
    CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);
    CustomFieldDefinition::create(['key' => 'seats', 'label' => 'Seats', 'type' => 'number']);
    $upsert = app(UpsertContact::class);

    $upsert->handle(ContactData::fromValidated(['email' => 'jane@example.com', 'custom_fields' => ['plan' => 'pro'], 'tags' => ['VIP']]), ContactSource::Api);
    ['contact' => $contact, 'created' => $created] = $upsert->handle(
        ContactData::fromValidated(['email' => 'JANE@example.com', 'first_name' => 'Jane', 'custom_fields' => ['seats' => '5'], 'tags' => ['Beta']]),
        ContactSource::Api,
    );

    expect($created)->toBeFalse()
        ->and($contact->first_name)->toBe('Jane')
        ->and($contact->custom_fields)->toBe(['plan' => 'pro', 'seats' => 5])
        ->and($contact->tags()->orderBy('name')->pluck('name')->all())->toBe(['Beta', 'VIP'])
        ->and($contact->source)->toBe(ContactSource::Api);
});

test('upsert restores an archived contact', function () {
    Contact::factory()->archived()->create(['email' => 'jane@example.com']);

    ['contact' => $contact] = app(UpsertContact::class)->handle(ContactData::fromValidated(['email' => 'jane@example.com']), ContactSource::Api);

    expect($contact->archived_at)->toBeNull();
});

test('unsubscribing records when it happened and resubscribing clears it', function () {
    $contact = Contact::factory()->create();

    $this->actingAs($this->owner)->put(route('marketing.contacts.update', $contact), [
        'email' => $contact->email,
        'status' => 'unsubscribed',
    ])->assertRedirect();

    expect($contact->fresh()->status)->toBe(ContactStatus::Unsubscribed)
        ->and($contact->fresh()->unsubscribed_at)->not->toBeNull();

    $this->actingAs($this->owner)->put(route('marketing.contacts.update', $contact), [
        'email' => $contact->email,
        'status' => 'subscribed',
    ]);

    expect($contact->fresh()->unsubscribed_at)->toBeNull();
});

test('the edit form replaces tags and lists', function () {
    $customers = ContactList::factory()->create();
    $leads = ContactList::factory()->create();
    $contact = Contact::factory()->create();
    $contact->lists()->attach($customers);
    app(UpsertContact::class)->handle(ContactData::fromValidated(['email' => $contact->email, 'tags' => ['Old']]), ContactSource::Manual);

    $this->actingAs($this->owner)->put(route('marketing.contacts.update', $contact), [
        'email' => $contact->email,
        'status' => 'subscribed',
        'tags' => ['New'],
        'lists' => [$leads->id],
    ])->assertSessionHasNoErrors();

    expect($contact->tags()->pluck('name')->all())->toBe(['New'])
        ->and($contact->lists()->pluck('contact_lists.id')->all())->toBe([$leads->id]);
});

test('custom field values are validated against their type', function () {
    CustomFieldDefinition::create(['key' => 'renewal', 'label' => 'Renewal', 'type' => 'date']);

    $this->actingAs($this->owner)->post(route('marketing.contacts.store'), [
        'email' => 'jane@example.com',
        'status' => 'subscribed',
        'custom_fields' => ['renewal' => 'next tuesday'],
    ])->assertSessionHasErrors('custom_fields.renewal');

    $this->actingAs($this->owner)->post(route('marketing.contacts.store'), [
        'email' => 'jane@example.com',
        'status' => 'subscribed',
        'custom_fields' => ['unknown' => 'x'],
    ])->assertSessionHasErrors('custom_fields');
});

test('lists from another workspace cannot be attached', function () {
    $this->createOwner('Globex');
    $foreignList = ContactList::factory()->create();
    $this->useWorkspace($this->workspace);

    $this->actingAs($this->owner)->post(route('marketing.contacts.store'), [
        'email' => 'jane@example.com',
        'status' => 'subscribed',
        'lists' => [$foreignList->id],
    ])->assertSessionHasErrors('lists.0');
});

test('archived contacts are hidden from the list unless asked for', function () {
    Contact::factory()->create(['email' => 'active@example.com']);
    Contact::factory()->archived()->create(['email' => 'archived@example.com']);

    $this->actingAs($this->owner)->get(route('marketing.contacts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/contacts/index')
            ->has('contacts.data', 1)
            ->where('contacts.data.0.email', 'active@example.com'));

    $this->actingAs($this->owner)->get(route('marketing.contacts.index', ['archived' => 1]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('contacts.data.0.email', 'archived@example.com'));
});

test('contacts can be searched by email or name', function () {
    Contact::factory()->create(['email' => 'jane@example.com', 'first_name' => 'Jane']);
    Contact::factory()->create(['email' => 'bob@example.com', 'first_name' => 'Bob']);

    $this->actingAs($this->owner)->get(route('marketing.contacts.index', ['search' => 'JAN']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('contacts.data', 1)->where('contacts.data.0.email', 'jane@example.com'));
});
