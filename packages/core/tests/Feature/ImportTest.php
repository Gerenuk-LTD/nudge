<?php

use Gerenuk\Marketing\Actions\Contacts\UpsertContact;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\ImportStatus;
use Gerenuk\Marketing\Jobs\ProcessImport;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\Import;
use Gerenuk\Marketing\Support\ContactData;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    [$this->owner, $this->workspace] = $this->createOwner();
});

function csvUpload(string $contents, string $name = 'contacts.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

function uploadAndStart($test, string $csv, array $mapping, array $extra = []): Import
{
    $test->actingAs($test->owner)->post(route('marketing.imports.store'), ['file' => csvUpload($csv)])->assertRedirect();
    $import = Import::latest('id')->first();

    $test->actingAs($test->owner)
        ->post(route('marketing.imports.start', $import), ['mapping' => $mapping, ...$extra])
        ->assertSessionHasNoErrors();

    return $import->fresh();
}

test('uploading a file records its headers and row count', function () {
    $this->actingAs($this->owner)->post(route('marketing.imports.store'), [
        'file' => csvUpload("\xEF\xBB\xBFEmail,First Name\na@example.com,Ann\n\nb@example.com,Bob\n"),
    ])->assertRedirect();

    $import = Import::first();

    expect($import->headers)->toBe(['Email', 'First Name'])
        ->and($import->total_rows)->toBe(2)
        ->and($import->status)->toBe(ImportStatus::Pending);
});

test('an import started from a list has that list chosen when mapping columns', function () {
    $list = ContactList::factory()->create();

    $this->actingAs($this->owner)->post(route('marketing.imports.store'), [
        'file' => csvUpload("email\na@example.com\n"),
        'list' => $list->id,
    ])->assertRedirect(route('marketing.imports.show', ['import' => Import::first(), 'list' => $list->id]));

    $this->actingAs($this->owner)->get(route('marketing.imports.show', ['import' => Import::first(), 'list' => $list->id]))
        ->assertInertia(fn ($page) => $page->where('defaultList', $list->id));
});

test('a mapped import creates and updates contacts, adds them to a list and tags them', function () {
    CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);
    Contact::factory()->create(['email' => 'existing@example.com', 'first_name' => 'Old']);
    $list = ContactList::factory()->create();

    $import = uploadAndStart($this, "email,first,plan,ignored\nnew@example.com,Ann,pro,x\nEXISTING@example.com,Eve,basic,y\n", [
        'email', 'first_name', 'custom:plan', null,
    ], ['contact_list_id' => $list->id, 'tags' => ['Imported']]);

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->created_count)->toBe(1)
        ->and($import->updated_count)->toBe(1)
        ->and($import->failed_count)->toBe(0)
        ->and(Contact::forEmail('existing@example.com')->first()->first_name)->toBe('Eve')
        ->and(Contact::forEmail('new@example.com')->first()->custom_fields)->toBe(['plan' => 'pro'])
        ->and(Contact::forEmail('new@example.com')->first()->source)->toBe(ContactSource::Import)
        ->and($list->contacts()->count())->toBe(2)
        ->and(Contact::whereHas('tags', fn ($q) => $q->where('name', 'Imported'))->count())->toBe(2);
});

test('the errors download neutralises spreadsheet formulas', function () {
    $import = uploadAndStart($this, "email\n=1+1\n", ['email']);

    $download = $this->actingAs($this->owner)->get(route('marketing.imports.errors', $import));

    expect($download->streamedContent())->toContain("'=1+1");
});

test('invalid rows are skipped and reported with their line number', function () {
    $import = uploadAndStart($this, "email\ngood@example.com\nnot-an-email\n\nalso-good@example.com\n", ['email']);

    expect($import->created_count)->toBe(2)
        ->and($import->failed_count)->toBe(1)
        ->and($import->errors()->first()->row_number)->toBe(3);

    $download = $this->actingAs($this->owner)->get(route('marketing.imports.errors', $import));
    $download->assertOk();

    expect($download->streamedContent())->toContain('3,not-an-email');

    $this->actingAs($this->owner)->get(route('marketing.imports.show', $import))
        ->assertInertia(fn ($page) => $page
            ->has('failedRows', 1)
            ->where('failedRows.0.row_number', 3)
            ->where('failedRows.0.email', 'not-an-email'));
});

