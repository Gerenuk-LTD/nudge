<?php

namespace Gerenuk\Marketing\Actions\Contacts;

use Gerenuk\Marketing\Automations\AutomationTriggers;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Support\ContactData;
use Gerenuk\Marketing\Support\ContactRules;
use Illuminate\Support\Facades\DB;

class UpdateContact
{
    public function __construct(
        private readonly ContactRules $rules,
        private readonly AutomationTriggers $triggers,
    ) {}

    /**
     * Replace a contact's details from the edit form: custom fields, tags and lists are set to exactly what is given.
     * A status change is recorded on the timeline with `$by`, the person who made it.
     */
    public function handle(Contact $contact, ContactData $data, ?string $by = null): Contact
    {
        return DB::transaction(function () use ($contact, $data, $by): Contact {
            $customFields = array_filter(
                $this->rules->castCustomFields($data->customFields ?? []),
                fn (mixed $value) => $value !== null,
            );

            $contact->fill([
                'email' => $data->email,
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'custom_fields' => $customFields === [] ? null : $customFields,
            ]);

            $before = $contact->status;
            $status = $data->status ?? ContactStatus::Subscribed;
            (new ChangeContactStatus)->apply($contact, $status);

            $contact->save();

            if ($before !== $status) {
                ChangeContactStatus::record($contact, $status, ['by' => $by, 'via' => 'contact form']);
            }

            $this->triggers->tagsAdded($contact, $contact->tags()->sync(UpsertContact::resolveTagIds($data->tags))['attached']);
            $this->triggers->listsJoined($contact, $contact->lists()->sync(UpsertContact::listPivot($data->lists))['attached']);

            return $contact;
        });
    }
}
