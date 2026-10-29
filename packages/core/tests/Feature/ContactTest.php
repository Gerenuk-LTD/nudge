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

test('contacts can be sorted by an allowed column in either direction', function () {
    Contact::factory()->create(['email' => 'bob@example.com']);
    Contact::factory()->create(['email' => 'amy@example.com']);
    Contact::factory()->create(['email' => 'cat@example.com']);

    $ascending = $this->actingAs($this->owner)->get(route('marketing.contacts.index', ['sort' => 'email']));
    $descending = $this->actingAs($this->owner)->get(route('marketing.contacts.index', ['sort' => '-email']));

    $ascending->assertInertia(fn (AssertableInertia $page) => $page
        ->where('contacts.data.0.email', 'amy@example.com')
        ->where('contacts.data.2.email', 'cat@example.com'));
    $descending->assertInertia(fn (AssertableInertia $page) => $page
        ->where('contacts.data.0.email', 'cat@example.com')
        ->where('contacts.data.2.email', 'amy@example.com'));
});

test('an unknown sort or page size falls back to the newest contacts, 50 a page', function () {
    $first = Contact::factory()->create();
    $latest = Contact::factory()->create();

    $response = $this->actingAs($this->owner)->get(route('marketing.contacts.index', ['sort' => 'password', 'per_page' => 7]));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('contacts.per_page', 50)
        ->where('contacts.data.0.id', $latest->id)
        ->where('contacts.data.1.id', $first->id));
});

test('contacts can be paged by an allowed page size', function () {
    Contact::factory()->count(11)->create();

    $response = $this->actingAs($this->owner)->get(route('marketing.contacts.index', ['per_page' => 10]));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('contacts.per_page', 10)
        ->has('contacts.data', 10)
        ->where('contacts.last_page', 2));
});

test('comma-separated filters keep contacts matching any of the values', function () {
    $customers = ContactList::factory()->create();
    $leads = ContactList::factory()->create();
    $other = ContactList::factory()->create();
    $customers->contacts()->attach(Contact::factory()->create(['email' => 'customer@example.com']));
    $leads->contacts()->attach(Contact::factory()->create(['email' => 'lead@example.com']));
    $other->contacts()->attach(Contact::factory()->create(['email' => 'other@example.com']));

    $response = $this->actingAs($this->owner)->get(route('marketing.contacts.index', [
        'list' => "{$customers->id},{$leads->id}",
        'sort' => 'email',
    ]));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('contacts.data', 2)
        ->where('contacts.data.0.email', 'customer@example.com')
        ->where('contacts.data.1.email', 'lead@example.com'));
});

test('lists show how many contacts joined each one per day', function () {
    $this->travelTo('2026-10-28 12:00:00');
    $newsletter = ContactList::factory()->create(['name' => 'Newsletter']);
    $quiet = ContactList::factory()->create(['name' => 'Quiet']);
    $newsletter->contacts()->attach(Contact::factory()->count(2)->create(), ['created_at' => '2026-10-27 09:00:00']);
    $newsletter->contacts()->attach(Contact::factory()->create(), ['created_at' => '2026-08-01 09:00:00']);

    $response = $this->actingAs($this->owner)->get(route('marketing.lists.index'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('lists.0.name', 'Newsletter')
        ->where('lists.0.contacts_count', 3)
        ->has('lists.0.joins', 30)
        ->where('lists.0.joins.28', ['date' => '2026-10-27', 'count' => 2])
        ->where('lists.1.joins', fn ($joins) => collect($joins)->sum('count') === 0));
});

test('the contacts list loads a side panel preview only when asked for one', function () {
    CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);
    CustomFieldDefinition::create(['key' => 'seats', 'label' => 'Seats', 'type' => 'number']);
    $list = ContactList::factory()->create(['name' => 'Newsletter']);
    $contact = Contact::factory()->create(['email' => 'jane@example.com', 'first_name' => 'Jane', 'last_name' => 'Doe', 'custom_fields' => ['plan' => 'pro']]);
    $contact->lists()->attach($list);

    $this->actingAs($this->owner)->get(route('marketing.contacts.index', ['preview' => $contact->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('preview')
            ->reloadOnly('preview', fn (AssertableInertia $reload) => $reload
                ->where('preview.name', 'Jane Doe')
                ->where('preview.lists', ['Newsletter'])
                ->where('preview.fields', [['label' => 'Plan', 'type' => 'text', 'value' => 'pro']])
                ->has('preview.activity')));
});

test('the side panel cannot preview a contact from another workspace', function () {
    $this->createOwner('Globex');
    $foreign = Contact::factory()->create();
    $this->useWorkspace($this->workspace);

    $this->actingAs($this->owner)->get(route('marketing.contacts.index', ['preview' => $foreign->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->reloadOnly('preview', fn (AssertableInertia $reload) => $reload->where('preview', null)));
});
