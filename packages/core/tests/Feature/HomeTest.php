<?php

use Gerenuk\Marketing\Enums\AutomationStatus;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\ImportStatus;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Import;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Models\Role;
use Gerenuk\Marketing\Sending\ProviderType;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
    $this->travelTo('2026-10-28 12:00:00');
});

test('home summarises audience growth, recent sending and what is coming up', function () {
    Contact::factory()->count(2)->create(['created_at' => '2026-10-27 09:00:00']);
    Contact::factory()->unsubscribed()->create(['created_at' => '2026-10-28 09:00:00']);
    $sent = Campaign::factory()->create(['name' => 'October news', 'status' => CampaignStatus::Sent, 'sent_at' => '2026-10-27 10:00:00']);
    $contacts = Contact::query()->take(2)->get();
    foreach ([['opened_at' => '2026-10-27 11:00:00'], ['opened_at' => null]] as $index => $engagement) {
        $contact = $contacts[$index];
        CampaignRecipient::create([
            'campaign_id' => $sent->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientStatus::Delivered,
            'sent_at' => '2026-10-27 10:00:00',
            ...$engagement,
        ]);
    }
    Campaign::factory()->create(['name' => 'Next week', 'status' => CampaignStatus::Scheduled, 'scheduled_at' => '2026-11-04 09:00:00']);
    Campaign::factory()->create(['name' => 'Half written']);
    Automation::factory()->create(['name' => 'Welcome', 'status' => AutomationStatus::Active]);

    $response = $this->actingAs($this->owner)->get(route('marketing.home'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('marketing/home')
        ->where('audience.subscribed', 2)
        ->has('audience.added', 30)
        ->where('audience.added.28', ['date' => '2026-10-27', 'count' => 2])
        ->where('audience.added.29', ['date' => '2026-10-28', 'count' => 1])
        ->where('sending.totals', ['sent' => 2, 'opened' => 1, 'clicked' => 0])
        ->where('sending.sent.28.count', 2)
        ->where('sending.next.name', 'Next week')
        ->where('sending.drafts.0.name', 'Half written')
        ->where('sending.recent.0.name', 'October news')
        ->where('automations.0.name', 'Welcome'));
});

test('home leaves out the sections a role cannot view', function () {
    $role = Role::create(['workspace_id' => $this->workspace->id, 'name' => 'Audience only', 'permissions' => ['contacts.view'], 'is_owner' => false]);
    $member = $this->addMember($this->workspace, $role->name);

    $response = $this->actingAs($member)->get(route('marketing.home'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('sending', null)
        ->where('automations', null)
        ->has('audience'));
});

test('home compares the period with the one before it', function () {
    Contact::factory()->create(['created_at' => '2026-09-20 09:00:00']);
    Contact::factory()->create(['created_at' => '2026-08-01 09:00:00']);

    $this->actingAs($this->owner)->get(route('marketing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('audience.previous_added', 1)
            ->where('sending.previous', ['sent' => 0, 'opened' => 0, 'clicked' => 0]));
});

test('home flags what stops or harms sending, most urgent first', function () {
    $this->workspace->update(['sending_paused_at' => now(), 'sending_paused_reason' => 'Bounce rate too high.', 'postal_address' => null]);
    $silent = Provider::create(['name' => 'Mailgun', 'type' => ProviderType::Mailgun, 'max_per_second' => 10, 'credentials' => ['api_key' => 'k', 'domain' => 'd', 'region' => 'us'], 'last_sent_at' => now()]);
    Provider::create(['name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 1, 'credentials' => ['host' => 'h', 'port' => '25', 'encryption' => 'starttls'], 'last_sent_at' => now()]);
    Import::create(['file_path' => 'imports/a.csv', 'original_filename' => 'a.csv', 'headers' => ['email'], 'status' => ImportStatus::Failed]);
    Import::create(['file_path' => 'imports/b.csv', 'original_filename' => 'b.csv', 'headers' => ['email'], 'status' => ImportStatus::Pending]);
    $this->travel(8)->days();
    Import::create(['file_path' => 'imports/c.csv', 'original_filename' => 'c.csv', 'headers' => ['email'], 'status' => ImportStatus::Failed]);

    $this->actingAs($this->owner)->get(route('marketing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notices.0.key', 'paused')
            ->where('notices.0.type', 'danger')
            ->where('notices.1.key', 'no-postal-address')
            ->where('notices.2.key', "webhooks-{$silent->id}")
            ->where('notices.2.action.href', route('marketing.providers.show', $silent))
            ->where('notices.3.key', 'failed-imports')
            ->where('notices.3.heading', 'An import failed')
            ->where('notices.4.key', 'pending-imports')
            ->has('notices', 5));
});

test('home warns when no provider is connected and only links fixes the person can make', function () {
    $role = Role::create(['workspace_id' => $this->workspace->id, 'name' => 'Audience reader', 'permissions' => ['contacts.view'], 'is_owner' => false]);
    $member = $this->addMember($this->workspace, $role->name);
    $this->workspace->update(['postal_address' => '1 High Street']);

    $this->actingAs($member)->get(route('marketing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notices.0.key', 'no-provider')
            ->where('notices.0.action', null)
            ->has('notices', 1)
            ->where('setup', null));
});

test('the setup checklist tracks the first steps and disappears once they are done', function () {
    $this->workspace->update(['postal_address' => '1 High Street']);
    Contact::factory()->create();

    $this->actingAs($this->owner)->get(route('marketing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('setup.0.done', false)
            ->where('setup.1.done', true)
            ->where('setup.2.done', true)
            ->where('setup.3.done', false));

    Provider::create(['name' => 'SMTP', 'type' => ProviderType::Smtp, 'max_per_second' => 1, 'credentials' => ['host' => 'h', 'port' => '25', 'encryption' => 'starttls']]);
    Campaign::factory()->create(['status' => CampaignStatus::Sent, 'sent_at' => now()]);

    $this->actingAs($this->owner)->get(route('marketing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('setup', null));
});
