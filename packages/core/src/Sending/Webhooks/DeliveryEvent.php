<?php

namespace Gerenuk\Nudge\Sending\Webhooks;

use Carbon\CarbonImmutable;
use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Sending\MessageHeaders;

/**
 * A provider webhook event in one shape, whichever provider sent it. Serialisable for the queue.
 */
final class DeliveryEvent
{
    /**
     * @param  array<string, mixed>  $metadata  Values we attached when sending, if the provider returns them
     */
    public function __construct(
        public readonly EventType $type,
        public readonly string $email,
        public readonly ?string $providerMessageId,
        public readonly array $metadata,
        public readonly CarbonImmutable $occurredAt,
        public readonly ?string $reason = null,
    ) {}

    public function recipientId(): ?int
    {
        $id = $this->metadata[MessageHeaders::RECIPIENT_KEY] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}
