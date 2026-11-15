<?php

namespace Gerenuk\Marketing\Http\Resources;

use Gerenuk\Marketing\Models\Segment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Segment
 */
class SegmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /**
             * The segment's rule definition.
             */
            'definition' => $this->definition,
            /**
             * The number of contacts that currently match the segment. Only present when getting a single segment, because counting runs its whole query.
             *
             * @var int
             */
            'contacts_count' => $this->whenHas('contacts_count'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
