<?php

use Gerenuk\Marketing\Actions\Campaigns\ScheduleCampaign;
use Gerenuk\Marketing\Campaigns\CampaignChecklist;
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
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Sending\ProviderTransports;
use Gerenuk\Marketing\Sending\ProviderType;
use Gerenuk\Marketing\Tests\Fixtures\CapturingTransports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

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

test('test sends are refused while sending is paused', function () {
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);
    $this->workspace->update(['sending_paused_at' => now(), 'sending_paused_reason' => 'Bounce rate']);

    $this->actingAs($this->owner)->post(route('marketing.campaigns.test', $campaign), ['emails' => ['me@example.com']])
        ->assertSessionHasErrors('emails');

    expect($this->transports->sent)->toBeEmpty();
});

test('test sends skip suppressed addresses and count against the daily limit', function () {
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);
    Suppression::suppress('bounced@example.com', SuppressionReason::Bounce);

    $this->actingAs($this->owner)->post(route('marketing.campaigns.test', $campaign), ['emails' => ['Bounced@example.com']])
        ->assertSessionHasErrors('emails');
    expect($this->transports->sent)->toBeEmpty();

    $this->actingAs($this->owner)->post(route('marketing.campaigns.test', $campaign), ['emails' => ['bounced@example.com', 'me@example.com']])
        ->assertSessionHasNoErrors();
    expect($this->transports->recipients())->toBe(['me@example.com']);

    $this->provider->update(['daily_limit' => 2]);

    $this->actingAs($this->owner)->post(route('marketing.campaigns.test', $campaign), ['emails' => ['me@example.com']])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('marketing.campaigns.test', $campaign), ['emails' => ['me@example.com']])
        ->assertSessionHasErrors('emails');
    expect($this->transports->sent)->toHaveCount(2);
});

test('test sends are rate limited', function () {
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    foreach (range(1, 20) as $attempt) {
        $this->actingAs($this->owner)->post(route('marketing.campaigns.test', $campaign), ['emails' => ["me{$attempt}@example.com"]])
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($this->owner)->post(route('marketing.campaigns.test', $campaign), ['emails' => ['me21@example.com']])
        ->assertTooManyRequests();
    expect($this->transports->sent)->toHaveCount(20);
});

test('sent campaigns cannot be edited', function () {
    Contact::factory()->create();
    $campaign = sendNow($this, Campaign::factory()->create(['provider_id' => $this->provider->id]));

    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['subject' => 'Too late'])
        ->assertSessionHasErrors('campaign');
});

test('the campaign history is filtered by status and sorted by open rate, leaving drafts out', function () {
    Campaign::factory()->create(['name' => 'Draft', 'provider_id' => $this->provider->id]);
    Campaign::factory()->create(['name' => 'Cancelled', 'provider_id' => $this->provider->id, 'status' => CampaignStatus::Cancelled]);
    Campaign::factory()->create(['name' => 'Half opened', 'provider_id' => $this->provider->id, 'status' => CampaignStatus::Sent])
        ->forceFill(['sent_count' => 10, 'opened_count' => 5])->save();
    Campaign::factory()->create(['name' => 'Mostly opened', 'provider_id' => $this->provider->id, 'status' => CampaignStatus::Sent])
        ->forceFill(['sent_count' => 4, 'opened_count' => 3])->save();

    $response = $this->actingAs($this->owner)->get(route('marketing.campaigns.index', ['status' => 'sent,draft', 'sort' => '-opened']));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('campaigns.data', 2)
        ->where('campaigns.data.0.name', 'Mostly opened')
        ->where('campaigns.data.1.name', 'Half opened'));
});

