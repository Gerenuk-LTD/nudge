<?php

namespace Gerenuk\Nudge\Models;

use Carbon\CarbonInterface;
use Gerenuk\Nudge\Enums\SuppressionReason;
use Gerenuk\Nudge\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An address the workspace must never send to, after a hard bounce, a complaint or a manual block.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $email
 * @property SuppressionReason $reason
 * @property string|null $detail
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['workspace_id', 'email', 'reason', 'detail'])]
class Suppression extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
        ];
    }

    public static function suppress(string $email, SuppressionReason $reason, ?string $detail = null): self
    {
        return static::firstOrCreate(
            ['email' => Contact::normalizeEmail($email)],
            ['reason' => $reason, 'detail' => $detail === null ? null : mb_substr($detail, 0, 255)],
        );
    }
}
