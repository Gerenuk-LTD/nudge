<?php

use Gerenuk\Marketing\Actions\Workspaces\CreateApiToken;
use Gerenuk\Marketing\Jobs\PruneApiRequests;
use Gerenuk\Marketing\Models\ApiRequest;
use Gerenuk\Marketing\Models\ApiToken;
use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Role;
use Gerenuk\Marketing\Tests\Fixtures\User;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
});

/**
 * @param  list<string>  $permissions
 */
function logToken($workspace, $user, array $permissions = ['contacts.view', 'contacts.manage']): string
{
    return app(CreateApiToken::class)->handle($workspace, $user, 'Test', $permissions)['plainToken'];
}

function putContact(string $token, string $email = 'ada@example.com')
{
    return test()->withToken($token)->putJson(route('marketing.api.contacts.upsert', $email), ['first_name' => 'Ada']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function logRow($workspace, array $attributes = []): ApiRequest
{
    return ApiRequest::withoutGlobalScopes()->create([
        'workspace_id' => $workspace->id,
        'token_name' => 'Test',
        'method' => 'PUT',
        'route' => 'contacts/{email}',
        'path' => '/api/v1/contacts/ada@example.com',
        'status' => 200,
        'duration_ms' => 5,
        'request_body' => '{"a":1}',
        'response_body' => '{"b":2}',
        ...$attributes,
    ]);
}

/**
 * A user whose role holds exactly these permissions.
 *
 * @param  list<string>  $permissions
 */
function memberWith($workspace, array $permissions): User
{
    $role = Role::create(['workspace_id' => $workspace->id, 'name' => 'Custom '.count($permissions).uniqid(), 'permissions' => $permissions, 'is_owner' => false]);
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    Membership::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

test('a request is logged with its bodies', function () {
    $token = logToken($this->workspace, $this->owner);

    putContact($token)->assertSuccessful();

    $row = ApiRequest::sole();
    expect($row->method)->toBe('PUT')
        ->and($row->route)->toBe('contacts/{email}')
        ->and($row->token_name)->toBe('Test')
        ->and($row->status)->toBeIn([200, 201])
        ->and($row->request_body)->toContain('Ada')
        ->and($row->response_body)->toContain('ada@example.com')
        ->and($row->duration_ms)->toBeGreaterThanOrEqual(0);
});

test('the token is never stored', function () {
    $token = logToken($this->workspace, $this->owner);

    putContact($token)->assertSuccessful();

    expect(json_encode(ApiRequest::sole()->getAttributes()))->not->toContain($token);
});

test('invalid tokens are not logged', function () {
    $this->withToken('gmk_nope')->getJson(route('marketing.api.contacts.index'))->assertUnauthorized();

    expect(ApiRequest::withoutGlobalScopes()->count())->toBe(0);
});

test('a missing permission is logged', function () {
    $token = logToken($this->workspace, $this->owner, ['contacts.view']);

    putContact($token)->assertForbidden();

    expect(ApiRequest::sole()->status)->toBe(403);
});

test('long bodies are cut', function () {
    config(['marketing.api_log.max_body_bytes' => 100]);
    $token = logToken($this->workspace, $this->owner);

    $response = putContact($token);
    expect(strlen($response->getContent()))->toBeGreaterThan(100);

    $body = ApiRequest::sole()->response_body;
    expect($body)->toEndWith('more bytes')
        ->and(strlen($body))->toBeLessThanOrEqual(100 + strlen("\n… cut, 9999 more bytes"));
});

test('workspaces are kept apart', function () {
    $row = logRow($this->workspace);
    [$other, $otherWorkspace] = $this->createOwner('Other');

    $this->actingAs($other)->get(route('marketing.api-log.index'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($page) => $page->where('requests.total', 0)));
    $this->actingAs($other)->getJson(route('marketing.api-log.show', $row))->assertNotFound();
});

test('the page needs the api tokens permission', function () {
    $row = logRow($this->workspace);
    $user = memberWith($this->workspace, ['contacts.view']);

    $this->actingAs($user)->get(route('marketing.api-log.index'))->assertForbidden();
    $this->actingAs($user)->getJson(route('marketing.api-log.show', $row))->assertForbidden();
});

test('bodies need the contacts view permission', function () {
    $token = logToken($this->workspace, $this->owner);
    putContact($token)->assertSuccessful();
    $row = ApiRequest::sole();
    $user = memberWith($this->workspace, ['api_tokens.manage']);

    $this->actingAs($user)->getJson(route('marketing.api-log.show', $row))
        ->assertOk()
        ->assertJson(['request_body' => null, 'response_body' => null, 'bodies_missing' => 'hidden']);

    $this->actingAs($this->owner)->getJson(route('marketing.api-log.show', $row))
        ->assertOk()
        ->assertJson(['bodies_missing' => null])
        ->assertJsonPath('request_body', $row->request_body);
});

test('the log can be filtered', function () {
    $tokenA = ApiToken::query()->create(['name' => 'A', 'token_hash' => 'a', 'permissions' => []]);
    $tokenB = ApiToken::query()->create(['name' => 'B', 'token_hash' => 'b', 'permissions' => []]);
    logRow($this->workspace, ['api_token_id' => $tokenA->id, 'token_name' => 'A', 'status' => 200]);
    logRow($this->workspace, ['api_token_id' => $tokenB->id, 'token_name' => 'B', 'status' => 404, 'method' => 'GET', 'route' => 'contacts/{email}']);
    logRow($this->workspace, ['api_token_id' => $tokenB->id, 'token_name' => 'B', 'status' => 500, 'method' => 'POST', 'route' => 'events']);
    logRow($this->workspace, ['token_name' => 'Old', 'status' => 200, 'created_at' => now()->subDays(10)]);

    $expectTotal = function (array $query, int $expected): void {
        $this->actingAs($this->owner)->get(route('marketing.api-log.index', $query))
            ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload->where('requests.total', $expected)));
    };

    $expectTotal([], 3);
    $expectTotal(['token' => (string) $tokenA->id], 1);
    $expectTotal(['status' => 'client_error'], 1);
    $expectTotal(['status' => 'success,server_error'], 2);
    $expectTotal(['route' => 'events'], 1);
    $expectTotal(['route' => 'contacts/{email}'], 2);
    $expectTotal(['method' => 'POST'], 1);
    $expectTotal(['method' => 'get,post'], 2);
    $expectTotal(['method' => 'GET', 'route' => 'contacts/{email}'], 1);
    $expectTotal(['period' => '30d'], 4);
});

test("the method and endpoint options come from this workspace's log", function () {
    logRow($this->workspace, ['method' => 'PATCH', 'route' => 'contacts/{email}']);
    logRow($this->workspace, ['method' => 'GET', 'route' => 'me']);
    logRow($this->workspace, ['method' => 'PATCH', 'route' => 'me']);

    $this->actingAs($this->owner)->get(route('marketing.api-log.index'))
        ->assertInertia(fn ($page) => $page
            ->where('methods', [['value' => 'GET', 'label' => 'GET'], ['value' => 'PATCH', 'label' => 'PATCH']])
            ->where('routes', [['value' => 'contacts/{email}', 'label' => 'contacts/{email}'], ['value' => 'me', 'label' => 'me']]));
});

test('pruning clears old bodies and deletes old rows', function () {
    $old = logRow($this->workspace, ['created_at' => now()->subDays(31)]);
    $middle = logRow($this->workspace, ['created_at' => now()->subDays(8)]);
    $recent = logRow($this->workspace, ['created_at' => now()->subDay()]);

    (new PruneApiRequests)();

    expect(ApiRequest::withoutGlobalScopes()->find($old->id))->toBeNull()
        ->and($middle->fresh()->request_body)->toBeNull()
        ->and($middle->fresh()->response_body)->toBeNull()
        ->and($recent->fresh()->request_body)->toBe('{"a":1}')
        ->and($recent->fresh()->response_body)->toBe('{"b":2}');

    $this->actingAs($this->owner)->getJson(route('marketing.api-log.show', $middle))
        ->assertJson(['bodies_missing' => 'cleared']);
});

test('logging can be turned off', function () {
    config(['marketing.api_log.enabled' => false]);
    $token = logToken($this->workspace, $this->owner);

    putContact($token)->assertSuccessful();

    expect(ApiRequest::withoutGlobalScopes()->count())->toBe(0);
});

test('a deleted token keeps its rows', function () {
    $token = logToken($this->workspace, $this->owner);
    putContact($token)->assertSuccessful();

    // SQLite doesn't enforce foreign keys in the test database, so check what the schema promises.
    $foreignKey = collect(Schema::getForeignKeys('api_requests'))->firstWhere('columns', ['api_token_id']);
    expect($foreignKey['on_delete'])->toBe('set null');

    ApiToken::query()->delete();

    expect(ApiRequest::sole()->token_name)->toBe('Test');
});

test('successful reads keep no bodies', function () {
    $token = logToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('marketing.api.contacts.index'))->assertOk();

    $row = ApiRequest::sole();
    expect($row->request_body)->toBeNull()
        ->and($row->response_body)->toBeNull();

    $this->actingAs($this->owner)->getJson(route('marketing.api-log.show', $row))
        ->assertJson(['bodies_missing' => 'not_kept']);
});

