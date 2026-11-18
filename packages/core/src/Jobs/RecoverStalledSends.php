<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Campaigns\QueueCampaignBatches;
use Gerenuk\Marketing\Enums\AutomationRunStatus;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Models\AutomationRun;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Sending\SendRateLimiter;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Cache;

/**
 * Runs every five minutes and unsticks sends and automation runs left behind by crashed workers, long pauses or failed jobs, in every workspace.
 */
class RecoverStalledSends
{
    /** Longer than SendCampaignBatch's 900s timeout, so a healthy send in progress is never touched. */
    public const STALE_SENDING_MINUTES = 30;

    /** Longer than the queue's retry_after (3660s), so a crashed job has had its own retry first. */
    public const STALE_PROGRESS_MINUTES = 120;

    public function __invoke(): void
    {
        $this->failStuckRecipients();
        $this->finishOrRequeueBroadcasts();
        $this->wakeStuckAutomationRuns();
    }

    /**
     * A recipient left claimed by a crash is never retried (to avoid a duplicate), so it is failed
     * to let its campaign finish.
     */
    private function failStuckRecipients(): void
    {
        $cutoff = now()->subMinutes(self::STALE_SENDING_MINUTES);

        $pairs = CampaignRecipient::withoutGlobalScopes()
            ->where('status', RecipientStatus::Sending)
            ->where('updated_at', '<', $cutoff)
            ->select(['workspace_id', 'campaign_id'])
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $workspace = Workspace::find($pair->workspace_id);

            if ($workspace === null) {
                continue;
            }

            app(CurrentWorkspace::class)->runAs($workspace, function () use ($pair, $cutoff): void {
                $failed = CampaignRecipient::query()
                    ->where('campaign_id', $pair->campaign_id)
                    ->where('status', RecipientStatus::Sending)
                    ->where('updated_at', '<', $cutoff)
                    ->update([
                        'status' => RecipientStatus::Failed,
                        'error' => __('The worker stopped before the send was confirmed. Not retried, to avoid a duplicate.'),
                    ]);

                if ($failed > 0) {
                    Campaign::query()->whereKey($pair->campaign_id)->increment('failed_count', $failed);
                }
            });
        }
    }

    /**
     * Finishes campaigns with nothing left to send, and queues the work again for campaigns that stopped making progress.
     */
    private function finishOrRequeueBroadcasts(): void
    {
        Campaign::withoutGlobalScopes()
            ->broadcasts()
            ->where('status', CampaignStatus::Sending)
            ->select(['id', 'workspace_id'])
            ->chunkById(100, function ($campaigns): void {
                foreach ($campaigns as $row) {
                    $workspace = Workspace::find($row->workspace_id);

                    if ($workspace === null) {
                        continue;
                    }

                    app(CurrentWorkspace::class)->runAs($workspace, function () use ($row, $workspace): void {
                        $campaign = Campaign::with(['provider', 'snapshot'])->find($row->id);

                        if ($campaign !== null) {
                            $this->recoverCampaign($campaign, $workspace);
                        }
                    });
                }
            });
    }

    private function recoverCampaign(Campaign $campaign, Workspace $workspace): void
    {
        SendCampaignBatch::finishIfDone($campaign);
        $campaign->refresh();

        if ($campaign->status !== CampaignStatus::Sending) {
            return;
        }

        if ($workspace->isSendingPaused() || $campaign->provider === null || $campaign->snapshot === null) {
            return;
        }

        if (! app(SendRateLimiter::class)->allowsToday($campaign->provider)) {
            return;
        }

        $staleSince = now()->subMinutes(self::STALE_PROGRESS_MINUTES);

        if ($campaign->recipients()->where('updated_at', '>=', $staleSince)->exists()) {
            return;
        }

        $hasHeld = $campaign->recipients()->where('status', RecipientStatus::Held)->exists();

        if ($campaign->ab_enabled && $campaign->ab_winner === null && $hasHeld
            && $campaign->sending_started_at !== null
            && $campaign->sending_started_at <= now()->subMinutes($campaign->ab_wait_minutes + self::STALE_PROGRESS_MINUTES)) {
            $this->once($campaign, fn () => PickAbTestWinner::dispatch($campaign->id, $campaign->workspace_id));

            return;
        }

        if ($campaign->ab_winner !== null && $hasHeld) {
            $this->once($campaign, fn () => PickAbTestWinner::dispatch($campaign->id, $campaign->workspace_id));

            return;
        }

        if ($campaign->recipients()->where('status', RecipientStatus::Queued)->exists()) {
            $this->once($campaign, fn () => app(QueueCampaignBatches::class)->handle($campaign));
        }
    }

    /**
     * Caps the safety net at one re-queue per campaign per window, so batches waiting behind a long queue do not pile up.
     */
    private function once(Campaign $campaign, callable $retry): void
    {
        if (Cache::add("marketing:recover:campaign:{$campaign->id}", true, now()->addMinutes(self::STALE_PROGRESS_MINUTES))) {
            $retry();
        }
    }

    /**
     * Wakes automation runs whose job was lost, so the every-minute advance picks them up again.
     */
    private function wakeStuckAutomationRuns(): void
    {
        AutomationRun::withoutGlobalScopes()
            ->where('status', AutomationRunStatus::Active)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_PROGRESS_MINUTES))
            ->update(['status' => AutomationRunStatus::Waiting, 'next_run_at' => now()]);
    }
}
