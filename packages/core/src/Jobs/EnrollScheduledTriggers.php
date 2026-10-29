<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Actions\Automations\EnrollContact;
use Gerenuk\Marketing\Enums\AutomationStatus;
use Gerenuk\Marketing\Enums\AutomationTrigger;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Segments\SegmentQuery;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Enrols contacts for triggers that depend on time rather than an action: entering a segment,
 * a date field coming round, and going quiet.
 */
class EnrollScheduledTriggers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 1800;

    /**
     * @param  list<AutomationTrigger>  $triggers
     */
    public function __construct(public array $triggers)
    {
        $this->onQueue(config('marketing.sending.queue'));
    }

    public function uniqueId(): string
    {
        return implode(',', array_map(fn (AutomationTrigger $trigger) => $trigger->value, $this->triggers));
    }

    public function handle(CurrentWorkspace $currentWorkspace, EnrollContact $enroll, SegmentQuery $segments): void
    {
        $automations = Automation::withoutGlobalScopes()
            ->where('status', AutomationStatus::Active)
            ->whereIn('trigger_type', $this->triggers)
            ->get();

        foreach ($automations as $automation) {
            $currentWorkspace->runAs(Workspace::findOrFail($automation->workspace_id), function () use ($automation, $enroll, $segments): void {
                $candidates = match ($automation->trigger_type) {
                    AutomationTrigger::SegmentEntered => $this->segmentMembers($automation, $segments),
                    AutomationTrigger::DateField => $this->dateMatches($automation),
                    AutomationTrigger::Inactivity => $this->inactive($automation),
                    default => null,
                };

                $candidates?->select('contacts.*')->chunkById(500, function (Collection $contacts) use ($automation, $enroll): void {
                    foreach ($contacts as $contact) {
                        $enroll->handle($automation, $contact, ['trigger' => $automation->trigger_type->value]);
                    }
                }, 'contacts.id', 'id');
            });
        }
    }

    /**
     * Segment members who have never been through this automation. A contact enters a segment
     * automation once, even if they leave the segment and come back.
     *
     * @return Builder<Contact>|null
     */
    private function segmentMembers(Automation $automation, SegmentQuery $segments): ?Builder
    {
        $segment = Segment::find((int) $automation->triggerValue('segment_id'));

        return $segment === null ? null : $this->neverEnrolled($segments->contacts($segment->definition), $automation);
    }

    /**
     * Contacts whose date field falls on today plus the offset: every year, or once.
     *
     * @return Builder<Contact>|null
     */
    private function dateMatches(Automation $automation): ?Builder
    {
        $key = $automation->triggerValue('field_key');

        if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
            return null;
        }

        // A positive offset starts the automation that many days before the date.
        $target = now()->addDays((int) $automation->triggerValue('offset_days'));
        $column = 'custom_fields->'.$key;
        $yearly = $automation->triggerValue('repeat') !== 'once';

        $query = Contact::query()->active()->where('status', ContactStatus::Subscribed);

        if (! $yearly) {
            return $query->where($column, $target->toDateString());
        }

        return $query
            ->where($column, 'like', '%-'.$target->format('m-d'))
            // Once a year at most.
            ->whereDoesntHave('automationRuns', fn (Builder $runs) => $runs
                ->where('automation_id', $automation->id)
                ->where('created_at', '>=', now()->startOfYear()));
    }

    /**
     * Contacts who were sent something in the window but have not opened or clicked anything in it.
     *
     * @return Builder<Contact>
     */
    private function inactive(Automation $automation): Builder
    {
        $since = now()->subDays(max(7, (int) $automation->triggerValue('days')));

        $query = Contact::query()->active()
            ->where('status', ContactStatus::Subscribed)
            ->where('created_at', '<', $since)
            ->whereExists(fn ($events) => $events->from('events')
                ->whereColumn('events.contact_id', 'contacts.id')
                ->where('events.type', EventType::Sent)
                ->where('events.occurred_at', '>=', $since))
            ->whereNotExists(fn ($events) => $events->from('events')
                ->whereColumn('events.contact_id', 'contacts.id')
                ->whereIn('events.type', [EventType::Opened, EventType::Clicked])
                ->where('events.occurred_at', '>=', $since));

        // With re-entry allowed, a contact can come back once per inactivity window.
        return $automation->allow_reentry
            ? $query->whereDoesntHave('automationRuns', fn (Builder $runs) => $runs->where('automation_id', $automation->id)->where('created_at', '>=', $since))
            : $this->neverEnrolled($query, $automation);
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    private function neverEnrolled(Builder $query, Automation $automation): Builder
    {
        return $query->whereDoesntHave('automationRuns', fn (Builder $runs) => $runs->where('automation_id', $automation->id));
    }
}
