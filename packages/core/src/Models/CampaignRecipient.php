<?php

namespace Gerenuk\Marketing\Models;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One contact's copy of a campaign, and what happened to it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $campaign_id
 * @property int $contact_id
 * @property string $email
 * @property RecipientStatus $status
 * @property string|null $provider_message_id
 * @property string|null $error
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $opened_at
 * @property CarbonInterface|null $clicked_at
 * @property CarbonInterface|null $bounced_at
 * @property CarbonInterface|null $complained_at
 * @property CarbonInterface|null $unsubscribed_at
 */
#[Fillable([
    'workspace_id', 'campaign_id', 'contact_id', 'email', 'status', 'provider_message_id', 'error',
    'sent_at', 'delivered_at', 'opened_at', 'clicked_at', 'bounced_at', 'complained_at', 'unsubscribed_at',
])]
class CampaignRecipient extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'status' => RecipientStatus::class,
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'bounced_at' => 'datetime',
            'complained_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
