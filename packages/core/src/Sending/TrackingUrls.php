<?php

namespace Gerenuk\Nudge\Sending;

use Gerenuk\Nudge\Models\CampaignLink;
use Gerenuk\Nudge\Models\CampaignRecipient;
use Illuminate\Support\Facades\URL;

/**
 * Signed URLs for click tracking, the open pixel and unsubscribing. The signature stops anyone
 * guessing another recipient's links; it is short because it appears in every email.
 * Links signed with a previous APP_KEY keep working after key rotation, as long as that key is listed in APP_PREVIOUS_KEYS.
 */
class TrackingUrls
{
    public function click(CampaignRecipient $recipient, CampaignLink $link): string
    {
        return $this->url('nudge.track.click', [
            'recipient' => $recipient->id,
            'link' => $link->id,
            'signature' => $this->sign("click:{$recipient->id}:{$link->id}"),
        ]);
    }

    public function open(CampaignRecipient $recipient): string
    {
        return $this->url('nudge.track.open', [
            'recipient' => $recipient->id,
            'signature' => $this->sign("open:{$recipient->id}"),
        ]);
    }

    public function unsubscribe(CampaignRecipient $recipient): string
    {
        return $this->url('nudge.unsubscribe.show', [
            'recipient' => $recipient->id,
            'signature' => $this->sign("unsubscribe:{$recipient->id}"),
        ]);
    }

    /**
     * Where the unsubscribe page sends a request to pause emails. It shares the unsubscribe link's signature.
     */
    public function pause(CampaignRecipient $recipient): string
    {
        return $this->url('nudge.unsubscribe.pause', [
            'recipient' => $recipient->id,
            'signature' => $this->sign("unsubscribe:{$recipient->id}"),
        ]);
    }

    public function verify(string $payload, string $signature): bool
    {
        foreach ([config('app.key'), ...config('app.previous_keys', []), ...config('nudge.previous_link_keys', [])] as $key) {
            if (is_string($key) && $key !== '' && hash_equals($this->sign($payload, $key), $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * New links are always signed with the current key.
     */
    private function sign(string $payload, ?string $key = null): string
    {
        return substr(hash_hmac('sha256', $payload, 'nudge-tracking|'.($key ?? config('app.key'))), 0, 24);
    }

    /**
     * @param  array<string, int|string>  $parameters
     */
    private function url(string $route, array $parameters): string
    {
        $base = rtrim((string) (config('nudge.tracking_url') ?: config('app.url')), '/');

        return $base.URL::route($route, $parameters, absolute: false);
    }
}
