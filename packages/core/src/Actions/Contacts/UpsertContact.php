<?php

namespace Gerenuk\Marketing\Actions\Contacts;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Automations\AutomationTriggers;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Tag;
use Gerenuk\Marketing\Support\ContactData;
use Gerenuk\Marketing\Support\ContactRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class UpsertContact
{
    public function __construct(
        private readonly ContactRules $rules,
        private readonly AutomationTriggers $triggers,
    ) {}

    /**
     * Create or update a contact by email, restoring it if archived. Only provided fields change:
     * custom fields are merged, and tags and lists are added, never removed.
     * An existing contact who unsubscribed stays unsubscribed unless `$allowResubscribe` is true, so an integration cannot quietly opt people back in.
     *
     * @return array{contact: Contact, created: bool}
     */
    public function handle(ContactData $data, ContactSource $source, bool $allowResubscribe = false): array
    {
        try {
            return $this->upsert($data, $source, $allowResubscribe);
        } catch (UniqueConstraintViolationException) {
            // Another request created the same email between our read and insert; it now exists, so update it.
            return $this->upsert($data, $source, $allowResubscribe);
        }
    }

    /**
     * @return array{contact: Contact, created: bool}
     */
    private function upsert(ContactData $data, ContactSource $source, bool $allowResubscribe): array
    {
        return DB::transaction(function () use ($data, $source, $allowResubscribe): array {
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

            $resubscribing = ! $created
                && $contact->status === ContactStatus::Unsubscribed
                && $data->status === ContactStatus::Subscribed;

            if ($data->status !== null && (! $resubscribing || $allowResubscribe)) {
                (new ChangeContactStatus)->apply($contact, $data->status);
            }

            $contact->save();

            if ($data->tags !== []) {
                $this->triggers->tagsAdded($contact, $contact->tags()->syncWithoutDetaching(self::resolveTagIds($data->tags))['attached']);
            }

            if ($data->lists !== []) {
                $this->triggers->listsJoined($contact, $contact->lists()->syncWithoutDetaching(self::listPivot($data->lists))['attached']);
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
            // Matched without regard to case on every database, so "VIP" joins an existing "vip" tag.
            $ids[] = (Tag::query()->whereRaw('lower(name) = ?', [$key])->first() ?? Tag::create(['name' => $name]))->id;
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
