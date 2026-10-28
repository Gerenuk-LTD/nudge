<?php

namespace Gerenuk\Marketing\Models;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Database\Factories\CampaignFactory;
use Gerenuk\Marketing\Enums\AudienceType;
use Gerenuk\Marketing\Enums\CampaignKind;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $workspace_id
 * @property CampaignKind $kind
 * @property int|null $automation_id
 * @property string|null $automation_step_id
 * @property int|null $provider_id
 * @property int|null $segment_id
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property string $name
 * @property string|null $subject
 * @property string|null $preheader
 * @property string|null $from_name
 * @property string|null $from_email
 * @property string|null $reply_to
 * @property string $editor
 * @property array<string, mixed>|null $editor_state
 * @property string|null $html
 * @property string|null $text
 * @property AudienceType $audience_type
 * @property list<int>|null $list_ids
 * @property bool $track_opens
 * @property bool $track_clicks
 * @property bool $ab_enabled
 * @property string|null $ab_subject_b
 * @property string|null $ab_html_b
 * @property int $ab_sample_percent
 * @property int $ab_wait_minutes
 * @property string $ab_metric
 * @property string|null $ab_winner
 * @property CarbonInterface|null $ab_decided_at
 * @property CampaignStatus $status
 * @property CarbonInterface|null $approved_at
 * @property CarbonInterface|null $scheduled_at
 * @property CarbonInterface|null $sending_started_at
 * @property CarbonInterface|null $sent_at
 * @property int $recipients_count
 * @property int $sent_count
 * @property int $failed_count
 * @property int $delivered_count
 * @property int $bounced_count
 * @property int $complained_count
 * @property int $opened_count
 * @property int $clicked_count
 * @property int $unsubscribed_count
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'workspace_id', 'kind', 'automation_id', 'automation_step_id', 'provider_id', 'segment_id', 'created_by', 'approved_by', 'name', 'subject', 'preheader',
    'from_name', 'from_email', 'reply_to', 'editor', 'editor_state', 'html', 'text', 'audience_type', 'list_ids',
    'track_opens', 'track_clicks', 'status', 'approved_at', 'scheduled_at', 'sending_started_at', 'sent_at',
    'recipients_count', 'ab_enabled', 'ab_subject_b', 'ab_html_b', 'ab_sample_percent', 'ab_wait_minutes',
    'ab_metric', 'ab_winner', 'ab_decided_at',
])]
#[UseFactory(CampaignFactory::class)]
class Campaign extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'kind' => 'broadcast',
        'audience_type' => 'lists',
        'editor' => 'html',
        'track_opens' => true,
        'track_clicks' => true,
    ];

    protected function casts(): array
    {
        return [
            'kind' => CampaignKind::class,
            'editor_state' => 'array',
            'ab_enabled' => 'boolean',
            'ab_decided_at' => 'datetime',
            'audience_type' => AudienceType::class,
            'list_ids' => 'array',
            'track_opens' => 'boolean',
            'track_clicks' => 'boolean',
            'status' => CampaignStatus::class,
            'approved_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * @return BelongsTo<Segment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /**
     * @return HasOne<CampaignSnapshot, $this>
     */
    public function snapshot(): HasOne
    {
        return $this->hasOne(CampaignSnapshot::class);
    }

    /**
     * @return HasMany<CampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /**
     * @return BelongsTo<Automation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function broadcasts(Builder $query): void
    {
        $query->where('kind', CampaignKind::Broadcast);
    }

    /**
     * @return HasMany<CampaignLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(CampaignLink::class);
    }
}
