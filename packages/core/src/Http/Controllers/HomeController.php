<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Enums\AutomationRunStatus;
use Gerenuk\Marketing\Enums\AutomationStatus;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Support\CampaignSummary;
use Gerenuk\Marketing\Support\DailySeries;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace overview: audience growth, recent sending and what is coming up.
 * Each section is only sent to people allowed to see the data behind it.
 */
class HomeController extends Controller
{
    private const DAYS = 30;

    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function __invoke(Request $request): Response
    {
        $user = $this->user($request);
        $workspace = $this->currentWorkspace->getOrFail();
        $can = fn (Permission $permission): bool => $user->hasWorkspacePermission($workspace, $permission);

        return Inertia::render('marketing/home', [
            'days' => self::DAYS,
            'audience' => $can(Permission::ContactsView) ? $this->audience() : null,
            'sending' => $can(Permission::CampaignsView) ? $this->sending() : null,
            'automations' => $can(Permission::AutomationsView) ? $this->automations() : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function audience(): array
    {
        $contacts = Contact::query()->active();

        return [
            'subscribed' => $contacts->clone()->where('status', ContactStatus::Subscribed)->count(),
            'added' => DailySeries::count($contacts, 'created_at', self::DAYS),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sending(): array
    {
        $since = Date::today()->subDays(self::DAYS - 1);
        $recipients = CampaignRecipient::query()->whereNotNull('sent_at')->where('sent_at', '>=', $since);

        return [
            'sent' => DailySeries::count(CampaignRecipient::query(), 'sent_at', self::DAYS),
            'totals' => [
                'sent' => $recipients->clone()->count(),
                'opened' => $recipients->clone()->whereNotNull('opened_at')->count(),
                'clicked' => $recipients->clone()->whereNotNull('clicked_at')->count(),
            ],
            'next' => ($next = Campaign::query()->broadcasts()->where('status', CampaignStatus::Scheduled)->orderBy('scheduled_at')->first())
                ? CampaignSummary::from($next)
                : null,
            'drafts' => Campaign::query()->broadcasts()->where('status', CampaignStatus::Draft)->latest('updated_at')->limit(3)->get()
                ->map(fn (Campaign $campaign) => CampaignSummary::from($campaign)),
            'recent' => Campaign::query()->broadcasts()->where('status', CampaignStatus::Sent)->latest('sent_at')->limit(5)->get()
                ->map(fn (Campaign $campaign) => CampaignSummary::from($campaign)),
        ];
    }

    /**
     * @return list<array{id: int, name: string, active_count: int}>
     */
    private function automations(): array
    {
        return array_values(Automation::query()
            ->where('status', AutomationStatus::Active)
            ->withCount(['runs as active_count' => fn ($query) => $query->whereIn('status', [AutomationRunStatus::Active, AutomationRunStatus::Waiting])])
            ->orderByDesc('active_count')
            ->limit(5)
            ->get()
            ->map(fn (Automation $automation) => [
                'id' => $automation->id,
                'name' => $automation->name,
                'active_count' => (int) $automation->getAttribute('active_count'),
            ])
            ->all());
    }
}
