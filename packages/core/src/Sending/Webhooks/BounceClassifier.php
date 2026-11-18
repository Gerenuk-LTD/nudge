<?php

namespace Gerenuk\Marketing\Sending\Webhooks;

use Gerenuk\Marketing\Sending\ProviderType;

/**
 * Tells a temporary bounce from a permanent one. Several providers report both under one "bounce"
 * event, and only permanent bounces should suppress an address.
 */
class BounceClassifier
{
    /**
     * @param  array<string, mixed>  $payload  The provider's raw webhook payload for one event
     */
    public static function isTemporary(ProviderType $type, array $payload): bool
    {
        return match ($type) {
            ProviderType::Brevo, ProviderType::Mandrill => ($payload['event'] ?? null) === 'soft_bounce',
            ProviderType::MailerSend => ($payload['type'] ?? null) === 'activity.soft_bounced',
            ProviderType::Postmark => ! in_array($payload['Type'] ?? null, ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'], true),
            ProviderType::SendGrid => ($payload['type'] ?? null) === 'blocked',
            ProviderType::Mailjet => ($payload['hard_bounce'] ?? true) === false,
            ProviderType::Resend => isset($payload['data']['bounce']['type']) && $payload['data']['bounce']['type'] !== 'Permanent',
            default => false,
        };
    }
}
