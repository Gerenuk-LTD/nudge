<?php

namespace Gerenuk\Marketing\Actions\Providers;

use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Sending\CredentialField;
use Gerenuk\Marketing\Sending\ProviderType;
use Gerenuk\Marketing\Sending\WebhookVerification;
use Gerenuk\Marketing\Support\ProviderData;
use Illuminate\Support\Str;

class SaveProvider
{
    /**
     * Create or update a provider. Secret fields left blank on update keep their saved value.
     */
    public function handle(ProviderData $data, ?Provider $provider = null): Provider
    {
        $type = $provider === null ? ProviderType::from((string) $data->type) : $provider->type;
        $credentials = [];

        foreach ($type->fields() as $field) {
            $value = $data->credentials[$field->key] ?? null;
            $credentials[$field->key] = $this->keepSecret($field, $value, $provider) ? ($provider->credentials[$field->key] ?? null) : $value;
        }

        $webhookSecret = match ($type->webhookVerification()) {
            // We generate the basic auth credentials the provider will send back.
            WebhookVerification::BasicAuth => $provider->webhook_secret ?? 'gmk:'.Str::random(32),
            WebhookVerification::SigningSecret => $data->webhookSecret ?? $provider?->webhook_secret,
            default => null,
        };

        $provider ??= new Provider(['type' => $type]);

        $provider->fill([
            'name' => $data->name,
            'credentials' => $credentials,
            'webhook_secret' => $webhookSecret,
            'from_name' => $data->fromName,
            'from_email' => $data->fromEmail,
            'reply_to' => $data->replyTo,
            'max_per_second' => $data->maxPerSecond,
            'daily_limit' => $data->dailyLimit,
        ])->save();

        return $provider;
    }

    private function keepSecret(CredentialField $field, ?string $value, ?Provider $provider): bool
    {
        return $field->secret && $provider !== null && ($value === null || $value === '');
    }
}
