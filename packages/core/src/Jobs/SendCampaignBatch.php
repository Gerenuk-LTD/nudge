<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Sending\DeliveryOutcome;
use Gerenuk\Marketing\Sending\RecipientDelivery;
use Gerenuk\Marketing\Sending\SendRateLimiter;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Sends one batch of a campaign through its provider, within the provider's rate and daily limits.
 */
class SendCampaignBatch implements ShouldQueue
{
    use Queueable;

    /** Released jobs (paused workspace, daily cap) come back, so allow plenty of attempts. */
    public int $tries = 50;

    public int $timeout = 900;

    /**
     * @param  list<int>  $recipientIds
     */
    public function __construct(public int $campaignId, public int $workspaceId, public array $recipientIds)
    {
        $this->onQueue(config('marketing.sending.queue'));
    }

    public function handle(CurrentWorkspace $currentWorkspace, RecipientDelivery $delivery, SendRateLimiter $limiter): void
    {
        $workspace = Workspace::findOrFail($this->workspaceId);

        $currentWorkspace->runAs($workspace, function () use ($workspace, $delivery, $limiter): void {
            $campaign = Campaign::with(['snapshot', 'provider'])->find($this->campaignId);

            if ($campaign === null || $campaign->status !== CampaignStatus::Sending) {
                return;
            }

            if ($workspace->isSendingPaused()) {
                // Recipients stay queued; resuming sending (or the stalled-send sweep) queues them again.
                return;
            }

            $provider = $campaign->provider;
            $snapshot = $campaign->snapshot;

            if ($provider === null || $snapshot === null) {
                $this->fail(new RuntimeException("Campaign {$campaign->id} has no provider or snapshot."));

                return;
            }

            $linksByHash = $campaign->links()->get()->keyBy('url_hash')->all();

            $recipients = CampaignRecipient::with('contact')
                ->whereKey($this->recipientIds)
                ->where('status', RecipientStatus::Queued)
                ->get();

            foreach ($recipients as $recipient) {
                if ($delivery->deliver($campaign, $provider, $snapshot, $recipient, $workspace, $linksByHash) === DeliveryOutcome::OverDailyLimit) {
                    $this->release($limiter->secondsUntilTomorrow());

                    return;
                }
            }

            $provider->forceFill(['last_sent_at' => now()])->saveQuietly();

            self::finishIfDone($campaign);
        });
    }

    /**
     * Mark the campaign sent once no recipient is waiting.
     */
    public static function finishIfDone(Campaign $campaign): void
    {
        $waiting = $campaign->recipients()->whereIn('status', [RecipientStatus::Queued, RecipientStatus::Sending, RecipientStatus::Held])->exists();

        if (! $waiting) {
            Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending)
                ->update(['status' => CampaignStatus::Sent, 'sent_at' => now()]);
        }
    }
}
