<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Campaigns\AbTestResults;
use Gerenuk\Marketing\Campaigns\QueueCampaignBatches;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Picks the better variant of an A/B test and sends it to everyone who was held back.
 */
class PickAbTestWinner implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $campaignId, public int $workspaceId)
    {
        $this->onQueue(config('marketing.sending.queue'));
    }

    public function handle(CurrentWorkspace $currentWorkspace, AbTestResults $results): void
    {
        $currentWorkspace->runAs(Workspace::findOrFail($this->workspaceId), function () use ($results): void {
            $campaign = Campaign::find($this->campaignId);

            if ($campaign === null || $campaign->status !== CampaignStatus::Sending) {
                return;
            }

            // Only one copy of this job decides the winner; any copy may finish releasing the held recipients.
            $decided = false;

            if ($campaign->ab_winner === null) {
                $decided = Campaign::query()->whereKey($campaign->id)->whereNull('ab_winner')
                    ->update(['ab_winner' => $results->leader($campaign), 'ab_decided_at' => now()]) === 1;
            }

            $winner = Campaign::query()->whereKey($campaign->id)->value('ab_winner');

            $released = $campaign->recipients()->where('status', RecipientStatus::Held)
                ->update(['status' => RecipientStatus::Queued, 'variant' => $winner]);

            if ($decided || $released > 0) {
                app(QueueCampaignBatches::class)->handle($campaign);
            }

            SendCampaignBatch::finishIfDone($campaign);
        });
    }
}
