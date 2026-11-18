<?php

namespace Gerenuk\Nudge\Automations;

use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Jobs\StartAutomationsForTrigger;
use Gerenuk\Nudge\Models\Contact;

/**
 * The single place contact changes are reported to automations. Matching runs on the queue,
 * so saving a contact or importing a file never waits on automations.
 */
class AutomationTriggers
{
    /**
     * @param  array<int, int|string>  $listIds  Lists the contact was just added to
     */
    public function listsJoined(Contact $contact, array $listIds): void
    {
        foreach ($listIds as $listId) {
            StartAutomationsForTrigger::dispatch($contact->workspace_id, $contact->id, AutomationTrigger::ListJoined, (string) $listId);
        }
    }

    /**
     * @param  array<int, int|string>  $tagIds  Tags the contact was just given
     */
    public function tagsAdded(Contact $contact, array $tagIds): void
    {
        foreach ($tagIds as $tagId) {
            StartAutomationsForTrigger::dispatch($contact->workspace_id, $contact->id, AutomationTrigger::TagAdded, (string) $tagId);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function event(Contact $contact, string $name, array $properties, ?int $eventId = null): void
    {
        StartAutomationsForTrigger::dispatch($contact->workspace_id, $contact->id, AutomationTrigger::ApiEvent, $name, ['event' => $name, 'properties' => $properties], $eventId);
    }
}
