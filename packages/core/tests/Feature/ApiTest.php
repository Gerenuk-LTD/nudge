<?php

use Gerenuk\Nudge\Actions\Workspaces\CreateApiToken;
use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Models\ActivityEvent;
use Gerenuk\Nudge\Models\ApiToken;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\ContactList;
use Gerenuk\Nudge\Models\CustomFieldDefinition;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Models\Tag;
use Gerenuk\Nudge\Models\Workspace;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Route;
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
    $this->getJson(route('nudge.api.contacts.index'))
        ->assertUnauthorized()
        ->assertExactJson(['code' => 'unauthenticated', 'message' => 'Unauthenticated.']);
    $this->withToken('gmk_nope')->getJson(route('nudge.api.contacts.index'))
        ->assertUnauthorized()
        ->assertExactJson(['code' => 'unauthenticated', 'message' => 'Unauthenticated.']);
});

test('a missing record returns a plain not found message', function () {
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('nudge.api.contacts.show', 'nobody@example.com'))
        ->assertNotFound()
        ->assertExactJson(['code' => 'not_found', 'message' => 'Not found.']);
});

test('a record that does not bind answers not found', function () {
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('nudge.api.lists.show', 999))
        ->assertNotFound()
        ->assertExactJson(['code' => 'not_found', 'message' => 'Not found.']);
});

test('an unknown path under the prefix answers not found as JSON', function () {
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson('/'.config('nudge.api_prefix').'/nothing-here')
        ->assertNotFound()
        ->assertExactJson(['code' => 'not_found', 'message' => 'Not found.']);
});

test('an unknown contact on the event timeline answers not found', function () {
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('nudge.api.contacts.events', 'nobody@example.com'))
        ->assertNotFound()
        ->assertExactJson(['code' => 'not_found', 'message' => 'Not found.']);
});

test('an unknown segment filter answers not found', function () {
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('nudge.api.contacts.index', ['segment' => 999]))
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

test('a wrong method answers method not allowed', function () {
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->deleteJson(route('nudge.api.me'))
        ->assertStatus(405)
        ->assertJsonPath('code', 'method_not_allowed')
        ->assertJsonStructure(['code', 'message']);
});

test('an unknown contact on an event without create_contact has its own code', function () {
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->postJson(route('nudge.api.events.store'), ['email' => 'nobody@example.com', 'event' => 'ping'])
        ->assertNotFound()
        ->assertJsonPath('code', 'contact_not_found');
});

test('event properties are bounded', function () {
    $token = apiToken($this->workspace, $this->owner);
    $post = fn (array $properties) => $this->withToken($token)->postJson(route('nudge.api.events.store'), [
        'email' => 'ann@example.com', 'event' => 'ping', 'create_contact' => true, 'properties' => $properties,
    ]);
    $nested = fn (int $levels) => array_reduce(range(1, $levels), fn ($inner) => ['k' => $inner], 1);

    $post(array_combine(array_map(fn ($i) => "key{$i}", range(1, 51)), range(1, 51)))->assertUnprocessable()->assertJsonValidationErrors('properties');
    $post($nested(6))->assertUnprocessable()->assertJsonValidationErrors('properties');
    $post(['note' => str_repeat('a', 20000)])->assertUnprocessable()->assertJsonValidationErrors('properties');

    $post(['total' => 12.5, 'items' => [['sku' => 'a'], ['sku' => 'b']], 'deep' => $nested(4)])->assertAccepted();
});

test('a bad idempotency key has its own code', function () {
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->withHeader('Idempotency-Key', 'has space')
        ->postJson(route('nudge.api.events.store'), ['email' => 'ann@example.com', 'event' => 'ping', 'create_contact' => true])
        ->assertUnprocessable()
        ->assertExactJson([
            'code' => 'invalid_idempotency_key',
            'message' => 'The Idempotency-Key header must be 1 to 255 visible ASCII characters.',
        ]);
});

test('a token without the permission answers with a code', function () {
    $token = apiToken($this->workspace, $this->owner, ['contacts.view']);

    $this->withToken($token)->putJson(route('nudge.api.contacts.upsert', 'jane@example.com'))
        ->assertForbidden()
        ->assertExactJson(['code' => 'missing_permission', 'message' => 'This token does not have the contacts.manage permission.']);
});

test('validation errors carry a code and the field errors', function () {
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->putJson(route('nudge.api.contacts.upsert', 'not-an-email'))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['code', 'message', 'errors' => ['email']]);
});

