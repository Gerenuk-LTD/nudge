<?php

namespace Gerenuk\Marketing\Sending;

use Symfony\Component\Mailer\Bridge\Brevo\RemoteEvent\BrevoPayloadConverter;
use Symfony\Component\Mailer\Bridge\Brevo\Webhook\BrevoRequestParser;
use Symfony\Component\Mailer\Bridge\Mailchimp\RemoteEvent\MailchimpPayloadConverter;
use Symfony\Component\Mailer\Bridge\Mailchimp\Webhook\MailchimpRequestParser;
use Symfony\Component\Mailer\Bridge\MailerSend\RemoteEvent\MailerSendPayloadConverter;
use Symfony\Component\Mailer\Bridge\MailerSend\Webhook\MailerSendRequestParser;
use Symfony\Component\Mailer\Bridge\Mailgun\RemoteEvent\MailgunPayloadConverter;
use Symfony\Component\Mailer\Bridge\Mailgun\Webhook\MailgunRequestParser;
use Symfony\Component\Mailer\Bridge\Mailjet\RemoteEvent\MailjetPayloadConverter;
use Symfony\Component\Mailer\Bridge\Mailjet\Webhook\MailjetRequestParser;
use Symfony\Component\Mailer\Bridge\Postmark\RemoteEvent\PostmarkPayloadConverter;
use Symfony\Component\Mailer\Bridge\Postmark\Webhook\PostmarkRequestParser;
use Symfony\Component\Mailer\Bridge\Resend\RemoteEvent\ResendPayloadConverter;
use Symfony\Component\Mailer\Bridge\Resend\Webhook\ResendRequestParser;
use Symfony\Component\Mailer\Bridge\Sendgrid\RemoteEvent\SendgridPayloadConverter;
use Symfony\Component\Mailer\Bridge\Sendgrid\Webhook\SendgridRequestParser;
use Symfony\Component\Webhook\Client\AbstractRequestParser;

/**
 * Every supported sending provider. Each one is a Symfony Mailer transport, built from a DSN.
 */
enum ProviderType: string
{
    case Smtp = 'smtp';
    case AmazonSes = 'ses';
    case Mailgun = 'mailgun';
    case Postmark = 'postmark';
    case SendGrid = 'sendgrid';
    case Brevo = 'brevo';
    case Mailjet = 'mailjet';
    case Resend = 'resend';
    case MailerSend = 'mailersend';
    case Mandrill = 'mandrill';
    case Scaleway = 'scaleway';

    public function label(): string
    {
        return match ($this) {
            self::Smtp => 'SMTP',
            self::AmazonSes => 'Amazon SES',
            self::Mailgun => 'Mailgun',
            self::Postmark => 'Postmark',
            self::SendGrid => 'SendGrid',
            self::Brevo => 'Brevo',
            self::Mailjet => 'Mailjet',
            self::Resend => 'Resend',
            self::MailerSend => 'MailerSend',
            self::Mandrill => 'Mailchimp Transactional (Mandrill)',
            self::Scaleway => 'Scaleway',
        };
    }

