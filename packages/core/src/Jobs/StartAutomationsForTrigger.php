<?php

namespace Gerenuk\Nudge\Jobs;

use Gerenuk\Nudge\Actions\Automations\EnrollContact;
use Gerenuk\Nudge\Enums\AutomationStatus;
use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Models\ActivityEvent;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Starts every active automation whose trigger matches something that just happened to a contact.
 */
class StartAutomationsForTrigger implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * @param  string  $value  The list ID, tag ID or event name that happened
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $workspaceId,
        public int $contactId,
        public AutomationTrigger $trigger,
        public string $value,
        public array $context = [],
        public ?int $eventId = null,
    ) {
        $this->onQueue(config('nudge.sending.queue'));
    }

    public function handle(CurrentWorkspace $currentWorkspace, EnrollContact $enroll): void
    {
        $currentWorkspace->runAs(Workspace::findOrFail($this->workspaceId), function () use ($enroll): void {
            if ($this->eventId === null) {
                $this->startAutomations($enroll);

                return;
            }

            // The same event can be dispatched more than once (a replayed request, a retried job).
            // The lock keeps two copies apart, and triggered_at tells a later copy there is nothing to do.
            Cache::lock("nudge:event-trigger:{$this->eventId}", 60)->block(10, function () use ($enroll): void {
                $event = ActivityEvent::query()->whereKey($this->eventId)->first();

                if ($event === null || $event->triggered_at !== null) {
                    return;
                }

                $this->startAutomations($enroll);

                // Marked after enrolling, so a job that dies part-way is retried and finishes the work;
                // EnrollContact skips automations the contact is already in.
                $event->triggered_at = now();
                $event->save();
            });
        });
    }

    private function startAutomations(EnrollContact $enroll): void
    {
        $contact = Contact::find($this->contactId);

        if ($contact === null) {
            return;
        }

        $key = match ($this->trigger) {
            AutomationTrigger::ListJoined => 'list_id',
            AutomationTrigger::TagAdded => 'tag_id',
            AutomationTrigger::ApiEvent => 'event',
            default => null,
        };

        if ($key === null) {
            return;
        }

        Automation::query()
            ->where('status', AutomationStatus::Active)
            ->where('trigger_type', $this->trigger)
            ->get()
            ->filter(fn (Automation $automation) => $this->matches($automation->triggerValue($key)))
            ->each(fn (Automation $automation) => $enroll->handle($automation, $contact, ['trigger' => $this->trigger->value, ...$this->context]));
    }

    private function matches(mixed $configured): bool
    {
        // Event names are matched without regard to case; IDs exactly.
        return $this->trigger === AutomationTrigger::ApiEvent
            ? is_string($configured) && mb_strtolower(trim($configured)) === mb_strtolower(trim($this->value))
            : (string) $configured === $this->value;
    }
}
