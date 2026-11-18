<?php

namespace Gerenuk\Nudge\Actions\Contacts;

use Carbon\CarbonInterface;
use Gerenuk\Nudge\Automations\AutomationTriggers;
use Gerenuk\Nudge\Enums\ContactSource;
use Gerenuk\Nudge\Enums\ContactStatus;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\Tag;
use Gerenuk\Nudge\Support\ContactData;
use Gerenuk\Nudge\Support\ContactRules;
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
     * A status change to an existing contact is recorded on their timeline with the source and `$context`.
     *
     * @param  array<string, mixed>  $context  extra detail for that record, such as the API token's ID
     * @return array{contact: Contact, created: bool}
     */
    public function handle(ContactData $data, ContactSource $source, bool $allowResubscribe = false, array $context = []): array
    {
        try {
            return $this->upsert($data, $source, $allowResubscribe, $context);
        } catch (UniqueConstraintViolationException) {
            // Another request created the same email between our read and insert; it now exists, so update it.
            return $this->upsert($data, $source, $allowResubscribe, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{contact: Contact, created: bool}
     */
    private function upsert(ContactData $data, ContactSource $source, bool $allowResubscribe, array $context): array
    {
        return DB::transaction(function () use ($data, $source, $allowResubscribe, $context): array {
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

            $before = $contact->status;

            if ($data->status !== null && (! $resubscribing || $allowResubscribe)) {
                (new ChangeContactStatus)->apply($contact, $data->status);
            }

            $contact->save();

            // A new contact's creation is its own record; only changes to existing ones are noted.
            if (! $created && $before !== $contact->status) {
                ChangeContactStatus::record($contact, $contact->status, ['via' => $source->value, ...$context]);
            }

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
