<?php

namespace Gerenuk\Marketing\Campaigns;

use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;

/**
 * How each A/B variant performed among the test sample.
 */
class AbTestResults
{
    /**
     * Once a winner is chosen, the results from that moment are returned, so the rest of the audience doesn't blur the test.
     *
     * @return array{a: array{sent: int, opened: int, clicked: int, rate: float}, b: array{sent: int, opened: int, clicked: int, rate: float}}
     */
    public function for(Campaign $campaign): array
    {
        if ($campaign->ab_results !== null) {
            return $campaign->ab_results;
        }

        $rows = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('sent_at')
            ->whereIn('variant', ['a', 'b'])
            ->selectRaw('variant, count(*) as sent, count(opened_at) as opened, count(clicked_at) as clicked')
            ->groupBy('variant')
            ->get()
            ->keyBy('variant');

        $results = [];

        foreach (['a', 'b'] as $variant) {
            $sent = (int) ($rows[$variant]->sent ?? 0);
            $metric = (int) ($rows[$variant]->{$campaign->ab_metric === 'opens' ? 'opened' : 'clicked'} ?? 0);

            $results[$variant] = [
                'sent' => $sent,
                'opened' => (int) ($rows[$variant]->opened ?? 0),
                'clicked' => (int) ($rows[$variant]->clicked ?? 0),
                'rate' => $sent > 0 ? $metric / $sent : 0.0,
            ];
        }

        return $results;
    }

    /**
     * The variant ahead on the campaign's chosen metric. Ties go to A, the original.
     */
    public function leader(Campaign $campaign): string
    {
        return $this->leaderOf($this->for($campaign));
    }

    /**
     * @param  array{a: array{sent: int, opened: int, clicked: int, rate: float}, b: array{sent: int, opened: int, clicked: int, rate: float}}  $results
     */
    public function leaderOf(array $results): string
    {
        return $results['b']['rate'] > $results['a']['rate'] ? 'b' : 'a';
    }
}
