<?php

namespace Gerenuk\Marketing\Actions\Campaigns;

use Gerenuk\Marketing\Actions\Contacts\ChangeContactStatus;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\CampaignLink;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Illuminate\Support\Facades\DB;

/**
 * Records opens, clicks and unsubscribes from our own tracking links.
 */
class RecordEngagement
{
    /** Security scanners fetch links within seconds of delivery; real people rarely do. */
    private const SCANNER_WINDOW_SECONDS = 10;

    private const SCANNER_AGENTS = ['barracuda', 'mimecast', 'proofpoint', 'safelinks', 'microsoft office', 'urldefense', 'bot', 'crawler', 'spider', 'headless', 'python-requests', 'curl/'];

    public function click(CampaignRecipient $recipient, CampaignLink $link, ?string $userAgent): void
    {
        $suspected = $this->looksAutomated($recipient, $userAgent);

        DB::transaction(function () use ($recipient, $link, $userAgent, $suspected): void {
            $firstForLink = ! $suspected && ! ActivityEvent::query()
                ->where('campaign_recipient_id', $recipient->id)
                ->where('type', EventType::Clicked)
                ->where('data->link_id', $link->id)
                ->whereNull('data->suspected_bot')
                ->exists();

            ActivityEvent::recordFor($recipient, EventType::Clicked, array_filter([
                'link_id' => $link->id,
                'url' => $link->url,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                'suspected_bot' => $suspected ?: null,
            ]));

            // Automated clicks are recorded but kept out of the totals.
            if ($suspected) {
                return;
            }

            $link->increment('clicks_count');

            if ($firstForLink) {
                $link->increment('unique_clicks_count');
            }

            $this->markFirst($recipient, 'clicked_at', 'clicked_count');
        });
    }

    public function open(CampaignRecipient $recipient, ?string $userAgent): void
    {
        DB::transaction(function () use ($recipient, $userAgent): void {
            ActivityEvent::recordFor($recipient, EventType::Opened, array_filter([
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            ]));

            $this->markFirst($recipient, 'opened_at', 'opened_count');
        });
    }

    public function unsubscribe(CampaignRecipient $recipient): void
    {
        DB::transaction(function () use ($recipient): void {
            $contact = $recipient->contact;

            if ($contact->status !== ContactStatus::Unsubscribed) {
                (new ChangeContactStatus)->apply($contact, ContactStatus::Unsubscribed);
                $contact->save();
            }

            if ($this->markFirst($recipient, 'unsubscribed_at', 'unsubscribed_count')) {
                ActivityEvent::recordFor($recipient, EventType::Unsubscribed);
            }
        });
    }

    private function markFirst(CampaignRecipient $recipient, string $column, string $counter): bool
    {
        $changed = CampaignRecipient::query()->whereKey($recipient->id)->whereNull($column)->update([$column => now()]) === 1;

        if ($changed) {
            $recipient->campaign()->increment($counter);
        }

        return $changed;
    }

    private function looksAutomated(CampaignRecipient $recipient, ?string $userAgent): bool
    {
        $agent = mb_strtolower((string) $userAgent);

        foreach (self::SCANNER_AGENTS as $scanner) {
            if (str_contains($agent, $scanner)) {
                return true;
            }
        }

        return $recipient->sent_at !== null && $recipient->sent_at->diffInSeconds(now()) < self::SCANNER_WINDOW_SECONDS;
    }
}
