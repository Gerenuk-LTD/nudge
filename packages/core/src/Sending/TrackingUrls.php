<?php

namespace Gerenuk\Marketing\Sending;

use Gerenuk\Marketing\Models\CampaignLink;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Illuminate\Support\Facades\URL;

/**
 * Signed URLs for click tracking, the open pixel and unsubscribing. The signature stops anyone
 * guessing another recipient's links; it is short because it appears in every email.
 */
class TrackingUrls
{
    public function click(CampaignRecipient $recipient, CampaignLink $link): string
    {
        return $this->url('marketing.track.click', [
            'recipient' => $recipient->id,
            'link' => $link->id,
            'signature' => $this->sign("click:{$recipient->id}:{$link->id}"),
        ]);
    }

    public function open(CampaignRecipient $recipient): string
    {
        return $this->url('marketing.track.open', [
            'recipient' => $recipient->id,
            'signature' => $this->sign("open:{$recipient->id}"),
        ]);
    }

    public function unsubscribe(CampaignRecipient $recipient): string
    {
        return $this->url('marketing.unsubscribe.show', [
            'recipient' => $recipient->id,
            'signature' => $this->sign("unsubscribe:{$recipient->id}"),
        ]);
    }

    public function verify(string $payload, string $signature): bool
    {
        return hash_equals($this->sign($payload), $signature);
    }

    private function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, 'marketing-tracking|'.config('app.key')), 0, 24);
    }

    /**
     * @param  array<string, int|string>  $parameters
     */
    private function url(string $route, array $parameters): string
    {
        $base = rtrim((string) (config('marketing.tracking_url') ?: config('app.url')), '/');

        return $base.URL::route($route, $parameters, absolute: false);
    }
}
