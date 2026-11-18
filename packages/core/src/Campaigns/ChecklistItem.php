<?php

namespace Gerenuk\Nudge\Campaigns;

final class ChecklistItem
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $passed,
        /** Blocking items stop the campaign being sent; others are warnings. */
        public readonly bool $blocking,
        public readonly ?string $message = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'passed' => $this->passed,
            'blocking' => $this->blocking,
            'message' => $this->message,
        ];
    }
}
