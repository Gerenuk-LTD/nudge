<?php

use Gerenuk\Marketing\Actions\Contacts\UpsertContact;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Models\Tag;
use Gerenuk\Marketing\Segments\SegmentDefinitionValidator;
use Gerenuk\Marketing\Segments\SegmentFieldCatalog;
use Gerenuk\Marketing\Segments\SegmentQuery;
use Gerenuk\Marketing\Support\ContactData;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();

    CustomFieldDefinition::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);
    CustomFieldDefinition::create(['key' => 'seats', 'label' => 'Seats', 'type' => 'number']);
    CustomFieldDefinition::create(['key' => 'renewal', 'label' => 'Renewal', 'type' => 'date']);
    CustomFieldDefinition::create(['key' => 'trial', 'label' => 'Trial', 'type' => 'boolean']);

    $upsert = app(UpsertContact::class);
    $upsert->handle(ContactData::fromValidated(['email' => 'ann@example.com', 'first_name' => 'Ann', 'custom_fields' => ['plan' => 'Pro', 'seats' => 25, 'renewal' => '2026-12-01', 'trial' => false], 'tags' => ['VIP']]), ContactSource::Api);
    $upsert->handle(ContactData::fromValidated(['email' => 'bob@example.com', 'first_name' => 'Bob', 'custom_fields' => ['plan' => 'basic', 'seats' => 9, 'trial' => true]]), ContactSource::Import);
    $upsert->handle(ContactData::fromValidated(['email' => 'cat@test.org', 'first_name' => 'Cat', 'status' => 'unsubscribed']), ContactSource::Manual);
});

/**
 * @param  list<array<string, mixed>>  $conditions
 * @return list<string>
 */
function matchingEmails(array $conditions, string $match = 'all'): array
{
    $definition = ['match' => $match, 'conditions' => $conditions];
    app(SegmentDefinitionValidator::class)->validate($definition);

    return app(SegmentQuery::class)->contacts($definition)->orderBy('email')->pluck('email')->all();
}

function condition(string $field, string $operator, mixed $value = null): array
{
    return ['type' => 'condition', 'field' => $field, 'operator' => $operator, 'value' => $value];
}

test('an empty segment matches every active contact', function () {
    Contact::factory()->archived()->create();

    expect(matchingEmails([]))->toBe(['ann@example.com', 'bob@example.com', 'cat@test.org']);
});

test('text conditions are case-insensitive, including custom fields', function () {
    expect(matchingEmails([condition('custom:plan', 'equals', 'pro')]))->toBe(['ann@example.com'])
        ->and(matchingEmails([condition('email', 'ends_with', '@EXAMPLE.com')]))->toBe(['ann@example.com', 'bob@example.com'])
        ->and(matchingEmails([condition('custom:plan', 'contains', 'BAS')]))->toBe(['bob@example.com'])
        ->and(matchingEmails([condition('custom:plan', 'is_empty')]))->toBe(['cat@test.org'])
        ->and(matchingEmails([condition('first_name', 'not_equals', 'ann')]))->toBe(['bob@example.com', 'cat@test.org']);
});

test('like wildcards in values are matched literally', function () {
    expect(matchingEmails([condition('email', 'contains', '%')]))->toBe([]);
});

test('number conditions compare numerically, not as text', function () {
    expect(matchingEmails([condition('custom:seats', 'greater_than', 10)]))->toBe(['ann@example.com'])
        ->and(matchingEmails([condition('custom:seats', 'less_than', '10')]))->toBe(['bob@example.com']);
});

test('date and boolean conditions', function () {
    expect(matchingEmails([condition('custom:renewal', 'after', '2026-11-30')]))->toBe(['ann@example.com'])
        ->and(matchingEmails([condition('custom:renewal', 'before', '2026-12-01')]))->toBe([])
        ->and(matchingEmails([condition('created_at', 'within_last_days', 7)]))->toHaveCount(3)
        ->and(matchingEmails([condition('custom:trial', 'is_true')]))->toBe(['bob@example.com'])
        ->and(matchingEmails([condition('custom:trial', 'is_false')]))->toBe(['ann@example.com', 'cat@test.org']);
});

test('choice, tag and list conditions', function () {
    $vip = Tag::where('name', 'VIP')->first();
    $list = ContactList::factory()->create();
    $list->contacts()->attach(Contact::forEmail('bob@example.com')->first());

    expect(matchingEmails([condition('status', 'is', 'unsubscribed')]))->toBe(['cat@test.org'])
        ->and(matchingEmails([condition('source', 'is', 'import')]))->toBe(['bob@example.com'])
        ->and(matchingEmails([condition('tag', 'has', $vip->id)]))->toBe(['ann@example.com'])
        ->and(matchingEmails([condition('tag', 'has_not', $vip->id)]))->toBe(['bob@example.com', 'cat@test.org'])
        ->and(matchingEmails([condition('list', 'in', $list->id)]))->toBe(['bob@example.com']);
});

