<?php

use Gerenuk\Marketing\Actions\Campaigns\ScheduleCampaign;
use Gerenuk\Marketing\Enums\AudienceType;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Enums\SuppressionReason;
use Gerenuk\Marketing\Jobs\DispatchDueCampaigns;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignLink;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Sending\ProviderTransports;
use Gerenuk\Marketing\Sending\ProviderType;
use Gerenuk\Marketing\Tests\Fixtures\CapturingTransports;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
    $this->workspace->update(['postal_address' => "1 High Street\nLondon"]);

    $this->transports = new CapturingTransports;
    $this->app->instance(ProviderTransports::class, $this->transports);

    $this->provider = Provider::create([
        'name' => 'Mailgun',
        'type' => ProviderType::Mailgun,
        'credentials' => ['api_key' => 'key', 'domain' => 'mg.acme.test', 'region' => 'eu'],
        'max_per_second' => 100,
    ]);
});

function sendNow($test, Campaign $campaign): Campaign
{
    app(ScheduleCampaign::class)->handle($campaign, $test->workspace->fresh());
    $test->useWorkspace($test->workspace);

    return $campaign->fresh();
}

test('a campaign goes to subscribed, active, unsuppressed contacts only', function () {
    Contact::factory()->create(['email' => 'ann@example.com', 'first_name' => 'Ann']);
    Contact::factory()->unsubscribed()->create(['email' => 'gone@example.com']);
    Contact::factory()->archived()->create(['email' => 'archived@example.com']);
    Contact::factory()->create(['email' => 'bounced@example.com']);
    Suppression::suppress('bounced@example.com', SuppressionReason::Bounce);

    $campaign = sendNow($this, Campaign::factory()->create(['provider_id' => $this->provider->id]));

    expect($this->transports->recipients())->toBe(['ann@example.com'])
        ->and($campaign->status)->toBe(CampaignStatus::Sent)
        ->and($campaign->recipients_count)->toBe(1)
        ->and($campaign->sent_count)->toBe(1)
        ->and(ActivityEvent::where('type', EventType::Sent)->count())->toBe(1);
});

test('the sent message is personalised, tracked and carries unsubscribe headers', function () {
    $contact = Contact::factory()->create(['email' => 'ann@example.com', 'first_name' => 'Ann <b>']);

    $campaign = sendNow($this, Campaign::factory()->create(['provider_id' => $this->provider->id, 'preheader' => 'Big news']));
    $email = $this->transports->sent[0];
    $recipient = CampaignRecipient::first();
    $html = (string) $email->getHtmlBody();

    expect($email->getSubject())->toBe('Hello Ann <b>')
        ->and($html)->toContain('Hi Ann &lt;b&gt;')
        ->and($html)->not->toContain('https://acme.test/offer')
        ->and($html)->toContain("/t/c/{$recipient->id}/")
        ->and($html)->toContain("/t/o/{$recipient->id}/")
        ->and($html)->toContain('1 High Street')
        ->and($html)->toContain('Big news')
        ->and($email->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString())->toBe('List-Unsubscribe=One-Click')
        ->and($email->getHeaders()->get('List-Unsubscribe')->getBodyAsString())->toContain("/u/{$recipient->id}/")
        ->and((string) $email->getTextBody())->toContain('Hi Ann <b>')
        ->and(CampaignLink::first()->url)->toBe('https://acme.test/offer?a=1&b=2')
        ->and($campaign->snapshot->subject)->toBe('Hello {{ first_name | there }}');
});

test('an unsubscribe link and postal address are added when the template has none', function () {
    Contact::factory()->create();

    sendNow($this, Campaign::factory()->create(['provider_id' => $this->provider->id, 'html' => '<p>Short</p>']));
    $html = (string) $this->transports->sent[0]->getHtmlBody();

    expect($html)->toContain('>Unsubscribe</a>')->and($html)->toContain('1 High Street<br />');
});

test('lists and segments limit the audience, and nobody is sent twice', function () {
    $list = ContactList::factory()->create();
    $onBoth = Contact::factory()->create(['email' => 'a@example.com']);
    Contact::factory()->create(['email' => 'b@example.com']);
    $list->contacts()->attach($onBoth);

    $campaign = Campaign::factory()->create([
        'provider_id' => $this->provider->id,
        'audience_type' => AudienceType::Lists,
        'list_ids' => [$list->id],
    ]);
    sendNow($this, $campaign);

    expect($this->transports->recipients())->toBe(['a@example.com']);
});

