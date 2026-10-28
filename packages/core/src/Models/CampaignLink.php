<?php

namespace Gerenuk\Marketing\Models;

use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A link in a sent campaign, with click counters.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $campaign_id
 * @property string $url
 * @property string $url_hash
 * @property int $clicks_count
 * @property int $unique_clicks_count
 */
#[Fillable(['workspace_id', 'campaign_id', 'url', 'url_hash'])]
class CampaignLink extends Model
{
    use BelongsToWorkspace;
}
