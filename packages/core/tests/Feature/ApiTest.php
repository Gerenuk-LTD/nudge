<?php

use Gerenuk\Marketing\Actions\Workspaces\CreateApiToken;
use Gerenuk\Marketing\Models\ApiToken;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Models\Workspace;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
});

/**
 * @param  list<string>  $permissions
 */
function apiToken(Workspace $workspace, $user, array $permissions = ['contacts.view', 'contacts.manage']): string
{
    return app(CreateApiToken::class)->handle($workspace, $user, 'Test', $permissions)['plainToken'];
}

test('requests without a valid token are rejected', function () {
    $this->getJson(route('marketing.api.contacts.index'))->assertUnauthorized();
    $this->withToken('gmk_nope')->getJson(route('marketing.api.contacts.index'))->assertUnauthorized();
});

test('a missing record returns a plain not found message', function () {
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('marketing.api.contacts.show', 'nobody@example.com'))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.']);
});

test('repeated bad tokens from one address are rate limited', function () {
    foreach (range(1, 30) as $attempt) {
        $this->withToken('gmk_'.Str::random(40))->getJson(route('marketing.api.me'))->assertUnauthorized();
    }

    $this->withToken('gmk_'.Str::random(40))->getJson(route('marketing.api.me'))->assertStatus(429);
});

test('expired tokens are rejected', function () {
    $token = apiToken($this->workspace, $this->owner);
    ApiToken::query()->update(['expires_at' => now()->subMinute()]);

    $this->withToken($token)->getJson(route('marketing.api.me'))->assertUnauthorized();
});

test('tokens only store a hash', function () {
    $token = apiToken($this->workspace, $this->owner);

    expect(ApiToken::first()->token_hash)->toBe(hash('sha256', $token))
        ->and(ApiToken::first()->toArray())->not->toHaveKey('token_hash');
});

test('a contact is created then updated through the same email-keyed call', function () {
    CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->putJson(route('marketing.api.contacts.upsert', 'Jane@Example.com'), [
        'first_name' => 'Jane',
        'custom_fields' => ['plan' => 'pro'],
        'tags' => ['VIP'],
    ])->assertCreated()
        ->assertJsonPath('data.email', 'jane@example.com')
        ->assertJsonPath('data.source', 'api')
        ->assertJsonPath('data.tags', ['VIP']);

    $this->withToken($token)->putJson(route('marketing.api.contacts.upsert', 'jane@example.com'), [
        'last_name' => 'Doe',
    ])->assertOk()
        ->assertJsonPath('data.first_name', 'Jane')
        ->assertJsonPath('data.last_name', 'Doe')
        ->assertJsonPath('data.custom_fields.plan', 'pro');

    $this->withToken($token)->getJson(route('marketing.api.contacts.show', 'JANE@example.com'))
        ->assertOk()
        ->assertJsonPath('data.last_name', 'Doe');
});

