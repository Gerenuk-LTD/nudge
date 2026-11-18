<?php

namespace Gerenuk\Nudge\Models;

use Carbon\CarbonInterface;
use Gerenuk\Nudge\Models\Concerns\BelongsToWorkspace;
use Gerenuk\Nudge\Sending\ProviderType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A workspace's connection to a sending provider. Credentials and the webhook secret are encrypted at rest.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property ProviderType $type
 * @property array<string, string|null> $credentials
 * @property string|null $webhook_secret
 * @property string $webhook_token
 * @property string|null $webhook_topic_arn
 * @property string|null $from_name
 * @property string|null $from_email
 * @property string|null $reply_to
 * @property int $max_per_second
 * @property int|null $daily_limit
 * @property CarbonInterface|null $last_sent_at
 * @property CarbonInterface|null $last_webhook_at
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['workspace_id', 'name', 'type', 'credentials', 'webhook_secret', 'webhook_token', 'webhook_topic_arn', 'from_name', 'from_email', 'reply_to', 'max_per_second', 'daily_limit', 'last_sent_at', 'last_webhook_at'])]
#[Hidden(['credentials', 'webhook_secret', 'webhook_token', 'webhook_topic_arn'])]
class Provider extends Model
{
    use BelongsToWorkspace;

    protected static function booted(): void
    {
        static::creating(function (Provider $provider): void {
            $provider->webhook_token ??= Str::random(48);
        });
    }

    protected function casts(): array
    {
        return [
            'type' => ProviderType::class,
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'last_sent_at' => 'datetime',
            'last_webhook_at' => 'datetime',
        ];
    }

    public function dsn(): string
    {
        return $this->type->dsn($this->credentials);
    }

    /**
     * Find a provider from its webhook URL token, across all workspaces.
     */
    public static function findByWebhookToken(string $token): ?self
    {
        return static::withoutGlobalScopes()->where('webhook_token', $token)->first();
    }
}
