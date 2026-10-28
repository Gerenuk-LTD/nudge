<?php

use App\Models\User;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Segments\SegmentQuery;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;

test('it creates a demo workspace with contacts, campaigns and engagement', function () {
    $this->artisan('app:demo-data', ['--contacts' => 40, '--email' => 'demo@example.com'])->assertSuccessful();

    $user = User::query()->where('email', 'demo@example.com')->firstOrFail();
    $workspace = Workspace::query()->sole();

    expect($user->current_workspace_id)->toBe($workspace->id);

    app(CurrentWorkspace::class)->runAs($workspace, function () {
        expect(Contact::count())->toBe(40);

        $sent = Campaign::query()->where('status', CampaignStatus::Sent)->get();
        expect($sent)->toHaveCount(3)
            ->and($sent->sum('recipients_count'))->toBeGreaterThan(0)
            ->and($sent->sum('recipients_count'))->toBe($sent->sum('sent_count'));

        foreach (Segment::all() as $segment) {
            expect(app(SegmentQuery::class)->contacts($segment->definition)->count())->toBeInt();
        }
    });
});

test('it refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('app:demo-data')->assertFailed();

    expect(Workspace::query()->count())->toBe(0);
});
