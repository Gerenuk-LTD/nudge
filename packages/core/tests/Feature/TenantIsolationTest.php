<?php

use Gerenuk\Nudge\Models\Concerns\BelongsToWorkspace;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\ContactList;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Gerenuk\Nudge\Tenancy\MissingWorkspaceException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Tables that carry workspace_id but are deliberately not scoped, with the reason.
 */
const UNSCOPED_TENANT_TABLES = [
    'memberships' => 'Read across workspaces to build the switcher; access is checked against the current workspace.',
];

/**
 * Tables that belong to a tenant through a parent row rather than their own workspace_id.
 */
const CHILD_TABLES = [
    'contact_list_memberships' => 'Pivot between a list and a contact, both workspace-scoped.',
    'contact_tag' => 'Pivot between a tag and a contact, both workspace-scoped.',
];

/**
 * @return array<string, class-string<Model>>
 */
function packageModelsByTable(): array
{
    $models = [];

    foreach (glob(__DIR__.'/../../src/Models/*.php') as $file) {
        $class = 'Gerenuk\\Nudge\\Models\\'.basename($file, '.php');
        $models[(new $class)->getTable()] = $class;
    }

    return $models;
}

test('every table with a workspace_id is owned by a workspace-scoped model', function () {
    $models = packageModelsByTable();

    $tenantTables = collect(Schema::getTableListing(schemaQualified: false))
        ->filter(fn (string $table) => Schema::hasColumn($table, 'workspace_id'))
        ->reject(fn (string $table) => array_key_exists($table, UNSCOPED_TENANT_TABLES));

    expect($tenantTables)->not->toBeEmpty();

    foreach ($tenantTables as $table) {
        expect(array_key_exists($table, $models))->toBeTrue("Table [{$table}] has workspace_id but no package model.");
        expect(in_array(BelongsToWorkspace::class, class_uses_recursive($models[$table]), true))->toBeTrue("Model for [{$table}] must use BelongsToWorkspace.");
    }
});

test('every package table either carries workspace_id or is a known child or platform table', function () {
    $platformTables = ['accounts', 'workspaces'];

    $packageTables = collect(packageModelsByTable())->keys()
        ->merge(array_keys(CHILD_TABLES));

    foreach ($packageTables as $table) {
        $isAccounted = Schema::hasColumn($table, 'workspace_id')
            || array_key_exists($table, CHILD_TABLES)
            || in_array($table, $platformTables, true);

        expect($isAccounted)->toBeTrue("Table [{$table}] is not tied to a workspace.");
    }
});

test('querying a tenant-owned model without a current workspace fails closed', function () {
    app(CurrentWorkspace::class)->set(null);

    Contact::query()->get();
})->throws(MissingWorkspaceException::class);

test('creating a tenant-owned model without a current workspace fails closed', function () {
    app(CurrentWorkspace::class)->set(null);

    Contact::create(['email' => 'a@example.com']);
})->throws(MissingWorkspaceException::class);

test('queries only return the current workspace rows', function () {
    [, $acme] = $this->createOwner('Acme');
    Contact::factory()->count(2)->create();

    [, $globex] = $this->createOwner('Globex');
    Contact::factory()->create();

    $this->useWorkspace($acme);
    expect(Contact::count())->toBe(2);

    $this->useWorkspace($globex);
    expect(Contact::count())->toBe(1);
});

test('a contact in another workspace cannot be opened in the dashboard', function () {
    [$owner, $acme] = $this->createOwner('Acme');

    [, $globex] = $this->createOwner('Globex');
    $foreign = Contact::factory()->create();

    $this->actingAs($owner)
        ->get(route('nudge.contacts.show', $foreign))
        ->assertNotFound();
});

test('a list in another workspace cannot be changed through the dashboard', function () {
    [$owner] = $this->createOwner('Acme');

    $this->createOwner('Globex');
    $foreign = ContactList::factory()->create(['name' => 'Customers']);

    $this->actingAs($owner)
        ->put(route('nudge.lists.update', $foreign), ['name' => 'Hijacked'])
        ->assertNotFound();

    expect($foreign->fresh()->name)->toBe('Customers');
});
