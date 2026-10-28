<?php

namespace Gerenuk\Marketing\Actions\Contacts;

use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Support\ContactData;
use Gerenuk\Marketing\Support\ContactRules;
use Illuminate\Support\Facades\DB;

class UpdateContact
{
    public function __construct(private readonly ContactRules $rules) {}

    /**
     * Replace a contact's details from the edit form: custom fields, tags and lists are set to exactly what is given.
     */
    public function handle(Contact $contact, ContactData $data): Contact
    {
        return DB::transaction(function () use ($contact, $data): Contact {
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

            (new ChangeContactStatus)->apply($contact, $data->status ?? ContactStatus::Subscribed);

            $contact->save();

            $contact->tags()->sync(UpsertContact::resolveTagIds($data->tags));
            $contact->lists()->sync(UpsertContact::listPivot($data->lists));

            return $contact;
        });
    }
}