test('the package renders JSON errors for its prefix without an Accept header', function () {
    $token = apiToken($this->workspace, $this->owner);

    $response = $this->withToken($token)->put(route('nudge.api.contacts.upsert', 'not-an-email'), ['first_name' => 'Jane']);

    $response->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

test('repeated bad tokens from one address are rate limited', function () {
    foreach (range(1, 30) as $attempt) {
        $this->withToken('gmk_'.Str::random(40))->getJson(route('nudge.api.me'))->assertUnauthorized();
    }

    $this->withToken('gmk_'.Str::random(40))->getJson(route('nudge.api.me'))
        ->assertStatus(429)
        ->assertExactJson(['code' => 'rate_limited', 'message' => 'Too many requests.'])
        ->assertHeader('Retry-After');
});

test('expired tokens are rejected', function () {
    $token = apiToken($this->workspace, $this->owner);
    ApiToken::query()->update(['expires_at' => now()->subMinute()]);

    $this->withToken($token)->getJson(route('nudge.api.me'))->assertUnauthorized();
});

test('tokens only store a hash', function () {
    $token = apiToken($this->workspace, $this->owner);

    expect(ApiToken::first()->token_hash)->toBe(hash('sha256', $token))
        ->and(ApiToken::first()->toArray())->not->toHaveKey('token_hash');
});

test('a contact is created then updated through the same email-keyed call', function () {
    CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->putJson(route('nudge.api.contacts.upsert', 'Jane@Example.com'), [
        'first_name' => 'Jane',
        'custom_fields' => ['plan' => 'pro'],
        'tags' => ['VIP'],
    ])->assertCreated()
        ->assertJsonPath('data.email', 'jane@example.com')
        ->assertJsonPath('data.source', 'api')
        ->assertJsonPath('data.tags', ['VIP']);

    $this->withToken($token)->putJson(route('nudge.api.contacts.upsert', 'jane@example.com'), [
        'last_name' => 'Doe',
    ])->assertOk()
        ->assertJsonPath('data.first_name', 'Jane')
        ->assertJsonPath('data.last_name', 'Doe')
        ->assertJsonPath('data.custom_fields.plan', 'pro');

    $this->withToken($token)->getJson(route('nudge.api.contacts.show', 'JANE@example.com'))
        ->assertOk()
        ->assertJsonPath('data.last_name', 'Doe');
});

test('validation errors come back as JSON', function () {
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->putJson(route('nudge.api.contacts.upsert', 'not-an-email'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('a read-only token cannot write', function () {
    $token = apiToken($this->workspace, $this->owner, ['contacts.view']);

    $this->withToken($token)->putJson(route('nudge.api.contacts.upsert', 'jane@example.com'))->assertForbidden();
    $this->withToken($token)->getJson(route('nudge.api.contacts.index'))->assertOk();
});

test('a token only sees its own workspace', function () {
    Contact::factory()->create(['email' => 'mine@example.com']);
    $list = ContactList::factory()->create();

    [$otherOwner, $globex] = $this->createOwner('Globex');
    Contact::factory()->create(['email' => 'theirs@example.com']);
    $token = apiToken($globex, $otherOwner);

    $this->withToken($token)->getJson(route('nudge.api.contacts.show', 'mine@example.com'))->assertNotFound();
    $this->withToken($token)->getJson(route('nudge.api.lists.show', $list))->assertNotFound();
    $this->withToken($token)->getJson(route('nudge.api.contacts.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'theirs@example.com');
});

test('contacts are paged with a cursor', function () {
    Contact::factory()->count(3)->create();
    $token = apiToken($this->workspace, $this->owner);

    $first = $this->withToken($token)->getJson(route('nudge.api.contacts.index', ['per_page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withToken($token)->getJson($first->json('links.next'))->assertJsonCount(1, 'data');
});

test('contacts can be added to and removed from a list by email', function () {
    Contact::factory()->create(['email' => 'a@example.com']);
    Contact::factory()->create(['email' => 'b@example.com']);
    $token = apiToken($this->workspace, $this->owner);

    $listId = $this->withToken($token)->postJson(route('nudge.api.lists.store'), ['name' => 'Newsletter'])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($token)->postJson(route('nudge.api.lists.contacts.store', $listId), [
        'emails' => ['A@example.com', 'b@example.com', 'missing@example.com'],
    ])->assertOk()
        ->assertJsonPath('data.added', 2)
        ->assertJsonPath('data.not_found', ['missing@example.com']);

    $this->withToken($token)->deleteJson(route('nudge.api.lists.contacts.destroy', $listId), ['emails' => ['a@example.com']])
        ->assertJsonPath('data.removed', 1);

    $this->withToken($token)->getJson(route('nudge.api.lists.show', $listId))->assertJsonPath('data.contacts_count', 1);
});

test('segment contacts are available through the API', function () {
    Contact::factory()->create(['email' => 'a@example.com']);
    Contact::factory()->unsubscribed()->create(['email' => 'b@example.com']);
    $segment = Segment::create([
        'name' => 'Subscribed',
        'definition' => ['match' => 'all', 'conditions' => [['type' => 'condition', 'field' => 'status', 'operator' => 'is', 'value' => 'subscribed']]],
    ]);
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('nudge.api.segments.contacts', $segment))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'a@example.com');
});

test('requests are rate limited per token', function () {
    config(['nudge.api_requests_per_minute' => 2]);
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('nudge.api.me'))->assertOk();
    $this->withToken($token)->getJson(route('nudge.api.me'))->assertOk();
    $this->withToken($token)->getJson(route('nudge.api.me'))
        ->assertTooManyRequests()
        ->assertExactJson(['code' => 'rate_limited', 'message' => 'Too many requests.'])
        ->assertHeader('Retry-After');

    // Another token from the same address has its own allowance.
    $this->withToken(apiToken($this->workspace, $this->owner))->getJson(route('nudge.api.me'))->assertOk();
});

test('a token cannot be given permissions its creator lacks', function () {
    $editor = $this->addMember($this->workspace, 'Editor');

    // Editors cannot manage tokens at all.
    $this->actingAs($editor)->post(route('nudge.api-tokens.store'), ['name' => 'x', 'permissions' => ['contacts.view']])
        ->assertForbidden();

    $this->actingAs($this->owner)->post(route('nudge.api-tokens.store'), ['name' => 'x', 'permissions' => ['not.a.permission']])
        ->assertSessionHasErrors('permissions.0');
});

test('an api upsert cannot quietly resubscribe someone who unsubscribed', function () {
    $contact = Contact::factory()->unsubscribed()->create(['email' => 'ann@example.com']);
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)
        ->putJson(route('nudge.api.contacts.upsert', 'ann@example.com'), ['status' => 'subscribed', 'first_name' => 'Ann'])
        ->assertOk()
        ->assertJsonPath('data.status', 'unsubscribed')
        ->assertJsonPath('data.first_name', 'Ann');

    expect($contact->fresh()->unsubscribed_at)->not->toBeNull();
});

test('an api upsert resubscribes when asked to explicitly', function () {
    Contact::factory()->unsubscribed()->create(['email' => 'ann@example.com']);
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)
        ->putJson(route('nudge.api.contacts.upsert', 'ann@example.com'), ['status' => 'subscribed', 'resubscribe' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'subscribed');
});

test('an api upsert can still unsubscribe and create subscribed contacts', function () {
    $token = apiToken($this->workspace, $this->owner);
    $url = route('nudge.api.contacts.upsert', 'new@example.com');

    $this->withToken($token)->putJson($url, ['status' => 'subscribed'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'subscribed');

    $this->withToken($token)->putJson($url, ['status' => 'unsubscribed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'unsubscribed');
});

test('an api resubscribe is recorded with the token', function () {
    $contact = Contact::factory()->unsubscribed()->create(['email' => 'ann@example.com']);
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)
        ->putJson(route('nudge.api.contacts.upsert', 'ann@example.com'), ['status' => 'subscribed', 'resubscribe' => true])
        ->assertOk();

    $event = $contact->events()->sole();

    expect($event->type)->toBe(EventType::Resubscribed)
        ->and($event->data)->toBe(['via' => 'api', 'token_id' => ApiToken::firstOrFail()->id]);
});

dataset('paginated list routes', [
    'contacts' => [fn (Segment $segment, Contact $contact): string => route('nudge.api.contacts.index')],
    'segment contacts' => [fn (Segment $segment, Contact $contact): string => route('nudge.api.segments.contacts', $segment)],
    'contact events' => [fn (Segment $segment, Contact $contact): string => route('nudge.api.contacts.events', $contact->email)],
    'lists' => [fn (Segment $segment, Contact $contact): string => route('nudge.api.lists.index')],
    'tags' => [fn (Segment $segment, Contact $contact): string => route('nudge.api.tags.index')],
    'segments' => [fn (Segment $segment, Contact $contact): string => route('nudge.api.segments.index')],
    'custom fields' => [fn (Segment $segment, Contact $contact): string => route('nudge.api.custom-fields.index')],
]);

test('every list is cursor-paginated with data, links and meta', function (Closure $url) {
    $contact = Contact::factory()->create(['email' => 'a@example.com']);
    Contact::factory()->create(['email' => 'b@example.com']);

    foreach (['one', 'two'] as $name) {
        ContactList::factory()->create(['name' => $name]);
        Tag::create(['name' => $name]);
        CustomFieldDefinition::create(['key' => $name, 'label' => $name, 'type' => 'text']);
        ActivityEvent::create(['contact_id' => $contact->id, 'type' => EventType::Custom, 'data' => ['name' => $name], 'occurred_at' => now()]);
    }

    $segment = Segment::create(['name' => 'One', 'definition' => ['match' => 'all', 'conditions' => []]]);
    Segment::create(['name' => 'Two', 'definition' => ['match' => 'all', 'conditions' => []]]);
    $token = apiToken($this->workspace, $this->owner);

    $page = $this->withToken($token)->getJson($url($segment, $contact).'?per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['path', 'per_page', 'next_cursor', 'prev_cursor']]);

    expect($page->json('meta.per_page'))->toBe(1)
        ->and($page->json('meta.next_cursor'))->not->toBeNull();

    $this->withToken($token)->getJson($page->json('links.next'))->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson($url($segment, $contact).'?per_page=0')->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
})->with('paginated list routes');

test('creating and updating a list answers with its contact count and updated_at', function () {
    $token = apiToken($this->workspace, $this->owner);

    $id = $this->withToken($token)->postJson(route('nudge.api.lists.store'), ['name' => 'News'])
        ->assertCreated()
        ->assertJsonPath('data.contacts_count', 0)
        ->assertJsonStructure(['data' => ['id', 'name', 'description', 'contacts_count', 'created_at', 'updated_at']])
        ->json('data.id');

    $this->withToken($token)->putJson(route('nudge.api.lists.update', $id), ['name' => 'Updates'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updates')
        ->assertJsonPath('data.contacts_count', 0)
        ->assertJsonStructure(['data' => ['updated_at']]);
});

test('the event timeline has ids that match the recorded event', function () {
    $token = apiToken($this->workspace, $this->owner);

    $recorded = $this->withToken($token)
        ->postJson(route('nudge.api.events.store'), ['email' => 'ann@example.com', 'event' => 'ping', 'create_contact' => true])
        ->assertAccepted()
        ->assertJsonStructure(['data' => ['id', 'type', 'data', 'campaign_id', 'occurred_at']])
        ->assertJsonPath('data.type', 'custom')
        ->assertJsonPath('data.data.name', 'ping');

    $this->withToken($token)->getJson(route('nudge.api.contacts.events', 'ann@example.com'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $recorded->json('data.id'));
});

test('an unknown contact status filter is a validation error', function () {
    Contact::factory()->create();
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('nudge.api.contacts.index', ['status' => 'subscibed']))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['status']]);

    $this->withToken($token)->getJson(route('nudge.api.contacts.index', ['status' => 'subscribed,unsubscribed']))->assertOk();
});

test('a segment has its definition on the list and its contact count only when fetched', function () {
    $segment = Segment::create(['name' => 'All', 'definition' => ['match' => 'all', 'conditions' => []]]);
    Contact::factory()->count(2)->create();
    $token = apiToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('nudge.api.segments.index'))
        ->assertOk()
        ->assertJsonPath('data.0.definition.match', 'all')
        ->assertJsonMissingPath('data.0.contacts_count')
        ->assertJsonStructure(['data' => [['id', 'name', 'definition', 'created_at', 'updated_at']]]);

    $this->withToken($token)->getJson(route('nudge.api.segments.show', $segment))
        ->assertOk()
        ->assertJsonPath('data.contacts_count', 2);
});

test('an event with no properties sends them as an empty object', function () {
    $token = apiToken($this->workspace, $this->owner);
    Contact::factory()->create(['email' => 'ada@example.com']);

    $response = $this->withToken($token)->postJson(route('nudge.api.events.store'), ['email' => 'ada@example.com', 'event' => 'ping'])
        ->assertStatus(202);

    expect($response->getContent())->toContain('"properties":{}');
});

test('an authentication exception on the API answers unauthenticated', function () {
    Route::middleware('api')->get(trim((string) config('nudge.api_prefix'), '/').'/_test-auth', fn () => throw new AuthenticationException);

    $this->get('/'.trim((string) config('nudge.api_prefix'), '/').'/_test-auth')
        ->assertUnauthorized()
        ->assertExactJson(['code' => 'unauthenticated', 'message' => 'Unauthenticated.']);
});
