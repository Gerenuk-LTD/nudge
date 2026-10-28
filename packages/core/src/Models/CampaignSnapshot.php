<?php

namespace Gerenuk\Marketing\Models;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Exactly what a campaign sent, frozen when sending started. Never updated.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $campaign_id
 * @property string $subject
 * @property string|null $preheader
 * @property string $from_name
 * @property string $from_email
 * @property string|null $reply_to
 * @property string $html
 * @property string $text
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['workspace_id', 'campaign_id', 'subject', 'preheader', 'from_name', 'from_email', 'reply_to', 'html', 'text'])]
class CampaignSnapshot extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Campaign snapshots are immutable.'));
    }
}