test('drafts and scheduled campaigns sit on the shelf, soonest scheduled first', function () {
    Campaign::factory()->create(['name' => 'Draft', 'provider_id' => $this->provider->id]);
    Campaign::factory()->create(['name' => 'Next week', 'provider_id' => $this->provider->id, 'status' => CampaignStatus::Scheduled, 'scheduled_at' => now()->addWeek()]);
    Campaign::factory()->create(['name' => 'Tomorrow', 'provider_id' => $this->provider->id, 'status' => CampaignStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
    Campaign::factory()->create(['name' => 'Sent', 'provider_id' => $this->provider->id, 'status' => CampaignStatus::Sent]);

    $response = $this->actingAs($this->owner)->get(route('marketing.campaigns.index'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('upcoming', fn ($upcoming) => collect($upcoming)->pluck('name')->all() === ['Tomorrow', 'Next week', 'Draft'])
        ->has('campaigns.data', 1)
        ->where('campaigns.data.0.name', 'Sent'));
});

test('the campaign editor shows how many people each audience choice reaches', function () {
    $newsletter = ContactList::factory()->create(['name' => 'Newsletter']);
    $empty = ContactList::factory()->create(['name' => 'Empty']);
    $ann = Contact::factory()->create(['email' => 'ann@example.com', 'first_name' => 'Ann']);
    $gone = Contact::factory()->unsubscribed()->create(['email' => 'gone@example.com']);
    $bounced = Contact::factory()->create(['email' => 'bounced@example.com']);
    Suppression::suppress('bounced@example.com', SuppressionReason::Bounce);
    $newsletter->contacts()->attach([$ann->id, $gone->id, $bounced->id]);
    $segment = Segment::create(['name' => 'Anns', 'definition' => ['match' => 'all', 'conditions' => [
        ['type' => 'condition', 'field' => 'first_name', 'operator' => 'equals', 'value' => 'Ann'],
    ]]]);
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    $this->actingAs($this->owner)->get(route('marketing.campaigns.edit', $campaign))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('audienceSizes.everyone', 1)
                ->where('audienceSizes.lists', [$newsletter->id => 1, $empty->id => 0])
                ->where('audienceSizes.segments', [$segment->id => 1])));
});

test('only approvers can switch campaign approval', function () {
    $this->actingAs($this->owner)->post(route('marketing.roles.store'), ['name' => 'Deliverability', 'permissions' => ['providers.manage']])
        ->assertRedirect();
    $manager = $this->addMember($this->workspace, 'Deliverability');
    $settings = ['postal_address' => "1 High Street\nLondon", 'requires_campaign_approval' => true];

    $this->actingAs($manager)->get(route('marketing.sending.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canChangeApproval', false));
    $this->actingAs($manager)->put(route('marketing.sending.update'), $settings)
        ->assertSessionHasErrors('requires_campaign_approval');
    expect($this->workspace->fresh()->requires_campaign_approval)->toBeFalse();

    $this->actingAs($manager)->put(route('marketing.sending.update'), [...$settings, 'requires_campaign_approval' => false, 'postal_address' => 'New address'])
        ->assertSessionHasNoErrors();
    expect($this->workspace->fresh()->postal_address)->toBe('New address');

    $this->actingAs($this->owner)->put(route('marketing.sending.update'), $settings)->assertSessionHasNoErrors();
    expect($this->workspace->fresh()->requires_campaign_approval)->toBeTrue();
});

test('the preheader is personalised for each recipient', function () {
    Contact::factory()->create(['email' => 'ann@example.com', 'first_name' => 'Ann']);

    sendNow($this, Campaign::factory()->create(['provider_id' => $this->provider->id, 'preheader' => 'For {{ first_name }}']));
    $html = (string) $this->transports->sent[0]->getHtmlBody();

    expect($html)->toContain('For Ann')->and($html)->not->toContain('{{ first_name }}');
});

test('the checklist flags merge tags that nothing will fill', function () {
    CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);
    $base = Campaign::factory()->make()->html;
    $campaign = Campaign::factory()->create([
        'provider_id' => $this->provider->id,
        'html' => $base.' {{ promo_code }} {{ custom.missing }} {{ maybe | none }} {{ custom.plan }}',
    ]);

    $item = collect(app(CampaignChecklist::class)->for($campaign, $this->workspace))->firstWhere('key', 'merge_tags');

    expect($item->passed)->toBeFalse()
        ->and($item->blocking)->toBeFalse()
        ->and($item->message)->not->toContain('promo_code')
        ->and($item->message)->toContain('{{ custom.missing }}')
        ->and($item->message)->not->toContain('maybe')
        ->and($item->message)->not->toContain('custom.plan');
});