    /**
     * @return list<CredentialField>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Smtp => [
                new CredentialField('host', 'Host'),
                new CredentialField('port', 'Port', default: '587'),
                new CredentialField('encryption', 'Encryption', default: 'starttls', options: [
                    ['value' => 'starttls', 'label' => 'STARTTLS (usually port 587)'],
                    ['value' => 'tls', 'label' => 'TLS (usually port 465)'],
                ]),
                new CredentialField('username', 'Username', required: false),
                new CredentialField('password', 'Password', secret: true, required: false),
            ],
            self::AmazonSes => [
                new CredentialField('access_key_id', 'Access key ID'),
                new CredentialField('secret_access_key', 'Secret access key', secret: true),
                new CredentialField('region', 'Region', default: 'eu-west-1'),
                new CredentialField('configuration_set', 'Configuration set', required: false,
                    help: 'Needed for bounce, complaint and delivery events. Point its event destination at an SNS topic subscribed to the webhook URL.'),
            ],
            self::Mailgun => [
                new CredentialField('api_key', 'API key', secret: true),
                new CredentialField('domain', 'Sending domain'),
                new CredentialField('region', 'Region', default: 'us', options: [
                    ['value' => 'us', 'label' => 'US'],
                    ['value' => 'eu', 'label' => 'EU'],
                ]),
            ],
            self::Postmark => [
                new CredentialField('server_token', 'Server API token', secret: true),
                new CredentialField('message_stream', 'Message stream', default: 'broadcast',
                    help: 'Postmark requires marketing email to go through a broadcast stream.'),
            ],
            self::SendGrid => [
                new CredentialField('api_key', 'API key', secret: true),
                new CredentialField('region', 'Region', required: false, default: 'global', options: [
                    ['value' => 'global', 'label' => 'Global'],
                    ['value' => 'eu', 'label' => 'EU'],
                ]),
            ],
            self::Brevo, self::Resend, self::MailerSend, self::Mandrill => [
                new CredentialField('api_key', 'API key', secret: true),
            ],
            self::Mailjet => [
                new CredentialField('api_key', 'API key'),
                new CredentialField('secret_key', 'Secret key', secret: true),
            ],
            self::Scaleway => [
                new CredentialField('project_id', 'Project ID'),
                new CredentialField('secret_key', 'Secret key', secret: true),
                new CredentialField('region', 'Region', default: 'fr-par'),
            ],
        };
    }

    /**
     * The Symfony Mailer DSN for these credentials.
     *
     * @param  array<string, string|null>  $credentials
     */
    public function dsn(array $credentials): string
    {
        $value = fn (string $key): string => rawurlencode((string) ($credentials[$key] ?? ''));
        $query = fn (array $options): string => ($filtered = array_filter($options, fn ($option) => $option !== null && $option !== '')) === []
            ? ''
            : '?'.http_build_query($filtered);

        return match ($this) {
            self::Smtp => sprintf(
                '%s://%s@%s:%s',
                ($credentials['encryption'] ?? 'starttls') === 'tls' ? 'smtps' : 'smtp',
                ($credentials['username'] ?? '') === '' ? '' : $value('username').':'.$value('password'),
                $value('host'),
                $value('port'),
            ),
            self::AmazonSes => 'ses+api://'.$value('access_key_id').':'.$value('secret_access_key').'@default'.$query(['region' => $credentials['region'] ?? null]),
            self::Mailgun => 'mailgun+api://'.$value('api_key').':'.$value('domain').'@default'.$query(['region' => $credentials['region'] ?? null]),
            self::Postmark => 'postmark+api://'.$value('server_token').'@default'.$query(['message_stream' => $credentials['message_stream'] ?? null]),
            self::SendGrid => 'sendgrid+api://'.$value('api_key').'@default'.$query(['region' => ($credentials['region'] ?? 'global') === 'eu' ? 'eu' : null]),
            self::Brevo => 'brevo+api://'.$value('api_key').'@default',
            self::Mailjet => 'mailjet+api://'.$value('api_key').':'.$value('secret_key').'@default',
            self::Resend => 'resend+api://'.$value('api_key').'@default',
            self::MailerSend => 'mailersend+api://'.$value('api_key').'@default',
            self::Mandrill => 'mandrill+api://'.$value('api_key').'@default',
            self::Scaleway => 'scaleway+api://'.$value('project_id').':'.$value('secret_key').'@default'.$query(['region' => $credentials['region'] ?? null]),
        };
    }

    public function webhookVerification(): WebhookVerification
    {
        return match ($this) {
            self::Smtp, self::Scaleway => WebhookVerification::None,
            self::AmazonSes => WebhookVerification::Sns,
            self::Postmark, self::Brevo, self::Mailjet => WebhookVerification::BasicAuth,
            self::Mailgun, self::SendGrid, self::Resend, self::MailerSend, self::Mandrill => WebhookVerification::SigningSecret,
        };
    }

    /**
     * Where to find the signing secret, for providers that use one.
     */
    public function webhookSecretLabel(): ?string
    {
        return match ($this) {
            self::Mailgun => 'HTTP webhook signing key',
            self::SendGrid => 'Signed event webhook verification key',
            self::Resend => 'Webhook signing secret (whsec_...)',
            self::MailerSend => 'Webhook signing secret',
            self::Mandrill => 'Webhook authentication key',
            default => null,
        };
    }

    /**
     * Symfony's parser for this provider's webhooks, which checks the signature and normalises events.
     */
    public function requestParser(): ?AbstractRequestParser
    {
        return match ($this) {
            self::Mailgun => new MailgunRequestParser(new MailgunPayloadConverter),
            self::Postmark => new PostmarkRequestParser(new PostmarkPayloadConverter),
            self::SendGrid => new SendgridRequestParser(new SendgridPayloadConverter),
            self::Brevo => new BrevoRequestParser(new BrevoPayloadConverter),
            self::Mailjet => new MailjetRequestParser(new MailjetPayloadConverter),
            self::Resend => new ResendRequestParser(new ResendPayloadConverter),
            self::MailerSend => new MailerSendRequestParser(new MailerSendPayloadConverter),
            self::Mandrill => new MailchimpRequestParser(new MailchimpPayloadConverter),
            self::Smtp, self::AmazonSes, self::Scaleway => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'fields' => array_map(fn (CredentialField $field) => $field->toArray(), $this->fields()),
            'webhook_verification' => $this->webhookVerification()->value,
            'webhook_secret_label' => $this->webhookSecretLabel(),
        ];
    }
}
