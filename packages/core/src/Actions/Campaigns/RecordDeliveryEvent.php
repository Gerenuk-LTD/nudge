<?php

namespace Gerenuk\Marketing\Actions\Campaigns;

use Gerenuk\Marketing\Actions\Contacts\ChangeContactStatus;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Enums\SuppressionReason;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Sending\Webhooks\DeliveryEvent;
use Illuminate\Support\Facades\DB;

/**
 * Applies a provider's delivery event: updates the recipient and campaign counters, suppresses
 * bounced and complaining addresses, and records the event.
 */
class RecordDeliveryEvent
{
    public function handle(DeliveryEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            $recipient = $this->findRecipient($event);
            $email = Contact::normalizeEmail($recipient->email ?? $event->email);

            switch ($event->type) {
                case EventType::Delivered:
                    if ($recipient) {
                        $this->markFirst($recipient, 'delivered_at', 'delivered_count', $event, RecipientStatus::Delivered);
                    }
                    break;
                case EventType::Bounced:
                    $this->bounced($email, $recipient, $event);
                    break;
                case EventType::Complained:
                    $this->complained($email, $recipient, $event);
                    break;
                case EventType::Unsubscribed:
                    $this->unsubscribed($email, $recipient, $event);
                    break;
                case EventType::Dropped:
                    if ($recipient?->status === RecipientStatus::Sent) {
                        $recipient->update(['status' => RecipientStatus::Failed, 'error' => $event->reason]);
                    }
                    break;
                default:
                    break;
            }

            ActivityEvent::create([
                'contact_id' => $recipient->contact_id ?? Contact::query()->forEmail($email)->value('id'),
                'campaign_id' => $recipient?->campaign_id,
                'campaign_recipient_id' => $recipient?->id,
                'type' => $event->type,
                'data' => array_filter(['reason' => $event->reason, 'provider_message_id' => $event->providerMessageId]),
                'occurred_at' => $event->occurredAt,
            ]);
        });
    }

    private function bounced(string $email, ?CampaignRecipient $recipient, DeliveryEvent $event): void
    {
        Suppression::suppress($email, SuppressionReason::Bounce, $event->reason);

        if ($recipient) {
            $this->markFirst($recipient, 'bounced_at', 'bounced_count', $event, RecipientStatus::Bounced);
        }
    }

    private function complained(string $email, ?CampaignRecipient $recipient, DeliveryEvent $event): void
    {
        Suppression::suppress($email, SuppressionReason::Complaint, $event->reason);
        $this->unsubscribeContact($email);

        if ($recipient) {
            $this->markFirst($recipient, 'complained_at', 'complained_count', $event, RecipientStatus::Complained);
        }
    }

    private function unsubscribed(string $email, ?CampaignRecipient $recipient, DeliveryEvent $event): void
    {
        $this->unsubscribeContact($email);

        if ($recipient) {
            $this->markFirst($recipient, 'unsubscribed_at', 'unsubscribed_count', $event);
        }
    }

    private function unsubscribeContact(string $email): void
    {
        $contact = Contact::query()->forEmail($email)->first();

        if ($contact !== null && $contact->status !== ContactStatus::Unsubscribed) {
            (new ChangeContactStatus)->apply($contact, ContactStatus::Unsubscribed);
            $contact->save();
        }
    }

    /**
     * Set a first-time timestamp on the recipient and bump the campaign counter, once per recipient.
     * The status only moves forward from sent or delivered, so a late delivery event never hides a bounce.
     */
    private function markFirst(CampaignRecipient $recipient, string $column, string $counter, DeliveryEvent $event, ?RecipientStatus $status = null): void
    {
        $changed = CampaignRecipient::query()->whereKey($recipient->id)->whereNull($column)
            ->update([$column => $event->occurredAt]) === 1;

        if (! $changed) {
            return;
        }

        $recipient->campaign()->increment($counter);

        if ($status !== null) {
            CampaignRecipient::query()->whereKey($recipient->id)
                ->whereIn('status', [RecipientStatus::Sent, RecipientStatus::Delivered])
                ->update(['status' => $status]);
        }
    }

    /**
     * Match by the recipient ID we attached when sending, then by the provider's message ID.
     */
    private function findRecipient(DeliveryEvent $event): ?CampaignRecipient
    {
        if ($event->recipientId() !== null) {
            $recipient = CampaignRecipient::find($event->recipientId());

            if ($recipient !== null && $recipient->email === Contact::normalizeEmail($event->email)) {
                return $recipient;
            }
        }

        if ($event->providerMessageId === null || $event->providerMessageId === '') {
            return null;
        }

        // Some providers append a suffix to the ID they returned when sending (SendGrid: "id.filter...").
        $candidates = array_values(array_unique([$event->providerMessageId, strtok($event->providerMessageId, '.') ?: $event->providerMessageId]));

        return CampaignRecipient::query()
            ->whereIn('provider_message_id', $candidates)
            ->where('email', Contact::normalizeEmail($event->email))
            ->first();
    }
}
