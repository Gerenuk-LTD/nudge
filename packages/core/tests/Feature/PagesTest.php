<?php

use Gerenuk\Marketing\Actions\Imports\CreateImport;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Sending\ProviderType;
use Gerenuk\Marketing\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

test('every dashboard page renders its component', function () {
    Storage::fake('local');
    [$owner, $workspace] = $this->createOwner();
    $contact = Contact::factory()->create();
    $segment = Segment::factory()->create();
    $provider = Provider::create(['name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 1, 'credentials' => ['host' => 'h', 'port' => '25', 'encryption' => 'starttls']]);
    $draft = Campaign::factory()->create(['provider_id' => $provider->id]);
    $sent = Campaign::factory()->create(['provider_id' => $provider->id, 'status' => 'sent', 'sent_at' => now()]);
    $import = app(CreateImport::class)->handle($workspace, $owner, UploadedFile::fake()->createWithContent('a.csv', "email\na@example.com\n"));

    $pages = [
        'marketing/contacts/index' => route('marketing.contacts.index'),
        'marketing/contacts/form' => route('marketing.contacts.create'),
        'marketing/lists/index' => route('marketing.lists.index'),
        'marketing/tags/index' => route('marketing.tags.index'),
        'marketing/custom-fields/index' => route('marketing.custom-fields.index'),
        'marketing/segments/index' => route('marketing.segments.index'),
        'marketing/segments/form' => route('marketing.segments.edit', $segment),
        'marketing/imports/index' => route('marketing.imports.index'),
        'marketing/imports/create' => route('marketing.imports.create'),
        'marketing/imports/show' => route('marketing.imports.show', $import),
        'marketing/workspace/settings' => route('marketing.workspace.edit'),
        'marketing/workspace/members' => route('marketing.members.index'),
        'marketing/workspace/roles' => route('marketing.roles.index'),
        'marketing/workspace/api-tokens' => route('marketing.api-tokens.index'),
        'marketing/workspace/sending' => route('marketing.sending.edit'),
        'marketing/suppressions/index' => route('marketing.suppressions.index'),
        'marketing/providers/index' => route('marketing.providers.index'),
        'marketing/providers/form' => route('marketing.providers.create'),
        'marketing/providers/show' => route('marketing.providers.show', $provider),
        'marketing/campaigns/index' => route('marketing.campaigns.index'),
        'marketing/campaigns/edit' => route('marketing.campaigns.edit', $draft),
        'marketing/campaigns/show' => route('marketing.campaigns.show', $sent),
    ];

    foreach ($pages as $component => $url) {
        $this->actingAs($owner)->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component($component)
                ->where('workspace.current.id', $workspace->id));
    }

    $this->actingAs($owner)->get(route('marketing.contacts.show', $contact))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('marketing/contacts/form')->where('contact.id', $contact->id));
});

test('pages outside a workspace render', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('marketing.workspaces.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('marketing/workspaces/create'));

    $this->actingAs($user)->get(route('marketing.invitations.show', 'unknown-token'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('marketing/invitations/show')->where('invitation', null));
});
