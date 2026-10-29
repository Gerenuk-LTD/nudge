<?php

namespace Gerenuk\Marketing\Support;

use Gerenuk\Marketing\Models\Campaign;

/**
 * A campaign as the dashboard shows it in lists, cards and reports.
 */
class CampaignSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'subject' => $campaign->subject,
            'status' => $campaign->status->value,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'sent_at' => $campaign->sent_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
            'updated_at' => $campaign->updated_at?->toIso8601String(),
            'stats' => [
                'recipients' => $campaign->recipients_count,
                'sent' => $campaign->sent_count,
                'failed' => $campaign->failed_count,
                'delivered' => $campaign->delivered_count,
                'bounced' => $campaign->bounced_count,
                'complained' => $campaign->complained_count,
                'opened' => $campaign->opened_count,
                'clicked' => $campaign->clicked_count,
                'unsubscribed' => $campaign->unsubscribed_count,
            ],
            'track_opens' => $campaign->track_opens,
            'track_clicks' => $campaign->track_clicks,
            'kind' => $campaign->kind->value,
            'automation_id' => $campaign->automation_id,
        ];
    }
}
