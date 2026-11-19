<?php

namespace Gerenuk\Nudge\Jobs;

use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Models\Campaign;

/**
 * Runs every minute and starts scheduled campaigns whose time has come, in every workspace.
 */
class DispatchDueCampaigns
{
    public function __invoke(): void
    {
        Campaign::withoutGlobalScopes()
            ->broadcasts()
            ->where('status', CampaignStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->select(['id', 'workspace_id'])
            ->each(fn (Campaign $campaign) => PrepareCampaign::dispatchFor($campaign));
    }
}
