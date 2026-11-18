<?php

namespace Gerenuk\Nudge\Actions\Automations;

use Gerenuk\Nudge\Enums\AutomationRunStatus;
use Gerenuk\Nudge\Enums\AutomationStatus;
use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Jobs\AdvanceAutomationRun;
use Gerenuk\Nudge\Models\ActivityEvent;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\AutomationRun;
use Gerenuk\Nudge\Models\Contact;
use Illuminate\Support\Facades\Cache;

class EnrollContact
{
    /**
     * Start a contact on an automation, unless they are already on it or have been before
     * (when it does not allow re-entry). Returns the new run, if one started.
     *
     * @param  array<string, mixed>  $context  What triggered it, kept on the run
     */
    public function handle(Automation $automation, Contact $contact, array $context = []): ?AutomationRun
    {
        if ($automation->status !== AutomationStatus::Active || $contact->archived_at !== null) {
            return null;
        }

        // Two triggers for the same contact can arrive at once; only one may enrol them.
        return Cache::lock("nudge:enrol:{$automation->id}:{$contact->id}", 10)->block(5, function () use ($automation, $contact, $context): ?AutomationRun {
            $previous = AutomationRun::query()->where('automation_id', $automation->id)->where('contact_id', $contact->id);

            $alreadyIn = (clone $previous)->whereIn('status', [AutomationRunStatus::Active, AutomationRunStatus::Waiting])->exists();

            if ($alreadyIn || (! $automation->allow_reentry && $previous->exists())) {
                return null;
            }

            $run = AutomationRun::create([
                'automation_id' => $automation->id,
                'contact_id' => $contact->id,
                'status' => AutomationRunStatus::Active,
                'current_step_id' => $automation->steps()->first(),
                'context' => $context === [] ? null : $context,
            ]);

            $automation->increment('entered_count');

            ActivityEvent::create([
                'contact_id' => $contact->id,
                'type' => EventType::AutomationEntered,
                'data' => ['automation_id' => $automation->id, 'automation' => $automation->name],
                'occurred_at' => now(),
            ]);

            AdvanceAutomationRun::dispatch($run->id, $run->workspace_id);

            return $run;
        });
    }
}
