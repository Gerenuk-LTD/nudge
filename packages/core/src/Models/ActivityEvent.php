<?php

namespace Gerenuk\Marketing\Models;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One row in the append-only event stream: a send, delivery, bounce, open, click and so on.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $contact_id
 * @property int|null $campaign_id
 * @property int|null $campaign_recipient_id
 * @property EventType $type
 * @property array<string, mixed>|null $data
 * @property string|null $idempotency_key
 * @property CarbonInterface $occurred_at
 * @property CarbonInterface|null $triggered_at
 */
#[Table('events')]
#[WithoutTimestamps]
#[Fillable(['workspace_id', 'contact_id', 'campaign_id', 'campaign_recipient_id', 'type', 'data', 'idempotency_key', 'occurred_at', 'triggered_at'])]
class ActivityEvent extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'data' => 'array',
            'occurred_at' => 'datetime',
            'triggered_at' => 'datetime',
        ];
    }

    /**
     * Record an event about a recipient of a campaign.
     *
     * @param  array<string, mixed>  $data
     */
    public static function recordFor(CampaignRecipient $recipient, EventType $type, array $data = [], ?CarbonInterface $occurredAt = null): self
    {
        return static::create([
            'workspace_id' => $recipient->workspace_id,
            'contact_id' => $recipient->contact_id,
            'campaign_id' => $recipient->campaign_id,
            'campaign_recipient_id' => $recipient->id,
            'type' => $type,
            'data' => $data === [] ? null : $data,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