test('an import whose file has gone shows the page instead of failing', function () {
    $this->actingAs($this->owner)->post(route('marketing.imports.store'), ['file' => csvUpload("email\na@example.com\n")]);
    $import = Import::first();
    Storage::disk(config('marketing.imports.disk'))->delete($import->file_path);

    $this->actingAs($this->owner)->get(route('marketing.imports.show', $import))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('fileMissing', true));
});

test('a finished or pending import can be removed with its file, but a running one cannot', function () {
    $this->actingAs($this->owner)->post(route('marketing.imports.store'), ['file' => csvUpload("email\na@example.com\n")]);
    $pending = Import::first();
    $running = Import::create(['file_path' => 'imports/running.csv', 'original_filename' => 'running.csv', 'headers' => ['email'], 'status' => ImportStatus::Processing]);

    $this->actingAs($this->owner)->delete(route('marketing.imports.destroy', $running))->assertConflict();
    $this->actingAs($this->owner)->delete(route('marketing.imports.destroy', $pending))->assertRedirect(route('marketing.imports.index'));

    expect(Import::pluck('id')->all())->toBe([$running->id])
        ->and(Storage::disk(config('marketing.imports.disk'))->exists($pending->file_path))->toBeFalse();
});

test('the upload page knows the list an import was started from', function () {
    $list = ContactList::factory()->create(['name' => 'Newsletter']);

    $this->actingAs($this->owner)->get(route('marketing.imports.create', ['list' => $list->id]))
        ->assertInertia(fn ($page) => $page
            ->where('list.name', 'Newsletter')
            ->where('columns', ['email', 'first_name', 'last_name', 'status', 'tags']));
});

test('an import can unsubscribe contacts but never re-subscribes them', function () {
    app(UpsertContact::class)->handle(ContactData::fromValidated(['email' => 'optedout@example.com', 'status' => 'unsubscribed']), ContactSource::Manual);

    uploadAndStart($this, "email,status\noptedout@example.com,subscribed\nleaving@example.com,Unsubscribed\n", ['email', 'status']);

    expect(Contact::forEmail('optedout@example.com')->first()->status)->toBe(ContactStatus::Unsubscribed)
        ->and(Contact::forEmail('leaving@example.com')->first()->status)->toBe(ContactStatus::Unsubscribed);
});

test('the email column must be mapped exactly once', function () {
    $this->actingAs($this->owner)->post(route('marketing.imports.store'), ['file' => csvUpload("a,b\nx,y\n")]);
    $import = Import::first();

    $this->actingAs($this->owner)->post(route('marketing.imports.start', $import), ['mapping' => ['first_name', null]])
        ->assertSessionHasErrors('mapping');

    $this->actingAs($this->owner)->post(route('marketing.imports.start', $import), ['mapping' => ['email', 'email']])
        ->assertSessionHasErrors('mapping');
});

test('a retried import resumes after the rows it already processed', function () {
    config(['marketing.imports.chunk_size' => 2]);
    $this->actingAs($this->owner)->post(route('marketing.imports.store'), [
        'file' => csvUpload("email\none@example.com\ntwo@example.com\nthree@example.com\n"),
    ]);
    $import = Import::first();
    $import->update(['column_mapping' => ['email'], 'status' => ImportStatus::Processing, 'processed_rows' => 2, 'created_count' => 2]);

    (new ProcessImport($import))->handle(app(CurrentWorkspace::class));

    expect($import->fresh()->created_count)->toBe(3)
        ->and($import->fresh()->processed_rows)->toBe(3)
        ->and(Contact::pluck('email')->all())->toBe(['three@example.com']);
});
