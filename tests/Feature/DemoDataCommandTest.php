<?php

use App\Models\User;
use Gerenuk\Marketing\Enums\AutomationStatus;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Enums\ImportStatus;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\ApiRequest;
use Gerenuk\Marketing\Models\ApiToken;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\AutomationRun;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\EmailTemplate;
use Gerenuk\Marketing\Models\Import;
use Gerenuk\Marketing\Models\Invitation;
use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Role;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Segments\SegmentQuery;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Storage;

test('it creates a demo workspace with contacts, campaigns and engagement', function () {
    Storage::fake(config('marketing.imports.disk'));

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
    Storage::fake(config('marketing.imports.disk'));

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

    Storage::fake(config('marketing.imports.disk'));

    $this->artisan('app:demo-data', ['--contacts' => 40, '--email' => 'demo@example.com'])->assertSuccessful();

    $owner = User::query()->where('email', 'demo@example.com')->firstOrFail();

    $urls = app(CurrentWorkspace::class)->runAs(Workspace::query()->sole(), fn () => [
        route('marketing.contacts.index'),
        route('marketing.contacts.show', ActivityEvent::query()->where('type', EventType::Custom)->firstOrFail()->contact_id),
        route('marketing.campaigns.index'),
        route('marketing.campaigns.show', Campaign::query()->where('status', CampaignStatus::Sent)->firstOrFail()),
        route('marketing.templates.index'),
        route('marketing.automations.index'),
        route('marketing.automations.edit', Automation::query()->where('name', 'Welcome series')->firstOrFail()),
        route('marketing.imports.index'),
        route('marketing.imports.show', Import::query()->where('status', ImportStatus::Completed)->firstOrFail()),
        route('marketing.segments.index'),
        route('marketing.members.index'),
        route('marketing.roles.index'),
        route('marketing.api-tokens.index'),
        route('marketing.api-log.index'),
    ]);

    foreach ($urls as $url) {
        $this->actingAs($owner)->get($url)->assertOk();
    }
});

test('seeding the database creates the demo workspace for the test user', function () {
    Storage::fake(config('marketing.imports.disk'));

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
