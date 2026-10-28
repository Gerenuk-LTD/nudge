<?php

namespace Gerenuk\Marketing\Models;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Owns all marketing data. Every tenant-owned row carries a workspace_id.
 *
 * @property int $id
 * @property int $account_id
 * @property string $name
 * @property string|null $postal_address
 * @property bool $requires_campaign_approval
 * @property CarbonInterface|null $sending_paused_at
 * @property string|null $sending_paused_reason
 */
#[Fillable(['account_id', 'name', 'postal_address', 'requires_campaign_approval', 'sending_paused_at', 'sending_paused_reason'])]
#[UseFactory(WorkspaceFactory::class)]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'requires_campaign_approval' => 'boolean',
            'sending_paused_at' => 'datetime',
        ];
    }

    public function isSendingPaused(): bool
    {
        return $this->sending_paused_at !== null;
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
