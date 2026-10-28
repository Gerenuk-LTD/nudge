<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Actions\Automations\EnrollContact;
use Gerenuk\Marketing\Enums\AutomationStatus;
use Gerenuk\Marketing\Enums\AutomationTrigger;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts every active automation whose trigger matches something that just happened to a contact.
 */
class StartAutomationsForTrigger implements ShouldQueue
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
    ) {
        $this->onQueue(config('marketing.sending.queue'));
    }

    public function handle(CurrentWorkspace $currentWorkspace, EnrollContact $enroll): void
    {
        $currentWorkspace->runAs(Workspace::findOrFail($this->workspaceId), function () use ($enroll): void {
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
        });
    }

    private function matches(mixed $configured): bool
    {
        // Event names are matched without regard to case; IDs exactly.
        return $this->trigger === AutomationTrigger::ApiEvent
            ? is_string($configured) && mb_strtolower(trim($configured)) === mb_strtolower(trim($this->value))
            : (string) $configured === $this->value;
    }
}
