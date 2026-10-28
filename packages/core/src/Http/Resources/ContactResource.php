<?php

namespace Gerenuk\Marketing\Http\Resources;

use Gerenuk\Marketing\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'custom_fields' => (object) ($this->custom_fields ?? []),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'lists' => $this->whenLoaded('lists', fn () => $this->lists->pluck('id')),
            'unsubscribed_at' => $this->unsubscribed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
