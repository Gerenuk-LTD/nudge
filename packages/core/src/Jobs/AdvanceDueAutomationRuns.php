<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Enums\AutomationRunStatus;
use Gerenuk\Marketing\Enums\AutomationStatus;
use Gerenuk\Marketing\Models\AutomationRun;

/**
 * Runs every minute and wakes up contacts whose wait is over, in every workspace.
 */
class AdvanceDueAutomationRuns
{
    public function __invoke(): void
    {
        AutomationRun::withoutGlobalScopes()
            ->where('status', AutomationRunStatus::Waiting)
            ->where('next_run_at', '<=', now())
            ->whereHas('automation', fn ($query) => $query->withoutGlobalScopes()->where('status', AutomationStatus::Active))
            ->select(['id', 'workspace_id'])
            ->chunkById(500, function ($runs): void {
                foreach ($runs as $run) {
                    // Claim it, so the next tick does not queue it again while it waits for a worker.
                    $claimed = AutomationRun::withoutGlobalScopes()->whereKey($run->id)->where('status', AutomationRunStatus::Waiting)
                        ->update(['status' => AutomationRunStatus::Active]) === 1;

                    if ($claimed) {
                        AdvanceAutomationRun::dispatch($run->id, $run->workspace_id);
                    }
                }
            });
    }
}
