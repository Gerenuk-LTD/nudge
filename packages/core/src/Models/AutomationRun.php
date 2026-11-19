<?php

namespace Gerenuk\Nudge\Models;

use Carbon\CarbonInterface;
use Gerenuk\Nudge\Enums\AutomationRunStatus;
use Gerenuk\Nudge\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One contact's journey through an automation.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $automation_id
 * @property int $contact_id
 * @property AutomationRunStatus $status
 * @property string|null $current_step_id
 * @property CarbonInterface|null $next_run_at
 * @property array<string, mixed>|null $context
 * @property string|null $exit_reason
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['workspace_id', 'automation_id', 'contact_id', 'status', 'current_step_id', 'next_run_at', 'context', 'exit_reason', 'completed_at'])]
class AutomationRun extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'status' => AutomationRunStatus::class,
            'next_run_at' => 'datetime',
            'context' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Automation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [AutomationRunStatus::Completed, AutomationRunStatus::Exited], true);
    }
}
