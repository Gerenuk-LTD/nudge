<?php

namespace Gerenuk\Nudge\Sending\Webhooks;

use Carbon\CarbonImmutable;
use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Sending\MessageHeaders;
use Gerenuk\Nudge\Sending\ProviderType;
use Gerenuk\Nudge\Sending\WebhookVerification;
use Illuminate\Http\Request;
use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Verifies a provider's webhook request and turns it into delivery events.
 */
class WebhookParser
{
    public function __construct(private readonly SnsParser $sns) {}

    /**
     * @return list<DeliveryEvent>
     *
     * @throws RejectWebhookException when the request is not authentic or not understood
     */
    public function parse(Provider $provider, Request $request): array
    {
        if ($provider->type->webhookVerification() === WebhookVerification::Sns) {
            return $this->sns->parse($request, $provider);
        }

        $parser = $provider->type->requestParser()
            ?? throw new RejectWebhookException(404, 'This provider does not send webhooks.');

        if ($provider->type->webhookVerification() === WebhookVerification::SigningSecret && blank($provider->webhook_secret)) {
            throw new RejectWebhookException(403, 'Add the webhook signing key to this provider to accept its webhooks.');
        }

        $result = $parser->parse($request, (string) $provider->webhook_secret);
        $events = is_array($result) ? $result : ($result === null ? [] : [$result]);

        $normalized = [];

        foreach ($events as $event) {
            if ($event instanceof AbstractMailerEvent && ($type = self::typeFor($event, $provider->type)) !== null) {
                $normalized[] = new DeliveryEvent(
                    type: $type,
                    email: $event->getRecipientEmail(),
                    providerMessageId: $event->getId(),
                    metadata: [...$this->payloadMetadata($event), ...$event->getMetadata()],
                    occurredAt: CarbonImmutable::instance($event->getDate()),
                    reason: $event instanceof MailerDeliveryEvent ? ($event->getReason() ?: null) : null,
                );
            }
        }

        return $normalized;
    }

    private static function typeFor(RemoteEvent $event, ProviderType $type): ?EventType
    {
        return match ($event->getName()) {
            MailerDeliveryEvent::DELIVERED => EventType::Delivered,
            MailerDeliveryEvent::DEFERRED => EventType::Deferred,
            MailerDeliveryEvent::BOUNCE => BounceClassifier::isTemporary($type, $event->getPayload()) ? EventType::Deferred : EventType::Bounced,
            MailerDeliveryEvent::DROPPED => EventType::Dropped,
            MailerEngagementEvent::SPAM => EventType::Complained,
            MailerEngagementEvent::UNSUBSCRIBE => EventType::Unsubscribed,
            // Opens and clicks are tracked by our own pixel and redirects, so provider copies would double count.
            default => null,
        };
    }

    /**
     * Some providers (SendGrid) return our custom values at the top level of the payload rather than as metadata.
     *
     * @return array<string, mixed>
     */
    private function payloadMetadata(RemoteEvent $event): array
    {
        $payload = $event->getPayload();

        return array_intersect_key($payload, array_flip([MessageHeaders::RECIPIENT_KEY, MessageHeaders::WORKSPACE_KEY]));
    }
}