test('nested any and all groups combine correctly', function () {
    // Subscribed AND (plan is pro OR seats < 10)
    $emails = matchingEmails([
        condition('status', 'is', 'subscribed'),
        ['type' => 'group', 'match' => 'any', 'conditions' => [
            condition('custom:plan', 'equals', 'pro'),
            condition('custom:seats', 'less_than', 10),
        ]],
    ]);

    expect($emails)->toBe(['ann@example.com', 'bob@example.com']);

    // Top-level any: first name is Cat OR (plan is basic AND trial)
    $emails = matchingEmails([
        condition('first_name', 'equals', 'cat'),
        ['type' => 'group', 'match' => 'all', 'conditions' => [
            condition('custom:plan', 'equals', 'basic'),
            condition('custom:trial', 'is_true'),
        ]],
    ], 'any');

    expect($emails)->toBe(['bob@example.com', 'cat@test.org']);
});

test('segments only see tags and lists from their own workspace', function () {
    $this->createOwner('Globex');
    $foreignTag = Tag::create(['name' => 'Foreign']);
    $this->useWorkspace($this->workspace);

    matchingEmails([condition('tag', 'has', $foreignTag->id)]);
})->throws(ValidationException::class);

test('invalid definitions are rejected with a reason', function (array $definition) {
    app(SegmentDefinitionValidator::class)->validate($definition);
})->throws(ValidationException::class)->with([
    'unknown field' => [['match' => 'all', 'conditions' => [condition('password', 'equals', 'x')]]],
    'wrong operator for type' => [['match' => 'all', 'conditions' => [condition('custom:seats', 'contains', '1')]]],
    'non-numeric number' => [['match' => 'all', 'conditions' => [condition('custom:seats', 'greater_than', 'lots')]]],
    'bad date' => [['match' => 'all', 'conditions' => [condition('custom:renewal', 'before', '2026-02-31')]]],
    'zero days of activity' => [['match' => 'all', 'conditions' => [condition('activity:opened', 'in_last_days', 0)]]],
    'non-numeric days of activity' => [['match' => 'all', 'conditions' => [condition('activity:opened', 'in_last_days', 'abc')]]],
    'missing match' => [['conditions' => []]],
    'too deep' => [['match' => 'all', 'conditions' => [['type' => 'group', 'match' => 'all', 'conditions' => [['type' => 'group', 'match' => 'all', 'conditions' => [['type' => 'group', 'match' => 'all', 'conditions' => []]]]]]]]],
]);

test('the preview endpoint returns a count out of all active contacts, and a sample', function () {
    $this->actingAs($this->owner)
        ->postJson(route('marketing.segments.preview'), ['definition' => ['match' => 'all', 'conditions' => [condition('status', 'is', 'subscribed')]]])
        ->assertOk()
        ->assertJsonPath('count', 2)
        ->assertJsonPath('total', 3)
        ->assertJsonCount(2, 'sample');
});

test('segments can be saved and used to filter contacts', function () {
    $this->actingAs($this->owner)->post(route('marketing.segments.store'), [
        'name' => 'Pro customers',
        'definition' => ['match' => 'all', 'conditions' => [condition('custom:plan', 'equals', 'pro')]],
    ])->assertRedirect();

    $segment = Segment::first();

    $this->actingAs($this->owner)->get(route('marketing.contacts.index', ['segment' => $segment->id]))
        ->assertInertia(fn ($page) => $page->has('contacts.data', 1));
});

test('the segment list shows each segment\'s rules and its share of active contacts', function () {
    $definition = ['match' => 'any', 'conditions' => [condition('custom:plan', 'equals', 'pro')]];
    Segment::create(['name' => 'Pro customers', 'definition' => $definition]);

    $this->actingAs($this->owner)->get(route('marketing.segments.index'))
        ->assertInertia(fn ($page) => $page
            ->where('segments.0.definition', $definition)
            ->where('segments.0.contacts_count', 1)
            ->where('activeContacts', 3)
            ->has('fields'));
});

