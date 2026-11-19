<?php

use Gerenuk\Nudge\Enums\SuppressionReason;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\Suppression;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    [$this->owner, $this->workspace] = $this->createOwner();
});

test('several addresses can be blocked at once from a pasted list', function () {
    $this->actingAs($this->owner)->post(route('nudge.suppressions.store'), [
        'emails' => "Jane@Example.com, bob@example.com\n\njane@example.com;cat@example.org",
        'note' => 'Asked by phone',
    ])->assertRedirect(route('nudge.suppressions.index'));

    expect(Suppression::orderBy('email')->pluck('email')->all())->toBe(['bob@example.com', 'cat@example.org', 'jane@example.com'])
        ->and(Suppression::first())->reason->toBe(SuppressionReason::Manual)->detail->toBe('Asked by phone');
});

test('blocking reports the addresses it cannot read and blocks none of them', function () {
    $this->actingAs($this->owner)->post(route('nudge.suppressions.store'), ['emails' => 'good@example.com, not-an-email'])
        ->assertSessionHasErrors(['emails' => 'This is not an email address: not-an-email']);

    expect(Suppression::count())->toBe(0);
});

test('the list counts each reason and links addresses that belong to contacts', function () {
    $contact = Contact::factory()->create(['email' => 'jane@example.com', 'first_name' => 'Jane', 'last_name' => null]);
    Suppression::suppress('jane@example.com', SuppressionReason::Bounce);
    Suppression::suppress('spam@example.com', SuppressionReason::Complaint);
    Suppression::suppress('other@example.com', SuppressionReason::Bounce);

    $this->actingAs($this->owner)->get(route('nudge.suppressions.index', ['search' => 'jane']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts', ['bounce' => 2, 'complaint' => 1])
            ->has('suppressions.data', 1)
            ->where('suppressions.data.0.contact', ['id' => $contact->id, 'name' => 'Jane']));
});

test('exports neutralise spreadsheet formulas', function () {
    Suppression::suppress('jane@example.com', SuppressionReason::Bounce, '=HYPERLINK("http://x")');

    $download = $this->actingAs($this->owner)->get(route('nudge.suppressions.export'));

    expect($download->streamedContent())->toContain("'=HYPERLINK");
});

test('the whole list can be exported as a CSV', function () {
    Suppression::suppress('jane@example.com', SuppressionReason::Bounce, 'Mailbox does not exist');

    $download = $this->actingAs($this->owner)->get(route('nudge.suppressions.export'));

    $download->assertOk();
    expect($download->streamedContent())->toContain('jane@example.com,bounce,"Mailbox does not exist"');
});
