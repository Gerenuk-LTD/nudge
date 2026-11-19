<?php

namespace Gerenuk\Nudge\Support;

use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Segments\SegmentQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters for contact listings, shared by the dashboard and the API.
 * Status, list and tag take one value or several, comma-separated; a contact matching any of them is kept.
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

        TableQuery::search($query, $filters['search'] ?? null, ['email', 'first_name', 'last_name']);

        if (($statuses = TableQuery::values($filters['status'] ?? null)) !== []) {
            $query->whereIn('status', $statuses);
        }

        if (($listIds = self::ids($filters['list'] ?? null)) !== []) {
            $query->whereHas('lists', fn (Builder $lists) => $lists->whereKey($listIds));
        }

        if (($tagIds = self::ids($filters['tag'] ?? null)) !== []) {
            $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($tagIds));
        }

        if (filled($filters['segment'] ?? null)) {
            $segment = Segment::findOrFail((int) $filters['segment']);
            $this->segmentQuery->apply($query, $segment->definition);
        }

        return $query;
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        return array_map(intval(...), TableQuery::values($value));
    }
}
