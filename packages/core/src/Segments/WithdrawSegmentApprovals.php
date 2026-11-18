<?php

namespace Gerenuk\Nudge\Segments;

use Gerenuk\Nudge\Automations\SyncAutomationEmails;
use Gerenuk\Nudge\Enums\AudienceType;
use Gerenuk\Nudge\Enums\AutomationStatus;
use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * A segment decides who an approved campaign or "enters a segment" automation reaches, so changing its definition
 * withdraws those approvals unless the person changing it can approve. Scheduled campaigns go back to Draft and live
 * automations pause, in workspaces that require approval.
 */
class WithdrawSegmentApprovals
{
    public function __construct(private readonly SyncAutomationEmails $emails) {}

    /**
     * @return array{campaigns: int, automations: int} How many were affected, for the notice.
     */
    public function handle(Segment $segment, Workspace $workspace): array
    {
        return DB::transaction(function () use ($segment, $workspace): array {
            $campaigns = Campaign::query()
                ->broadcasts()
                ->where('audience_type', AudienceType::Segment)
                ->where('segment_id', $segment->id)
                ->whereIn('status', [CampaignStatus::Draft, CampaignStatus::Scheduled])
                ->whereNotNull('approved_at')
                ->get();

            foreach ($campaigns as $campaign) {
                $wasScheduled = $campaign->status === CampaignStatus::Scheduled;

                $campaign->update(['approved_at' => null, 'approved_by' => null]);

                if ($workspace->requires_campaign_approval && $wasScheduled) {
                    // Guarded, so one that has just started sending is not touched.
                    Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Scheduled)
                        ->update(['status' => CampaignStatus::Draft, 'scheduled_at' => null]);
                }
            }

            $automations = Automation::query()
                ->where('trigger_type', AutomationTrigger::SegmentEntered)
                ->whereNotNull('approved_at')
                ->get()
                ->filter(fn (Automation $automation) => (int) ($automation->trigger_config['segment_id'] ?? 0) === $segment->id);

            foreach ($automations as $automation) {
                $automation->fill(['approved_at' => null, 'approved_by' => null]);

                if ($workspace->requires_campaign_approval && $automation->status === AutomationStatus::Active) {
                    $automation->status = AutomationStatus::Paused;
                }

                $automation->save();
                $this->emails->handle($automation);
            }

            return ['campaigns' => $campaigns->count(), 'automations' => $automations->count()];
        });
    }
}
