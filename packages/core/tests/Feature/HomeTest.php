<?php

use Gerenuk\Marketing\Enums\AutomationStatus;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Role;
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
