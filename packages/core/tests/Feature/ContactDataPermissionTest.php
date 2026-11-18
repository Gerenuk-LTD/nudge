<?php

use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\ImportStatus;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Enums\SuppressionReason;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Import;
use Gerenuk\Marketing\Models\ImportError;
use Gerenuk\Marketing\Models\Role;
use Gerenuk\Marketing\Models\Suppression;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Storage::fake('local');
    [$this->owner, $this->workspace] = $this->createOwner();

    Role::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Writer',
        'permissions' => ['campaigns.view', 'campaigns.manage', 'segments.manage', 'contacts.import', 'contacts.manage'],
        'is_owner' => false,
    ]);
    $this->writer = $this->addMember($this->workspace, 'Writer');
});

function contactDataUpload($test, $user): Import
{
    $test->actingAs($user)->post(route('marketing.imports.store'), [
        'file' => UploadedFile::fake()->createWithContent('people.csv', "email,name\nann@example.com,Ann\nbob@example.com,Bob\n"),
    ])->assertRedirect();

    return Import::latest('id')->firstOrFail();
}

test('campaign recipients are hidden from members who cannot view contacts', function () {
    $contact = Contact::factory()->create(['email' => 'ann@example.com']);
    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sent, 'sent_at' => now()]);
    CampaignRecipient::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'email' => $contact->email, 'status' => RecipientStatus::Delivered, 'sent_at' => now()]);

    $this->actingAs($this->writer)->get(route('marketing.campaigns.show', $campaign))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canViewRecipients', false)
            ->where('recipients', null));

    $this->actingAs($this->owner)->get(route('marketing.campaigns.show', $campaign))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canViewRecipients', true)
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload->where('recipients.data.0.email', 'ann@example.com')));
});

test('the segment preview gives counts but no sample to members who cannot view contacts', function () {
    Contact::factory()->create(['email' => 'ann@example.com']);
    $definition = ['match' => 'all', 'conditions' => [['type' => 'condition', 'field' => 'status', 'operator' => 'is', 'value' => 'subscribed']]];

    $this->actingAs($this->writer)->postJson(route('marketing.segments.preview'), ['definition' => $definition])
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('sample', []);

    $this->actingAs($this->owner)->postJson(route('marketing.segments.preview'), ['definition' => $definition])
        ->assertOk()
        ->assertJsonPath('sample.0.email', 'ann@example.com');
});

test('another member\'s import rows are hidden from members who cannot view contacts', function () {
    $import = contactDataUpload($this, $this->owner);

    $this->actingAs($this->writer)->get(route('marketing.imports.show', $import))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSeeRows', false)
            ->where('sample', []));

    $this->actingAs($this->writer)->get(route('marketing.imports.errors', $import))->assertForbidden();
    $this->actingAs($this->owner)->get(route('marketing.imports.errors', $import))->assertOk();
});

test('the uploader still sees their own import rows', function () {
    $import = contactDataUpload($this, $this->writer);

    $this->actingAs($this->writer)->get(route('marketing.imports.show', $import))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSeeRows', true)
            ->has('sample', 2));

    $this->actingAs($this->writer)->get(route('marketing.imports.errors', $import))->assertOk();
});

test('failed row emails are hidden from members who cannot view contacts', function () {
    $import = contactDataUpload($this, $this->owner);
    $import->update(['status' => ImportStatus::Completed, 'failed_count' => 1]);
    ImportError::create(['import_id' => $import->id, 'row_number' => 2, 'email' => 'bad@example.com', 'message' => 'Not a valid email address.', 'created_at' => now()]);

    $this->actingAs($this->writer)->get(route('marketing.imports.show', $import))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('failedRows.0.email', null)
            ->where('failedRows.0.message', 'Not a valid email address.'));

    $this->actingAs($this->owner)->get(route('marketing.imports.show', $import))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('failedRows.0.email', 'bad@example.com'));
});

test('removing a suppression does not name the address to members who cannot view contacts', function () {
    $suppression = Suppression::suppress('bounced@example.com', SuppressionReason::Bounce);

    $this->actingAs($this->writer)->delete(route('marketing.suppressions.destroy', $suppression))->assertRedirect();

    expect(json_encode(session()->all()))->toContain('The address can be emailed again.')
        ->and(json_encode(session()->all()))->not->toContain('bounced@example.com');
});
