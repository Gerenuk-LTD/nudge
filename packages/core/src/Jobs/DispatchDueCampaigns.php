<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Models\Campaign;

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
