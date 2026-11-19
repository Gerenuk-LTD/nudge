<?php

namespace Gerenuk\Nudge\Actions\Automations;

use Gerenuk\Nudge\Automations\ActivationChecklist;
use Gerenuk\Nudge\Automations\SyncAutomationEmails;
use Gerenuk\Nudge\Enums\AutomationRunStatus;
use Gerenuk\Nudge\Enums\AutomationStatus;
use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\AutomationRun;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Segments\SegmentQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateAutomation
{
    public function __construct(
        private readonly ActivationChecklist $checklist,
        private readonly SyncAutomationEmails $emails,
        private readonly SegmentQuery $segments,
    ) {}

    public function handle(Automation $automation, Workspace $workspace): void
    {
        $problems = $this->checklist->problems($automation, $workspace);

        if ($problems !== []) {
            throw ValidationException::withMessages(['automation' => $problems]);
        }

        DB::transaction(function () use ($automation): void {
            $firstActivation = $automation->activated_at === null;

            $automation->update(['status' => AutomationStatus::Active, 'activated_at' => $automation->activated_at ?? now()]);
            $this->emails->handle($automation);

            if ($firstActivation && $automation->trigger_type === AutomationTrigger::SegmentEntered && ! $automation->triggerValue('include_existing')) {
                $this->skipCurrentSegmentMembers($automation);
            }
        });
    }

    /**
     * "Enters a segment" means joining after the automation starts. People already in the segment
     * are marked as skipped so the segment check never enrols them.
     */
    private function skipCurrentSegmentMembers(Automation $automation): void
    {
        $segment = Segment::find((int) $automation->triggerValue('segment_id'));

        if ($segment === null) {
            return;
        }

        $this->segments->contacts($segment->definition)->select('contacts.id')->chunkById(1000, function ($contacts) use ($automation): void {
            $now = now();

            AutomationRun::query()->insert($contacts->map(fn ($contact) => [
                'workspace_id' => $automation->workspace_id,
                'automation_id' => $automation->id,
                'contact_id' => $contact->id,
                'status' => AutomationRunStatus::Exited->value,
                'exit_reason' => 'Already in the segment when the automation started',
                'completed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }, 'contacts.id', 'id');
    }
}
