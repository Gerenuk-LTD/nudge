<?php

namespace Gerenuk\Nudge\Automations;

use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\ContactList;
use Gerenuk\Nudge\Models\CustomFieldDefinition;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Models\Tag;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Segments\SegmentDefinitionValidator;
use Illuminate\Validation\ValidationException;

/**
 * What must be true before an automation can start: a complete trigger and complete steps.
 */
class ActivationChecklist
{
    public function __construct(private readonly SegmentDefinitionValidator $segments) {}

    /**
     * @return list<string> Problems, in plain language; empty when it can start
     */
    public function problems(Automation $automation, Workspace $workspace): array
    {
        $problems = [];
        $definition = $automation->steps();

        if ($definition->first() === null) {
            $problems[] = __('Add at least one step.');
        }

        $triggerProblem = $this->triggerProblem($automation);

        if ($triggerProblem !== null) {
            $problems[] = $triggerProblem;
        }

        $emails = $definition->ofType(StepType::SendEmail);

        if ($emails !== [] && blank($workspace->postal_address)) {
            $problems[] = __('Set a postal address in sending settings; every email must include one.');
        }

        foreach ($emails as $index => $step) {
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];
            $label = __('Email :number', ['number' => $index + 1]);

            foreach (['subject' => 'a subject', 'from_name' => 'a from name', 'from_email' => 'a from address', 'html' => 'content'] as $key => $what) {
                if (blank($config[$key] ?? null)) {
                    $problems[] = __(':label needs :what.', ['label' => $label, 'what' => $what]);
                }
            }

            if (! Provider::query()->whereKey($config['provider_id'] ?? 0)->exists()) {
                $problems[] = __(':label needs a sending provider.', ['label' => $label]);
            }
        }

        foreach ($definition->ofType(StepType::Condition) as $step) {
            try {
                $this->segments->validate($step['config']['definition'] ?? null);
            } catch (ValidationException) {
                $problems[] = __('A condition step has incomplete rules.');
            }
        }

        foreach ($definition->ofType(StepType::Webhook) as $step) {
            if (blank($step['config']['url'] ?? null)) {
                $problems[] = __('A webhook step needs a URL.');
            }
        }

        if ($workspace->requires_campaign_approval && $automation->approved_at === null) {
            $problems[] = __('This workspace requires automations to be approved before they go live.');
        }

        return array_values(array_unique($problems));
    }

    private function triggerProblem(Automation $automation): ?string
    {
        $value = fn (string $key) => $automation->triggerValue($key);

        return match ($automation->trigger_type) {
            AutomationTrigger::ListJoined => ContactList::query()->whereKey($value('list_id') ?? 0)->exists() ? null : 'Choose the list that starts it.',
            AutomationTrigger::TagAdded => Tag::query()->whereKey($value('tag_id') ?? 0)->exists() ? null : 'Choose the tag that starts it.',
            AutomationTrigger::SegmentEntered => Segment::query()->whereKey($value('segment_id') ?? 0)->exists() ? null : 'Choose the segment that starts it.',
            AutomationTrigger::DateField => CustomFieldDefinition::query()->where('key', $value('field_key'))->where('type', 'date')->exists() ? null : 'Choose a date field.',
            AutomationTrigger::Inactivity => (int) $value('days') >= 7 ? null : 'Set how many days without engagement, 7 or more.',
            AutomationTrigger::ApiEvent => filled($value('event')) ? null : 'Name the event your app sends.',
        };
    }
}
