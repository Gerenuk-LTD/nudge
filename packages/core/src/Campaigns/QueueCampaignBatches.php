<?php

namespace Gerenuk\Nudge\Campaigns;

use Gerenuk\Nudge\Enums\RecipientStatus;
use Gerenuk\Nudge\Jobs\PrepareCampaign;
use Gerenuk\Nudge\Jobs\SendCampaignBatch;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\CampaignRecipient;
use Illuminate\Support\Collection;

/**
 * Queues send batches for every recipient of a campaign still waiting to be sent. Safe to call again:
 * each recipient is claimed before sending, so a recipient queued twice is still sent once.
 */
class QueueCampaignBatches
{
    public function handle(Campaign $campaign): void
    {
        $campaign->recipients()
            ->where('status', RecipientStatus::Queued)
            ->select('id')
            ->chunkById(PrepareCampaign::BATCH_SIZE, function (Collection $recipients) use ($campaign): void {
                SendCampaignBatch::dispatch($campaign->id, $campaign->workspace_id, array_values($recipients->map(fn (CampaignRecipient $recipient) => $recipient->id)->all()));
            });
    }
}
