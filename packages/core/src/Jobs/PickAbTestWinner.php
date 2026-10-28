<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Campaigns\AbTestResults;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

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

            if ($campaign === null || $campaign->status !== CampaignStatus::Sending || $campaign->ab_winner !== null) {
                return;
            }

            $winner = $results->leader($campaign);

            // Only one copy of this job may release the held recipients.
            $decided = Campaign::query()->whereKey($campaign->id)->whereNull('ab_winner')
                ->update(['ab_winner' => $winner, 'ab_decided_at' => now()]) === 1;

            if (! $decided) {
                return;
            }

            $campaign->recipients()->where('status', RecipientStatus::Held)
                ->update(['status' => RecipientStatus::Queued, 'variant' => $winner]);

            $campaign->recipients()
                ->where('status', RecipientStatus::Queued)
                ->where('variant', $winner)
                ->whereNull('sent_at')
                ->select('id')
                ->chunkById(PrepareCampaign::BATCH_SIZE, function (Collection $recipients) use ($campaign): void {
                    SendCampaignBatch::dispatch($campaign->id, $campaign->workspace_id, array_values($recipients->map(fn (CampaignRecipient $recipient) => $recipient->id)->all()));
                });

            SendCampaignBatch::finishIfDone($campaign);
        });
    }
}
