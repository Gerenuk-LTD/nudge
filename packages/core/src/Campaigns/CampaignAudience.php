<?php

namespace Gerenuk\Marketing\Campaigns;

use Gerenuk\Marketing\Enums\AudienceType;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Segments\SegmentQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * The contacts a campaign will go to: its audience, minus anyone unsubscribed, archived or suppressed.
 */
class CampaignAudience
{
    public function __construct(private readonly SegmentQuery $segmentQuery) {}

    /**
     * @return Builder<Contact>
     */
    public function query(Campaign $campaign): Builder
    {
        $query = Contact::query()
            ->active()
            ->where('status', ContactStatus::Subscribed)
            ->whereNotIn('email', Suppression::query()->select('email'));

        return match ($campaign->audience_type) {
            AudienceType::AllContacts => $query,
            AudienceType::Lists => $query->whereHas('lists', fn (Builder $lists) => $lists->whereKey($campaign->list_ids ?? [])),
            AudienceType::Segment => $campaign->segment === null
                ? $query->whereRaw('1 = 0')
                : $this->segmentQuery->apply($query, $campaign->segment->definition),
        };
    }

    public function count(Campaign $campaign): int
    {
        return $this->query($campaign)->count();
    }
}
