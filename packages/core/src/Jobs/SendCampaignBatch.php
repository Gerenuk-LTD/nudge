<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Sending\CampaignMessage;
use Gerenuk\Marketing\Sending\ProviderTransports;
use Gerenuk\Marketing\Sending\SendRateLimiter;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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

    public function handle(CurrentWorkspace $currentWorkspace, ProviderTransports $transports, CampaignMessage $messages, SendRateLimiter $limiter): void
    {
        $workspace = Workspace::findOrFail($this->workspaceId);

        $currentWorkspace->runAs($workspace, function () use ($workspace, $transports, $messages, $limiter): void {
            $campaign = Campaign::with(['snapshot', 'provider'])->find($this->campaignId);

            if ($campaign === null || $campaign->status !== CampaignStatus::Sending) {
                return;
            }

            if ($workspace->isSendingPaused()) {
                $this->release(300);

                return;
            }

            $provider = $campaign->provider;
            $snapshot = $campaign->snapshot;

            if ($provider === null || $snapshot === null) {
                $this->fail(new \RuntimeException("Campaign {$campaign->id} has no provider or snapshot."));

                return;
            }

            $transport = $transports->for($provider);
            $linksByHash = $campaign->links()->get()->keyBy('url_hash')->all();
            $configurationSet = $provider->credentials['configuration_set'] ?? null;

            $recipients = CampaignRecipient::with('contact')
                ->whereKey($this->recipientIds)
                ->where('status', RecipientStatus::Queued)
                ->get();

            foreach ($recipients as $recipient) {
                if (! $limiter->allowsToday($provider)) {
                    $this->release($limiter->secondsUntilTomorrow());

                    return;
                }

                // Claim the recipient so no other worker sends it too.
                $claimed = CampaignRecipient::query()
                    ->whereKey($recipient->id)
                    ->where('status', RecipientStatus::Queued)
                    ->update(['status' => RecipientStatus::Sending]) === 1;

                if (! $claimed) {
                    continue;
                }

                $contact = $recipient->contact;

                // Things can change between building the audience and sending.
                if ($contact->archived_at !== null || $contact->status !== ContactStatus::Subscribed || Suppression::where('email', $recipient->email)->exists()) {
                    $recipient->update(['status' => RecipientStatus::Skipped]);

                    continue;
                }

                $limiter->waitForSlot($provider);

                try {
                    $sent = $transport->send($messages->build($campaign, $snapshot, $recipient, $contact, $workspace, $linksByHash, $configurationSet));

                    $recipient->update([
                        'status' => RecipientStatus::Sent,
                        'provider_message_id' => $sent?->getMessageId(),
                        'sent_at' => now(),
                    ]);
                    $limiter->recordSend($provider);
                    $campaign->increment('sent_count');
                    ActivityEvent::recordFor($recipient, EventType::Sent);
                } catch (Throwable $exception) {
                    report($exception);

                    $recipient->update(['status' => RecipientStatus::Failed, 'error' => mb_substr($exception->getMessage(), 0, 1000)]);
                    $campaign->increment('failed_count');
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
        $waiting = $campaign->recipients()->whereIn('status', [RecipientStatus::Queued, RecipientStatus::Sending])->exists();

        if (! $waiting) {
            Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending)
                ->update(['status' => CampaignStatus::Sent, 'sent_at' => now()]);
        }
    }
}
