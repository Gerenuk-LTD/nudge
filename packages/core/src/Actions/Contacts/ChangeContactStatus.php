<?php

namespace Gerenuk\Nudge\Actions\Contacts;

use Gerenuk\Nudge\Enums\ContactStatus;
use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Models\Contact;

class ChangeContactStatus
{
    /**
     * Set the status on the model without saving, keeping unsubscribed_at in step.
     */
    public function apply(Contact $contact, ContactStatus $status): void
    {
        if ($contact->status === $status) {
            return;
        }

        $contact->status = $status;
        $contact->unsubscribed_at = $status === ContactStatus::Unsubscribed ? now() : null;
    }

    /**
     * Note a subscription change on the contact's timeline, so there is always a record of who opted someone in or out,
     * how and when. Call it only when the status really changed, after the contact is saved.
     *
     * @param  array<string, mixed>  $context  who or what made the change, such as the person's name and the route it came by
     */
    public static function record(Contact $contact, ContactStatus $to, array $context): void
    {
        $contact->events()->create([
            'type' => $to === ContactStatus::Unsubscribed ? EventType::Unsubscribed : EventType::Resubscribed,
            'data' => array_filter($context, fn (mixed $value) => $value !== null),
            'occurred_at' => now(),
        ]);
    }
}
