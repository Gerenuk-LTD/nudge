<?php

use App\Models\User;
use Gerenuk\Nudge\Enums\AutomationStatus;
use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Enums\ImportStatus;
use Gerenuk\Nudge\Models\ActivityEvent;
use Gerenuk\Nudge\Models\ApiRequest;
use Gerenuk\Nudge\Models\ApiToken;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\AutomationRun;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\EmailTemplate;
use Gerenuk\Nudge\Models\Import;
use Gerenuk\Nudge\Models\Invitation;
use Gerenuk\Nudge\Models\Membership;
use Gerenuk\Nudge\Models\Role;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Segments\SegmentQuery;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Storage;

test('it creates a demo workspace with contacts, campaigns and engagement', function () {
    Storage::fake(config('nudge.imports.disk'));

    $this->artisan('app:demo-data', ['--contacts' => 40, '--email' => 'demo@example.com'])->assertSuccessful();

    $user = User::query()->where('email', 'demo@example.com')->firstOrFail();
    $workspace = Workspace::query()->sole();

    expect($user->current_workspace_id)->toBe($workspace->id)
        ->and($user->hasVerifiedEmail())->toBeTrue();

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

test('it adds a team, templates, imports, automation runs, an API log and custom events', function () {
    Storage::fake(config('nudge.imports.disk'));

    $this->artisan('app:demo-data', ['--contacts' => 40, '--email' => 'demo@example.com'])->assertSuccessful();

    app(CurrentWorkspace::class)->runAs(Workspace::query()->sole(), function () {
        expect(Membership::query()->count())->toBe(4)
            ->and(Role::query()->where('name', 'Importer')->exists())->toBeTrue()
            ->and(Invitation::query()->count())->toBe(1)
            ->and(EmailTemplate::query()->count())->toBe(3);

        expect(Import::query()->count())->toBe(2)
            ->and(Import::query()->where('status', ImportStatus::Completed)->sole()->errors()->count())->toBe(10)
            ->and(Import::query()->where('status', ImportStatus::Failed)->count())->toBe(1);

        $welcome = Automation::query()->where('name', 'Welcome series')->sole();
        expect(Automation::query()->count())->toBe(3)
            ->and($welcome->status)->toBe(AutomationStatus::Active)
            ->and(AutomationRun::query()->count())->toBeGreaterThan(0)
            ->and($welcome->entered_count)->toBe(AutomationRun::query()->count());

        $successfulReads = ApiRequest::query()->where('method', 'GET')->where('status', '<', 400);

        expect(ApiToken::query()->count())->toBe(2)
            ->and(ApiRequest::query()->count())->toBeGreaterThan(50)
            ->and($successfulReads->clone()->whereNotNull('request_body')->count())->toBe(0)
            ->and($successfulReads->clone()->whereNotNull('response_body')->count())->toBe(0)
            ->and(ActivityEvent::query()->where('type', EventType::Custom)->count())->toBeGreaterThan(0);
    });
});

test('every main page loads with demo data', function () {
    $this->withoutVite();

    Storage::fake(config('nudge.imports.disk'));

    $this->artisan('app:demo-data', ['--contacts' => 40, '--email' => 'demo@example.com'])->assertSuccessful();

    $owner = User::query()->where('email', 'demo@example.com')->firstOrFail();

    $urls = app(CurrentWorkspace::class)->runAs(Workspace::query()->sole(), fn () => [
        route('nudge.contacts.index'),
        route('nudge.contacts.show', ActivityEvent::query()->where('type', EventType::Custom)->firstOrFail()->contact_id),
        route('nudge.campaigns.index'),
        route('nudge.campaigns.show', Campaign::query()->where('status', CampaignStatus::Sent)->firstOrFail()),
        route('nudge.templates.index'),
        route('nudge.automations.index'),
        route('nudge.automations.edit', Automation::query()->where('name', 'Welcome series')->firstOrFail()),
        route('nudge.imports.index'),
        route('nudge.imports.show', Import::query()->where('status', ImportStatus::Completed)->firstOrFail()),
        route('nudge.segments.index'),
        route('nudge.members.index'),
        route('nudge.roles.index'),
        route('nudge.api-tokens.index'),
        route('nudge.api-log.index'),
    ]);

    foreach ($urls as $url) {
        $this->actingAs($owner)->get($url)->assertOk();
    }
});

test('seeding the database creates the demo workspace for the test user', function () {
    Storage::fake(config('nudge.imports.disk'));

    $this->seed();

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and(Workspace::query()->count())->toBe(1)
        ->and($user->current_workspace_id)->toBe(Workspace::query()->sole()->id);
});

test('it refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('app:demo-data')->assertFailed();

    expect(Workspace::query()->count())->toBe(0);
});
