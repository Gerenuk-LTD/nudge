<?php

namespace Gerenuk\Nudge\Support;

/**
 * Validated provider settings from the connect and edit forms.
 */
final class ProviderData
{
    /**
     * @param  array<string, string|null>  $credentials
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $type,
        public readonly array $credentials,
        public readonly ?string $webhookSecret,
        public readonly ?string $fromName,
        public readonly ?string $fromEmail,
        public readonly ?string $replyTo,
        public readonly int $maxPerSecond,
        public readonly ?int $dailyLimit,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $credentials = [];

        foreach (is_array($validated['credentials'] ?? null) ? $validated['credentials'] : [] as $key => $value) {
            $credentials[(string) $key] = $value === null ? null : (string) $value;
        }

        $string = fn (string $key): ?string => isset($validated[$key]) && $validated[$key] !== '' ? (string) $validated[$key] : null;

        return new self(
            name: (string) $validated['name'],
            type: $string('type'),
            credentials: $credentials,
            webhookSecret: $string('webhook_secret'),
            fromName: $string('from_name'),
            fromEmail: $string('from_email'),
            replyTo: $string('reply_to'),
            maxPerSecond: (int) $validated['max_per_second'],
            dailyLimit: isset($validated['daily_limit']) ? (int) $validated['daily_limit'] : null,
        );
    }
}