test('failed reads keep their bodies', function () {
    $token = logToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('marketing.api.contacts.show', 'nobody@example.com'))->assertNotFound();

    $row = ApiRequest::sole();
    expect($row->status)->toBe(404)
        ->and($row->response_body)->toContain('Not found');
});

test('a request can be found by its request ID', function () {
    logRow($this->workspace);
    $second = logRow($this->workspace, ['path' => '/api/v1/me', 'created_at' => now()->subDays(10)]);

    foreach (["req_{$second->ulid}", $second->ulid, strtoupper("req_{$second->ulid}")] as $search) {
        $this->actingAs($this->owner)->get(route('marketing.api-log.index', ['search' => $search]))
            ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload
                ->where('requests.total', 1)
                ->where('requests.data.0.id', $second->id)
                ->where('requests.data.0.request_id', $second->publicId())));
    }

    $this->actingAs($this->owner)->get(route('marketing.api-log.index', ['search' => (string) $second->id]))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload->where('requests.total', 0)));
});

test('API responses carry the logged request ID', function () {
    $token = logToken($this->workspace, $this->owner);

    $header = putContact($token)->assertSuccessful()->headers->get('X-Request-Id');

    expect($header)->toMatch('/^req_[0-9a-z]{26}$/')
        ->and(ApiRequest::sole()->publicId())->toBe($header);
});

test('the header is sent even when logging is off', function () {
    config(['marketing.api_log.enabled' => false]);
    $token = logToken($this->workspace, $this->owner);

    $this->withToken($token)->getJson(route('marketing.api.me'))->assertSuccessful()->assertHeader('X-Request-Id');

    expect(ApiRequest::withoutGlobalScopes()->count())->toBe(0);
});

test('the log shows 25 rows a page', function () {
    foreach (range(1, 30) as $ignored) {
        logRow($this->workspace);
    }

    $this->actingAs($this->owner)->get(route('marketing.api-log.index'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload
            ->where('requests.per_page', 25)
            ->has('requests.data', 25)));
});
