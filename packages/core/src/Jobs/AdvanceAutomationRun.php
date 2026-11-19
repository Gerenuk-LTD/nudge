<?php

namespace Gerenuk\Nudge\Jobs;

use Gerenuk\Nudge\Automations\StepRunner;
use Gerenuk\Nudge\Enums\AutomationRunStatus;
use Gerenuk\Nudge\Enums\AutomationStatus;
use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Models\ActivityEvent;
use Gerenuk\Nudge\Models\AutomationRun;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Moves a contact through an automation until it has to wait, or reaches the end.
 */
class AdvanceAutomationRun implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A guard against a definition that somehow loops. */
    private const MAX_STEPS_PER_RUN = 50;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public int $runId, public int $workspaceId)
    {
        $this->onQueue(config('nudge.sending.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    /**
     * After the last attempt fails, take the contact out of the automation, so the run is not stuck "active"
     * and the contact can enter again if the automation allows re-entry.
     */
    public function failed(?Throwable $exception): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        app(CurrentWorkspace::class)->runAs($workspace, function (): void {
            $run = AutomationRun::with('automation')->find($this->runId);

            if ($run !== null && $run->status === AutomationRunStatus::Active) {
                $this->exit($run, __('Stopped after an error'));
            }
        });
    }

    public function handle(CurrentWorkspace $currentWorkspace, StepRunner $runner): void
    {
        $workspace = Workspace::findOrFail($this->workspaceId);

        $currentWorkspace->runAs($workspace, function () use ($workspace, $runner): void {
            $run = AutomationRun::with(['automation', 'contact'])->find($this->runId);

            if ($run === null || $run->isFinished()) {
                return;
            }

            // Paused since this job was queued: put the contact back to wait, to carry on when it resumes.
            if ($run->automation->status !== AutomationStatus::Active) {
                $run->update(['status' => AutomationRunStatus::Waiting, 'next_run_at' => $run->next_run_at ?? now()]);

                return;
            }

            if ($run->contact->archived_at !== null) {
                $this->exit($run, __('Contact was archived'));

                return;
            }

            // Automation emails respect a paused workspace like campaigns do.
            if ($workspace->isSendingPaused()) {
                $run->update(['status' => AutomationRunStatus::Waiting, 'next_run_at' => now()->addMinutes(15)]);

                return;
            }

            $definition = $run->automation->steps();

            for ($i = 0; $i < self::MAX_STEPS_PER_RUN; $i++) {
                $stepId = $run->current_step_id;

                if ($stepId === null) {
                    $this->complete($run);

                    return;
                }

                $step = $definition->step($stepId);

                if ($step === null) {
                    $this->exit($run, __('Its step was removed from the automation'));

                    return;
                }

                $result = $runner->run($step, $run, $run->contact, $workspace);

                if ($result->notes !== []) {
                    $run->context = [...($run->context ?? []), $stepId => $result->notes];
                }

                switch ($result->action) {
                    case 'exit':
                        $this->exit($run, (string) $result->reason);

                        return;
                    case 'retry':
                        $run->update(['status' => AutomationRunStatus::Waiting, 'next_run_at' => $result->until]);

                        return;
                    case 'wait':
                        $run->update(['status' => AutomationRunStatus::Waiting, 'current_step_id' => $definition->next($stepId), 'next_run_at' => $result->until]);

                        return;
                    case 'branch':
                        $run->current_step_id = $definition->branch($stepId, (bool) $result->matched);
                        break;
                    default:
                        $run->current_step_id = $definition->next($stepId);
                }

                $run->save();
            }

            $this->exit($run, __('Too many steps in one go'));
        });
    }

    private function complete(AutomationRun $run): void
    {
        $run->update(['status' => AutomationRunStatus::Completed, 'completed_at' => now(), 'next_run_at' => null]);
        $run->automation->increment('completed_count');

        $this->record($run, EventType::AutomationCompleted);
    }

    private function exit(AutomationRun $run, string $reason): void
    {
        $run->update(['status' => AutomationRunStatus::Exited, 'exit_reason' => $reason, 'completed_at' => now(), 'next_run_at' => null]);

        $this->record($run, EventType::AutomationExited, ['reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(AutomationRun $run, EventType $type, array $data = []): void
    {
        ActivityEvent::create([
            'contact_id' => $run->contact_id,
            'type' => $type,
            'data' => ['automation_id' => $run->automation_id, 'automation' => $run->automation->name, ...$data],
            'occurred_at' => now(),
        ]);
    }
}
