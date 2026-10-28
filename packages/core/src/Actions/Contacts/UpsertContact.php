<?php

namespace Gerenuk\Marketing\Actions\Contacts;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Tag;
use Gerenuk\Marketing\Support\ContactData;
use Gerenuk\Marketing\Support\ContactRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class UpsertContact
{
    public function __construct(private readonly ContactRules $rules) {}

    /**
     * Create or update a contact by email, restoring it if archived. Only provided fields change:
     * custom fields are merged, and tags and lists are added, never removed.
     *
     * @return array{contact: Contact, created: bool}
     */
    public function handle(ContactData $data, ContactSource $source): array
    {
        try {
            return $this->upsert($data, $source);
        } catch (UniqueConstraintViolationException) {
            // Another request created the same email between our read and insert; it now exists, so update it.
            return $this->upsert($data, $source);
        }
    }

    /**
     * @return array{contact: Contact, created: bool}
     */
    private function upsert(ContactData $data, ContactSource $source): array
    {
        return DB::transaction(function () use ($data, $source): array {
            $contact = Contact::query()->forEmail($data->email)->lockForUpdate()->first();
            $created = $contact === null;

            $contact ??= new Contact(['email' => $data->email, 'source' => $source]);

            if ($data->has('first_name')) {
                $contact->first_name = $data->firstName;
            }

            if ($data->has('last_name')) {
                $contact->last_name = $data->lastName;
            }

            // Archiving hides a contact; adding them again brings them back.
            $contact->archived_at = null;

            if ($data->customFields !== null) {
                $contact->custom_fields = array_merge(
                    $contact->custom_fields ?? [],
                    $this->rules->castCustomFields($data->customFields),
                );
            }

            if ($data->status !== null) {
                (new ChangeContactStatus)->apply($contact, $data->status);
            }

            $contact->save();

            if ($data->tags !== []) {
                $contact->tags()->syncWithoutDetaching(self::resolveTagIds($data->tags));
            }

            if ($data->lists !== []) {
                $contact->lists()->syncWithoutDetaching(self::listPivot($data->lists));
            }

            return ['contact' => $contact, 'created' => $created];
        });
    }

    /**
     * Find or create tags by name in the current workspace.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function resolveTagIds(array $names): array
    {
        $ids = [];
        $seen = [];

        foreach ($names as $name) {
            $name = trim($name);
            $key = mb_strtolower($name);

            if ($name === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $ids[] = Tag::firstOrCreate(['name' => $name])->id;
        }

        return $ids;
    }

    /**
     * Pivot rows for attaching lists, stamped with when the contact joined.
     *
     * @param  list<int>  $listIds
     * @return array<int, array{created_at: CarbonInterface}>
     */
    public static function listPivot(array $listIds): array
    {
        $now = now();

        return array_fill_keys($listIds, ['created_at' => $now]);
    }
}
