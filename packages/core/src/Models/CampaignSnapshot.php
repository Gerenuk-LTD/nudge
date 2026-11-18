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
 * @property string|null $subject_b
 * @property string|null $preheader
 * @property string $from_name
 * @property string $from_email
 * @property string|null $reply_to
 * @property string $html
 * @property string|null $html_b
 * @property string $text
 * @property string|null $text_b
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['workspace_id', 'campaign_id', 'subject', 'subject_b', 'preheader', 'from_name', 'from_email', 'reply_to', 'html', 'html_b', 'text', 'text_b'])]
class CampaignSnapshot extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Campaign snapshots are immutable.'));
    }

    /**
     * The subject, HTML and text for an A/B variant. Variant B falls back to A for anything not tested.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function variant(?string $variant): array
    {
        $isB = $variant === 'b';

        return [
            'subject' => $isB && $this->subject_b !== null ? $this->subject_b : $this->subject,
            'html' => $isB && $this->html_b !== null ? $this->html_b : $this->html,
            'text' => $isB && $this->text_b !== null ? $this->text_b : $this->text,
        ];
    }
}