test('a campaign using only known merge tags passes the merge tag check', function () {
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    $item = collect(app(CampaignChecklist::class)->for($campaign, $this->workspace))->firstWhere('key', 'merge_tags');

    expect($item->passed)->toBeTrue();
});

test('unfilled merge tags warn but do not block the send', function () {
    Contact::factory()->create(['email' => 'ann@example.com']);
    $campaign = Campaign::factory()->create([
        'provider_id' => $this->provider->id,
        'html' => Campaign::factory()->make()->html.' {{ custom.missing }}',
    ]);

    expect(sendNow($this, $campaign)->status)->toBe(CampaignStatus::Sent);
});

test('campaign variable values reach the email', function () {
    Contact::factory()->create(['email' => 'ann@example.com', 'first_name' => 'Ann']);
    $campaign = Campaign::factory()->create([
        'provider_id' => $this->provider->id,
        'subject' => 'Code {{ promo_code }}',
        'html' => '<p>Use {{ promo_code }}</p><p>Hi {{ first_name | there }}</p>',
        'preheader' => 'Ends {{ ends }}',
        'variables' => ['promo_code' => 'NOV<10>', 'ends' => 'Friday'],
    ]);

    sendNow($this, $campaign);
    $email = $this->transports->sent[0];
    $html = (string) $email->getHtmlBody();

    expect($email->getSubject())->toBe('Code NOV<10>')
        ->and($html)->toContain('Use NOV&lt;10&gt;')
        ->and($html)->toContain('Ends Friday')
        ->and($html)->not->toContain('{{ promo_code }}')
        ->and($html)->toContain('Hi Ann');
});

test('a variable with no value blocks the send', function () {
    Contact::factory()->create();
    $campaign = Campaign::factory()->create([
        'provider_id' => $this->provider->id,
        'html' => '<p>Use {{ promo_code }}</p>',
    ]);

    sendNow($this, $campaign);
})->throws(ValidationException::class);

test('a fallback makes a variable optional', function () {
    Contact::factory()->create();
    $campaign = Campaign::factory()->create([
        'provider_id' => $this->provider->id,
        'html' => '<p>Use {{ promo_code | none }}</p>',
    ]);

    sendNow($this, $campaign);

    expect((string) $this->transports->sent[0]->getHtmlBody())->toContain('Use none');
});

test('the snapshot keeps the variable values it was taken with', function () {
    Contact::factory()->create();
    $campaign = Campaign::factory()->create([
        'provider_id' => $this->provider->id,
        'html' => '<p>Use {{ promo_code }}</p>',
        'variables' => ['promo_code' => 'OLD'],
    ]);

    $campaign = sendNow($this, $campaign);
    $campaign->update(['variables' => ['promo_code' => 'NEW']]);

    expect($campaign->snapshot()->first()->html)->toContain('Use OLD')->not->toContain('NEW');
});

test('a subject and preheader that grow past 255 characters once variables are filled still prepare', function () {
    Contact::factory()->create();
    $campaign = Campaign::factory()->create([
        'provider_id' => $this->provider->id,
        'subject' => '{{ long }}',
        'preheader' => '{{ long }}',
        'variables' => ['long' => str_repeat('a', 300)],
    ]);

    $snapshot = sendNow($this, $campaign)->snapshot()->first();

    expect(mb_strlen($snapshot->subject))->toBe(300)
        ->and(mb_strlen((string) $snapshot->preheader))->toBe(300);
});

test('variable names are validated', function () {
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['variables' => ['Bad-Key' => 'x']])
        ->assertSessionHasErrors('variables');
    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['variables' => ['first_name' => 'x']])
        ->assertSessionHasErrors('variables');
    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['variables' => ['promo_code' => 'x']])
        ->assertSessionHasNoErrors();
});

