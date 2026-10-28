<?php

namespace Gerenuk\Marketing\Support;

use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Segments\SegmentQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters for contact listings, shared by the dashboard and the API.
 */
class ContactFilter
{
    public function __construct(private readonly SegmentQuery $segmentQuery) {}

    /**
     * @param  array{search?: string|null, status?: string|null, list?: int|string|null, tag?: int|string|null, segment?: int|string|null, archived?: bool|string|null}  $filters
     * @return Builder<Contact>
     */
    public function query(array $filters): Builder
    {
        $query = Contact::query();

        filter_var($filters['archived'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ? $query->whereNotNull('archived_at')
            : $query->active();

        if (filled($filters['search'] ?? null)) {
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filters['search'])));

            $query->where(fn (Builder $q) => $q
                ->whereRaw("lower(email) like ? escape '!'", ["%{$term}%"])
                ->orWhereRaw("lower(first_name) like ? escape '!'", ["%{$term}%"])
                ->orWhereRaw("lower(last_name) like ? escape '!'", ["%{$term}%"]));
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['list'] ?? null)) {
            $query->whereHas('lists', fn (Builder $lists) => $lists->whereKey((int) $filters['list']));
        }

        if (filled($filters['tag'] ?? null)) {
            $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey((int) $filters['tag']));
        }

        if (filled($filters['segment'] ?? null)) {
            $segment = Segment::findOrFail((int) $filters['segment']);
            $this->segmentQuery->apply($query, $segment->definition);
        }

        return $query;
    }
}
