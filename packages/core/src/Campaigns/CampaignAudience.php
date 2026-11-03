<?php

namespace Gerenuk\Marketing\Campaigns;

use Gerenuk\Marketing\Enums\AudienceType;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Segments\SegmentQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * The contacts a campaign will go to: its audience, minus anyone unsubscribed, paused, archived or suppressed.
 */
class CampaignAudience
{
    public function __construct(private readonly SegmentQuery $segmentQuery) {}

    /**
     * @return Builder<Contact>
     */
    public function query(Campaign $campaign): Builder
    {
        $query = $this->reachable();

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

    /**
     * Everyone a campaign could reach at all: subscribed, not paused, not archived and not suppressed.
     *
     * @return Builder<Contact>
     */
    public function reachable(): Builder
    {
        $query = Contact::query();
        $this->reachableContacts($query);

        return $query;
    }

    /**
     * @param  Builder<Contact>  $contacts
     */
    private function reachableContacts(Builder $contacts): void
    {
        $contacts
            ->active()
            ->where('status', ContactStatus::Subscribed)
            ->where(fn (Builder $query) => $query->whereNull('paused_until')->orWhere('paused_until', '<=', now()))
            ->whereNotIn('email', Suppression::query()->select('email'));
    }

    /**
     * How many people each audience choice would reach, so the choices can show their size.
     *
     * @return array{everyone: int, lists: array<int, int>, segments: array<int, int>}
     */
    public function sizes(): array
    {
        $lists = ContactList::query()
            ->withCount(['contacts as reachable_count' => $this->reachableContacts(...)])
            ->pluck('reachable_count', 'id')
            ->map(fn (mixed $count) => (int) $count);

        $segments = Segment::query()->get()->mapWithKeys(fn (Segment $segment) => [
            $segment->id => $this->segmentQuery->apply($this->reachable(), $segment->definition)->count(),
        ]);

        return [
            'everyone' => $this->reachable()->count(),
            'lists' => $lists->all(),
            'segments' => $segments->all(),
        ];
    }
}
