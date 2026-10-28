<?php

namespace Gerenuk\Marketing\Actions\Contacts;

use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Models\Contact;

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
}
