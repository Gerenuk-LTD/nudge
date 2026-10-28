<?php

namespace Gerenuk\Marketing\Sending\Webhooks;

use Carbon\CarbonImmutable;
use Gerenuk\Marketing\Enums\EventType;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Webhook\Exception\RejectWebhookException;
use Throwable;

/**
 * Amazon SES events delivered through an SNS topic. Every message's signature is checked against
 * the AWS signing certificate, and subscription confirmations are accepted automatically.
 */
class SnsParser
{
    private const CERTIFICATE_HOST = '/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/';

    public function __construct(private readonly Http $http) {}

    /**
     * @return list<DeliveryEvent>
     */
    public function parse(Request $request): array
    {
        $message = json_decode($request->getContent(), true);

        if (! is_array($message) || ! isset($message['Type'], $message['Signature'], $message['SigningCertURL'])) {
            throw new RejectWebhookException(406, 'Not an SNS message.');
        }

        $this->verify($message);

        if ($message['Type'] === 'SubscriptionConfirmation') {
            $this->confirmSubscription((string) $message['SubscribeURL']);

            return [];
        }

        if ($message['Type'] !== 'Notification') {
            return [];
        }

        $notification = json_decode((string) $message['Message'], true);

        return is_array($notification) ? $this->eventsFrom($notification) : [];
    }

    /**
     * Turn an SES event (configuration set event destination or identity notification) into delivery events.
     *
     * @param  array<string, mixed>  $notification
     * @return list<DeliveryEvent>
     */
    private function eventsFrom(array $notification): array
    {
        $kind = $notification['eventType'] ?? $notification['notificationType'] ?? null;
        $mail = self::arrayAt($notification, 'mail');
        $messageId = isset($mail['messageId']) ? (string) $mail['messageId'] : null;

        // SES returns message tags as {name: [value]}.
        $metadata = [];

        foreach (self::arrayAt($mail, 'tags') as $name => $values) {
            $metadata[(string) $name] = is_array($values) ? ($values[0] ?? null) : $values;
        }

        [$type, $details, $recipientsKey, $reason] = match ($kind) {
            'Delivery' => [EventType::Delivered, self::arrayAt($notification, 'delivery'), 'recipients', null],
            'Bounce' => [
                ($notification['bounce']['bounceType'] ?? null) === 'Permanent' ? EventType::Bounced : EventType::Deferred,
                self::arrayAt($notification, 'bounce'),
                'bouncedRecipients',
                trim(($notification['bounce']['bounceType'] ?? '').' '.($notification['bounce']['bounceSubType'] ?? '')),
            ],
            'Complaint' => [EventType::Complained, self::arrayAt($notification, 'complaint'), 'complainedRecipients', null],
            'Reject' => [EventType::Dropped, ['recipients' => self::arrayAt($mail, 'destination')], 'recipients', 'Rejected by SES'],
            default => [null, [], '', null],
        };

        if ($type === null) {
            return [];
        }

        $occurredAt = isset($details['timestamp']) ? CarbonImmutable::parse((string) $details['timestamp']) : CarbonImmutable::now();
        $events = [];

        foreach (self::arrayAt($details, $recipientsKey) as $recipient) {
            $email = is_array($recipient) ? ($recipient['emailAddress'] ?? null) : $recipient;

            if (is_string($email) && $email !== '') {
                $events[] = new DeliveryEvent($type, $email, $messageId, $metadata, $occurredAt, $reason ?: null);
            }
        }

        return $events;
    }

    /**
     * @param  array<array-key, mixed>  $array
     * @return array<array-key, mixed>
     */
    private static function arrayAt(array $array, string $key): array
    {
        return is_array($array[$key] ?? null) ? $array[$key] : [];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function verify(array $message): void
    {
        $certificateUrl = (string) $message['SigningCertURL'];
        $parts = parse_url($certificateUrl);

        if (($parts['scheme'] ?? null) !== 'https' || ! preg_match(self::CERTIFICATE_HOST, $parts['host'] ?? '') || ! str_ends_with($parts['path'] ?? '', '.pem')) {
            throw new RejectWebhookException(406, 'The signing certificate is not hosted by Amazon SNS.');
        }

        $certificate = Cache::remember('marketing:sns-cert:'.hash('sha256', $certificateUrl), now()->addDay(), function () use ($certificateUrl): string {
            return $this->http->timeout(10)->get($certificateUrl)->throw()->body();
        });

        $publicKey = openssl_pkey_get_public($certificate);
        $algorithm = ($message['SignatureVersion'] ?? '1') === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        $signature = base64_decode((string) $message['Signature'], true);

        if ($publicKey === false || $signature === false || openssl_verify($this->stringToSign($message), $signature, $publicKey, $algorithm) !== 1) {
            throw new RejectWebhookException(406, 'The SNS signature is not valid.');
        }
    }

    /**
     * The canonical string SNS signs, per message type.
     *
     * @param  array<string, mixed>  $message
     */
    private function stringToSign(array $message): string
    {
        $keys = $message['Type'] === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $signed = '';

        foreach ($keys as $key) {
            if (array_key_exists($key, $message)) {
                $signed .= $key."\n".$message[$key]."\n";
            }
        }

        return $signed;
    }

    private function confirmSubscription(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($host) || ! preg_match(self::CERTIFICATE_HOST, $host)) {
            throw new RejectWebhookException(406, 'The subscription URL is not an Amazon SNS address.');
        }

        try {
            $this->http->timeout(10)->get($url)->throw();
        } catch (Throwable $exception) {
            throw new RejectWebhookException(502, 'Could not confirm the SNS subscription.', $exception);
        }
    }
}
