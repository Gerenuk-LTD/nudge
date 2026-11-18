<?php

namespace Gerenuk\Nudge\Jobs;

use Gerenuk\Nudge\Campaigns\AbTestResults;
use Gerenuk\Nudge\Campaigns\QueueCampaignBatches;
use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Enums\RecipientStatus;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Picks the better variant of an A/B test and sends it to everyone who was held back.
 */
class PickAbTestWinner implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $campaignId, public int $workspaceId, public bool $force = false)
    {
        $this->onQueue(config('nudge.sending.queue'));
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
                $snapshot = $results->for($campaign);

                if (! $this->force && $this->shouldKeepWaiting($campaign, $snapshot)) {
                    self::dispatch($this->campaignId, $this->workspaceId)->delay(now()->addMinutes(10));

                    return;
                }

                $decided = Campaign::query()->whereKey($campaign->id)->whereNull('ab_winner')
                    ->update([
                        'ab_winner' => $results->leaderOf($snapshot),
                        'ab_decided_at' => now(),
                        'ab_results' => json_encode($snapshot),
                    ]) === 1;
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

    /**
     * Whether the sample is still going out, or nothing has been sent yet, so there is no data to decide on.
     *
     * @param  array{a: array{sent: int, opened: int, clicked: int, rate: float}, b: array{sent: int, opened: int, clicked: int, rate: float}}  $snapshot
     */
    private function shouldKeepWaiting(Campaign $campaign, array $snapshot): bool
    {
        // Don't hold the rest of the audience forever if the sample never finishes sending.
        $cutoff = $campaign->sending_started_at?->copy()->addMinutes($campaign->ab_wait_minutes)->addDay();

        if ($cutoff !== null && now()->greaterThan($cutoff)) {
            return false;
        }

        $sampleStillWaiting = $campaign->recipients()
            ->whereIn('variant', ['a', 'b'])
            ->whereIn('status', [RecipientStatus::Queued, RecipientStatus::Sending])
            ->exists();

        return $sampleStillWaiting || ($snapshot['a']['sent'] === 0 && $snapshot['b']['sent'] === 0);
    }
}
