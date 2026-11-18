<?php

namespace Gerenuk\Marketing\Sending;

use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignLink;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\CampaignSnapshot;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one queued recipient their copy of a campaign. Shared by broadcasts and automation emails.
 */
class RecipientDelivery
{
    public function __construct(
        private readonly ProviderTransports $transports,
        private readonly CampaignMessage $messages,
        private readonly SendRateLimiter $limiter,
    ) {}

    /**
     * @param  array<string, CampaignLink>  $linksByHash
     */
    public function deliver(Campaign $campaign, Provider $provider, CampaignSnapshot $snapshot, CampaignRecipient $recipient, Workspace $workspace, array $linksByHash): DeliveryOutcome
    {
        if (! $this->limiter->allowsToday($provider)) {
            return DeliveryOutcome::OverDailyLimit;
        }

        // Claim the recipient so no other worker sends it too. A recipient left claimed by a crash is
        // never retried, so nobody gets a duplicate.
        $claimed = CampaignRecipient::query()
            ->whereKey($recipient->id)
            ->where('status', RecipientStatus::Queued)
            ->update(['status' => RecipientStatus::Sending]) === 1;

        if (! $claimed) {
            return DeliveryOutcome::Skipped;
        }

        $contact = $recipient->contact;

        if ($contact->archived_at !== null || $contact->status !== ContactStatus::Subscribed || $contact->isPaused() || Suppression::where('email', $recipient->email)->exists()) {
            $recipient->update(['status' => RecipientStatus::Skipped]);

            return DeliveryOutcome::Skipped;
        }

        $this->limiter->waitForSlot($provider);

        try {
            $sent = $this->transports->for($provider)->send(
                $this->messages->build($campaign, $snapshot, $recipient, $contact, $workspace, $linksByHash, $provider->credentials['configuration_set'] ?? null),
            );
        } catch (Throwable $exception) {
            // Provider errors often quote the recipient's address, so the message stays in the app, not the log.
            Log::warning('Campaign send failed', [
                'campaign' => $campaign->id,
                'recipient' => $recipient->id,
                'provider' => $provider->id,
                'exception' => $exception::class,
            ]);

            $recipient->update(['status' => RecipientStatus::Failed, 'error' => mb_substr($exception->getMessage(), 0, 1000)]);
            $campaign->increment('failed_count');

            return DeliveryOutcome::Failed;
        }

        $recipient->update([
            'status' => RecipientStatus::Sent,
            'provider_message_id' => $sent?->getMessageId(),
            'sent_at' => now(),
        ]);
        $this->limiter->recordSend($provider);
        $campaign->increment('sent_count');
        ActivityEvent::recordFor($recipient, EventType::Sent, $recipient->variant === null ? [] : ['variant' => $recipient->variant]);

        return DeliveryOutcome::Sent;
    }
}