function recordEngagement(string $email, ?int $sentDaysAgo, ?int $openedDaysAgo = null, ?int $clickedDaysAgo = null): void
{
    CampaignRecipient::create([
        'campaign_id' => Campaign::factory()->create()->id,
        'contact_id' => Contact::query()->where('email', $email)->firstOrFail()->id,
        'email' => $email,
        'status' => RecipientStatus::Sent,
        'sent_at' => $sentDaysAgo === null ? null : now()->subDays($sentDaysAgo),
        'opened_at' => $openedDaysAgo === null ? null : now()->subDays($openedDaysAgo),
        'clicked_at' => $clickedDaysAgo === null ? null : now()->subDays($clickedDaysAgo),
    ]);
}

test('engagement conditions find who opened, clicked or received recently', function () {
    recordEngagement('ann@example.com', 10, 9, 9);
    recordEngagement('bob@example.com', 200, 199);

    expect(matchingEmails([condition('activity:opened', 'in_last_days', 30)]))->toBe(['ann@example.com'])
        ->and(matchingEmails([condition('activity:opened', 'in_last_days', 365)]))->toBe(['ann@example.com', 'bob@example.com'])
        ->and(matchingEmails([condition('activity:clicked', 'in_last_days', 365)]))->toBe(['ann@example.com'])
        ->and(matchingEmails([condition('activity:received', 'in_last_days', 30)]))->toBe(['ann@example.com']);
});

test('engagement conditions find who has gone quiet or never engaged', function () {
    recordEngagement('ann@example.com', 10, 9, 9);
    recordEngagement('bob@example.com', 200, 199);

    expect(matchingEmails([condition('activity:opened', 'not_in_last_days', 30)]))->toBe(['bob@example.com', 'cat@test.org'])
        ->and(matchingEmails([condition('activity:clicked', 'never')]))->toBe(['bob@example.com', 'cat@test.org'])
        ->and(matchingEmails([condition('activity:received', 'never')]))->toBe(['cat@test.org']);
});

test('engagement combines with other conditions', function () {
    recordEngagement('ann@example.com', 10, 9, 9);
    recordEngagement('bob@example.com', 200, 199);

    expect(matchingEmails([
        condition('activity:opened', 'in_last_days', 365),
        condition('status', 'is', 'subscribed'),
    ]))->toBe(['ann@example.com', 'bob@example.com']);
});

test('the field catalog offers the engagement fields with their operators', function () {
    expect(app(SegmentFieldCatalog::class)->find('activity:clicked')->toArray()['operators'])
        ->toBe(['in_last_days', 'not_in_last_days', 'never']);
});

function sentCampaign(string $name = 'November news'): Campaign
{
    return Campaign::factory()->create(['name' => $name, 'status' => CampaignStatus::Sent, 'sent_at' => now()]);
}

function recordRecipient(Campaign $campaign, string $email, bool $opened = false, bool $clicked = false): void
{
    CampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => Contact::query()->where('email', $email)->firstOrFail()->id,
        'email' => $email,
        'status' => RecipientStatus::Sent,
        'sent_at' => now()->subDay(),
        'opened_at' => $opened ? now() : null,
        'clicked_at' => $clicked ? now() : null,
    ]);
}

test('campaign conditions find who received, opened or clicked a given campaign', function () {
    $first = sentCampaign();
    $second = sentCampaign('December news');
    recordRecipient($first, 'ann@example.com', opened: true, clicked: true);
    recordRecipient($first, 'bob@example.com');
    recordRecipient($second, 'cat@test.org', opened: true);

    expect(matchingEmails([condition('campaign', 'received', $first->id)]))->toBe(['ann@example.com', 'bob@example.com'])
        ->and(matchingEmails([condition('campaign', 'opened', $first->id)]))->toBe(['ann@example.com'])
        ->and(matchingEmails([condition('campaign', 'clicked', $first->id)]))->toBe(['ann@example.com'])
        ->and(matchingEmails([condition('campaign', 'received', $first->id), condition('campaign', 'not_opened', $first->id)]))->toBe(['bob@example.com'])
        ->and(matchingEmails([condition('campaign', 'not_received', $first->id)]))->toBe(['cat@test.org'])
        ->and(matchingEmails([condition('campaign', 'not_clicked', $first->id)]))->toBe(['bob@example.com', 'cat@test.org']);
});

test('campaign conditions only accept sent campaigns from this workspace', function (string $which) {
    $draft = Campaign::factory()->create(['status' => CampaignStatus::Draft]);
    $this->createOwner('Globex');
    $foreign = sentCampaign('Foreign');
    $this->useWorkspace($this->workspace);

    matchingEmails([condition('campaign', 'opened', $which === 'draft' ? $draft->id : $foreign->id)]);
})->throws(ValidationException::class)->with(['draft', 'another workspace']);