test('variable names are at most 50 characters', function () {
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['variables' => [str_repeat('a', 51) => 'x']])
        ->assertSessionHasErrors('variables');
    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['variables' => [str_repeat('a', 50) => 'x']])
        ->assertSessionHasNoErrors();
});

test('changing variables withdraws approval', function () {
    $this->workspace->update(['requires_campaign_approval' => true]);
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    $this->actingAs($this->owner)->post(route('marketing.campaigns.approve', $campaign));
    expect($campaign->fresh()->approved_at)->not->toBeNull();

    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['variables' => ['promo_code' => 'x']]);
    expect($campaign->fresh()->approved_at)->toBeNull();
});

test('re-saving unchanged JSON content keeps approval when the database reorders its keys', function () {
    $this->workspace->update(['requires_campaign_approval' => true]);
    $editorState = ['type' => 'doc', 'content' => [['text' => 'Hi', 'marks' => []]]];
    $campaign = Campaign::factory()->create(['provider_id' => $this->provider->id, 'editor_state' => $editorState]);

    $this->actingAs($this->owner)->post(route('marketing.campaigns.approve', $campaign));

    // MySQL stores JSON object keys in its own order; simulate that on any database.
    DB::table('campaigns')->where('id', $campaign->id)
        ->update(['editor_state' => json_encode(['content' => [['marks' => [], 'text' => 'Hi']], 'type' => 'doc'])]);

    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['editor_state' => $editorState])
        ->assertSessionHasNoErrors();
    expect($campaign->fresh()->approved_at)->not->toBeNull();

    $this->actingAs($this->owner)->put(route('marketing.campaigns.update', $campaign), ['editor_state' => [...$editorState, 'type' => 'other']]);
    expect($campaign->fresh()->approved_at)->toBeNull();
});

test('a draft campaign can be deleted', function () {
    $draft = Campaign::factory()->create(['provider_id' => $this->provider->id]);

    $this->actingAs($this->owner)->delete(route('marketing.campaigns.destroy', $draft))
        ->assertRedirect(route('marketing.campaigns.index'));

    expect(Campaign::find($draft->id))->toBeNull();
});

test('a cancelled campaign is deleted along with its recipients', function () {
    Contact::factory()->create();
    $campaign = sendNow($this, Campaign::factory()->create(['provider_id' => $this->provider->id]));
    $campaign->update(['status' => CampaignStatus::Cancelled]);

    expect(CampaignRecipient::where('campaign_id', $campaign->id)->count())->toBeGreaterThan(0);

    $this->actingAs($this->owner)->delete(route('marketing.campaigns.destroy', $campaign))
        ->assertRedirect(route('marketing.campaigns.index'));

    expect(Campaign::find($campaign->id))->toBeNull();

    // The test database does not enforce foreign keys, so check the schema declares the cascade.
    $campaignKey = collect(Schema::getForeignKeys('campaign_recipients'))->firstWhere('columns', ['campaign_id']);

    expect($campaignKey['on_delete'])->toBe('cascade');
});

test('sent and scheduled campaigns cannot be deleted', function () {
    Contact::factory()->create();
    $sent = sendNow($this, Campaign::factory()->create(['provider_id' => $this->provider->id]));
    $scheduled = Campaign::factory()->create(['provider_id' => $this->provider->id, 'status' => CampaignStatus::Scheduled, 'scheduled_at' => now()->addDay()]);

    $this->actingAs($this->owner)->delete(route('marketing.campaigns.destroy', $sent))->assertForbidden();
    $this->actingAs($this->owner)->delete(route('marketing.campaigns.destroy', $scheduled))->assertForbidden();

    expect(Campaign::find($sent->id))->not->toBeNull()
        ->and(Campaign::find($scheduled->id))->not->toBeNull();
});

test('viewers cannot delete a campaign', function () {
    $draft = Campaign::factory()->create(['provider_id' => $this->provider->id]);
    $viewer = $this->addMember($this->workspace, 'Viewer');

    $this->actingAs($viewer)->delete(route('marketing.campaigns.destroy', $draft))->assertForbidden();

    expect(Campaign::find($draft->id))->not->toBeNull();
});
