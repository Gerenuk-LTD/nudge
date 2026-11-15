<?php

namespace Gerenuk\Marketing\Http\Resources;

use Gerenuk\Marketing\Models\ActivityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityEvent
 */
class EventResource extends JsonResource
{
    /**
     * The record has its own `data` field, so without this Laravel would take it for the response envelope and not wrap it.
     */
    public static bool $forceWrapping = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            /**
             * Details of the event. For a `custom` event: `name` and `properties`.
             *
             * @var array<string, mixed>
             */
            'data' => (object) ($this->data ?? []),
            'campaign_id' => $this->campaign_id,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
