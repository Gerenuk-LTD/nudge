<?php

use Gerenuk\Nudge\Actions\Imports\CreateImport;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\EmailTemplate;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Sending\ProviderType;
use Gerenuk\Nudge\Tests\Fixtures\User;
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
    $automation = Automation::factory()->create();
    $template = EmailTemplate::factory()->create();
    $import = app(CreateImport::class)->handle($workspace, $owner, UploadedFile::fake()->createWithContent('a.csv', "email\na@example.com\n"));

    $pages = [
        'nudge/home' => route('nudge.home'),
        'nudge/contacts/index' => route('nudge.contacts.index'),
        'nudge/lists/index' => route('nudge.lists.index'),
        'nudge/tags/index' => route('nudge.tags.index'),
        'nudge/custom-fields/index' => route('nudge.custom-fields.index'),
        'nudge/segments/index' => route('nudge.segments.index'),
        'nudge/templates/index' => route('nudge.templates.index'),
        'nudge/templates/form' => route('nudge.templates.edit', $template),
        'nudge/segments/form' => route('nudge.segments.edit', $segment),
        'nudge/imports/index' => route('nudge.imports.index'),
        'nudge/imports/create' => route('nudge.imports.create'),
        'nudge/imports/show' => route('nudge.imports.show', $import),
        'nudge/workspace/settings' => route('nudge.workspace.edit'),
        'nudge/workspace/members' => route('nudge.members.index'),
        'nudge/workspace/roles' => route('nudge.roles.index'),
        'nudge/workspace/api-tokens' => route('nudge.api-tokens.index'),
        'nudge/workspace/sending' => route('nudge.sending.edit'),
        'nudge/suppressions/index' => route('nudge.suppressions.index'),
        'nudge/providers/index' => route('nudge.providers.index'),
        'nudge/providers/form' => route('nudge.providers.create'),
        'nudge/providers/show' => route('nudge.providers.show', $provider),
        'nudge/campaigns/index' => route('nudge.campaigns.index'),
        'nudge/campaigns/edit' => route('nudge.campaigns.edit', $draft),
        'nudge/campaigns/show' => route('nudge.campaigns.show', $sent),
        'nudge/automations/index' => route('nudge.automations.index'),
        'nudge/automations/edit' => route('nudge.automations.edit', $automation),
    ];

    foreach ($pages as $component => $url) {
        $this->actingAs($owner)->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component($component)
                ->where('workspace.current.id', $workspace->id));
    }

    $this->actingAs($owner)->get(route('nudge.contacts.show', $contact))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('nudge/contacts/show')->where('contact.id', $contact->id));
});

test('pages outside a workspace render', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('nudge.workspaces.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('nudge/workspaces/create'));

    $this->actingAs($user)->get(route('nudge.invitations.show', 'unknown-token'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('nudge/invitations/show')->where('invitation', null));
});