test('a failed send is recorded without stopping the batch', function () {
    Contact::factory()->create(['email' => 'ok@example.com']);
    Contact::factory()->create(['email' => 'rejected@example.com']);
    $this->transports->failFor = ['rejected@example.com'];

    $campaign = sendNow($this, Campaign::factory()->create(['provider_id' => $this->provider->id]));

    expect($campaign->sent_count)->toBe(1)
        ->and($campaign->failed_count)->toBe(1)
        ->and($campaign->status)->toBe(CampaignStatus::Sent)
        ->and(CampaignRecipient::where('status', RecipientStatus::Failed)->value('error'))->toBe('Rejected by the provider.');
});

test('blocking checklist failures stop the send', function () {
    $this->workspace->update(['postal_address' => null]);
    Contact::factory()->create();

    sendNow($this, Campaign::factory()->create(['provider_id' => $this->provider->id]));
})->throws(ValidationException::class);

test('approval is required when the workspace asks for it, and edits withdraw it', function () {
    $this->workspace->update(['requires_campaign_approval' => true]);
    Contact::factory()->create();
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    $this->actingAs($this->owner)->post(route('marketing.campaigns.schedule', $campaign))->assertSessionHasErrors('campaign');

    $this->actingAs($this->owner)->post(route('marketing.campaigns.approve', $campaign));
    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['subject' => 'Changed']);
    expect($campaign->fresh()->approved_at)->toBeNull();

    $this->actingAs($this->owner)->post(route('marketing.campaigns.approve', $campaign));
    $this->actingAs($this->owner)->post(route('marketing.campaigns.schedule', $campaign))->assertSessionHasNoErrors();

    $this->useWorkspace($this->workspace);
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Sent);
});

test('editors can send but cannot approve', function () {
    $editor = $this->addMember($this->workspace, 'Editor');
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    $this->actingAs($editor)->post(route('marketing.campaigns.approve', $campaign))->assertForbidden();
});

test('scheduled campaigns start when their time comes', function () {
    Contact::factory()->create();
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    $this->actingAs($this->owner)->post(route('marketing.campaigns.schedule', $campaign), ['send_at' => now()->addHour()->toIso8601String()])
        ->assertSessionHasNoErrors();

    $this->useWorkspace($this->workspace);
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled)->and($this->transports->sent)->toBe([]);

    $this->travel(61)->minutes();
    (new DispatchDueCampaigns)();

    $this->useWorkspace($this->workspace);
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Sent)->and($this->transports->sent)->toHaveCount(1);
});

test('a paused workspace does not send', function () {
    Contact::factory()->create();
    $this->workspace->update(['sending_paused_at' => now(), 'sending_paused_reason' => 'Bounce rate']);

    sendNow($this, Campaign::factory()->create(['provider_id' => $this->provider->id]));
})->throws(ValidationException::class);

test('the daily limit holds back the rest of a batch', function () {
    $this->provider->update(['daily_limit' => 2]);
    Contact::factory()->count(3)->create();

    $campaign = sendNow($this, Campaign::factory()->create(['provider_id' => $this->provider->id]));

    expect($this->transports->sent)->toHaveCount(2)
        ->and($campaign->status)->toBe(CampaignStatus::Sending)
        ->and(CampaignRecipient::where('status', RecipientStatus::Queued)->count())->toBe(1);
});

test('test emails go to the given address without tracking', function () {
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    $this->actingAs($this->owner)->post(route('marketing.campaigns.test', $campaign), ['emails' => ['me@example.com']])
        ->assertSessionHasNoErrors();

    $email = $this->transports->sent[0];

    expect($email->getSubject())->toStartWith('[Test]')
        ->and((string) $email->getHtmlBody())->toContain('https://acme.test/offer')
        ->and((string) $email->getHtmlBody())->not->toContain('/t/o/');
});

test('sent campaigns cannot be edited', function () {
    Contact::factory()->create();
    $campaign = sendNow($this, Campaign::factory()->create(['provider_id' => $this->provider->id]));

    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['subject' => 'Too late'])
        ->assertSessionHasErrors('campaign');
});