test('validation errors come back as JSON', function () {
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->putJson(route('marketing.api.contacts.upsert', 'not-an-email'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('a read-only token cannot write', function () {
    $token = apiToken($this->workspace, $this->owner, ['contacts.view']);

    $this->withToken($token)->putJson(route('marketing.api.contacts.upsert', 'jane@example.com'))->assertForbidden();
    $this->withToken($token)->getJson(route('marketing.api.contacts.index'))->assertOk();
});

test('a token only sees its own workspace', function () {
    Contact::factory()->create(['email' => 'mine@example.com']);
    $list = ContactList::factory()->create();

    [$otherOwner, $globex] = $this->createOwner('Globex');
    Contact::factory()->create(['email' => 'theirs@example.com']);
    $token = apiToken($globex, $otherOwner);

    $this->withToken($token)->getJson(route('marketing.api.contacts.show', 'mine@example.com'))->assertNotFound();
    $this->withToken($token)->getJson(route('marketing.api.lists.show', $list))->assertNotFound();
    $this->withToken($token)->getJson(route('marketing.api.contacts.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'theirs@example.com');
});

test('contacts are paged with a cursor', function () {
    Contact::factory()->count(3)->create();
    $token = apiToken($this->workspace, $this->owner);

    $first = $this->withToken($token)->getJson(route('marketing.api.contacts.index', ['per_page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withToken($token)->getJson($first->json('links.next'))->assertJsonCount(1, 'data');
});

test('contacts can be added to and removed from a list by email', function () {
    Contact::factory()->create(['email' => 'a@example.com']);
    Contact::factory()->create(['email' => 'b@example.com']);
    $token = apiToken($this->workspace, $this->owner);

    $listId = $this->withToken($token)->postJson(route('marketing.api.lists.store'), ['name' => 'Newsletter'])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($token)->postJson(route('marketing.api.lists.contacts.store', $listId), [
        'emails' => ['A@example.com', 'b@example.com', 'missing@example.com'],
    ])->assertOk()
        ->assertJsonPath('data.added', 2)
        ->assertJsonPath('data.not_found', ['missing@example.com']);

    $this->withToken($token)->deleteJson(route('marketing.api.lists.contacts.destroy', $listId), ['emails' => ['a@example.com']])
        ->assertJsonPath('data.removed', 1);

    $this->withToken($token)->getJson(route('marketing.api.lists.show', $listId))->assertJsonPath('data.contacts_count', 1);
});

test('segment contacts are available through the API', function () {
    Contact::factory()->create(['email' => 'a@example.com']);
    Contact::factory()->unsubscribed()->create(['email' => 'b@example.com']);
    $segment = Segment::create([
        'name' => 'Subscribed',
        'definition' => ['match' => 'all', 'conditions' => [['type' => 'condition', 'field' => 'status', 'operator' => 'is', 'value' => 'subscribed']]],
    ]);
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('marketing.api.segments.contacts', $segment))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'a@example.com');
});

test('requests are rate limited per token', function () {
    config(['marketing.api_requests_per_minute' => 2]);
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('marketing.api.me'))->assertOk();
    $this->withToken($token)->getJson(route('marketing.api.me'))->assertOk();
    $this->withToken($token)->getJson(route('marketing.api.me'))->assertTooManyRequests();

    // Another token from the same address has its own allowance.
    $this->withToken(apiToken($this->workspace, $this->owner))->getJson(route('marketing.api.me'))->assertOk();
});

test('a token cannot be given permissions its creator lacks', function () {
    $editor = $this->addMember($this->workspace, 'Editor');

    // Editors cannot manage tokens at all.
    $this->actingAs($editor)->post(route('marketing.api-tokens.store'), ['name' => 'x', 'permissions' => ['contacts.view']])
        ->assertForbidden();

    $this->actingAs($this->owner)->post(route('marketing.api-tokens.store'), ['name' => 'x', 'permissions' => ['not.a.permission']])
        ->assertSessionHasErrors('permissions.0');
});

test('an api upsert cannot quietly resubscribe someone who unsubscribed', function () {
    $contact = Contact::factory()->unsubscribed()->create(['email' => 'ann@example.com']);
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)
        ->putJson(route('marketing.api.contacts.upsert', 'ann@example.com'), ['status' => 'subscribed', 'first_name' => 'Ann'])
        ->assertOk()
        ->assertJsonPath('data.status', 'unsubscribed')
        ->assertJsonPath('data.first_name', 'Ann');

    expect($contact->fresh()->unsubscribed_at)->not->toBeNull();
});

test('an api upsert resubscribes when asked to explicitly', function () {
    Contact::factory()->unsubscribed()->create(['email' => 'ann@example.com']);
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)
        ->putJson(route('marketing.api.contacts.upsert', 'ann@example.com'), ['status' => 'subscribed', 'resubscribe' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'subscribed');
});

test('an api upsert can still unsubscribe and create subscribed contacts', function () {
    $token = apiToken($this->workspace, $this->owner);
    $url = route('marketing.api.contacts.upsert', 'new@example.com');

    $this->withToken($token)->putJson($url, ['status' => 'subscribed'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'subscribed');

    $this->withToken($token)->putJson($url, ['status' => 'unsubscribed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'unsubscribed');
});
