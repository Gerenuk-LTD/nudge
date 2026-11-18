<?php

namespace Gerenuk\Nudge\Actions\Campaigns;

use Carbon\CarbonInterface;
use Gerenuk\Nudge\Campaigns\CampaignChecklist;
use Gerenuk\Nudge\Campaigns\ChecklistItem;
use Gerenuk\Nudge\Enums\CampaignKind;
use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Jobs\PrepareCampaign;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\Workspace;
use Illuminate\Validation\ValidationException;

class ScheduleCampaign
{
    public function __construct(private readonly CampaignChecklist $checklist) {}

    /**
     * Schedule a campaign, or start it now when no time is given. Fails if a blocking check fails.
     */
    public function handle(Campaign $campaign, Workspace $workspace, ?CarbonInterface $sendAt = null): void
    {
        if ($campaign->kind !== CampaignKind::Broadcast || ! $campaign->status->isEditable()) {
            throw ValidationException::withMessages(['campaign' => __('This campaign has already been sent.')]);
        }

        $failures = array_filter(
            $this->checklist->for($campaign, $workspace),
            fn (ChecklistItem $item) => $item->blocking && ! $item->passed,
        );

        if ($failures !== []) {
            throw ValidationException::withMessages([
                'campaign' => array_values(array_map(fn (ChecklistItem $item) => (string) $item->message, $failures)),
            ]);
        }

        $campaign->update([
            'status' => CampaignStatus::Scheduled,
            'scheduled_at' => $sendAt ?? now(),
        ]);

        if ($sendAt === null || $sendAt->isPast()) {
            PrepareCampaign::dispatchFor($campaign);
        }
    }
}
