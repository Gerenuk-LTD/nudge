<?php

namespace Gerenuk\Marketing\Imports;

use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What each CSV column maps to: `email`, `first_name`, `last_name`, `status`, `tags`, `custom:<key>` or null.
 */
class ColumnMapping
{
    public const BUILT_IN_TARGETS = ['email', 'first_name', 'last_name', 'status', 'tags'];

    private const UNSUBSCRIBED_VALUES = ['unsubscribed', 'unsubscribe', 'unsub', 'opted out', 'opted_out', 'opt-out', 'optout'];

    /**
     * @param  Collection<int, CustomFieldDefinition>  $customFields
     * @return list<string>
     */
    public static function targets(Collection $customFields): array
    {
        return [
            ...self::BUILT_IN_TARGETS,
            ...$customFields->map(fn (CustomFieldDefinition $field) => 'custom:'.$field->key)->all(),
        ];
    }

    /**
     * Guess a target for each header from its name.
     *
     * @param  list<string>  $headers
     * @param  Collection<int, CustomFieldDefinition>  $customFields
     * @return list<string|null>
     */
    public static function suggest(array $headers, Collection $customFields): array
    {
        $aliases = [
            'email' => ['email', 'email_address', 'e_mail', 'mail'],
            'first_name' => ['first_name', 'firstname', 'first', 'given_name', 'forename'],
            'last_name' => ['last_name', 'lastname', 'last', 'surname', 'family_name'],
            'status' => ['status', 'subscription_status', 'subscribed'],
            'tags' => ['tags', 'tag', 'labels'],
        ];

        foreach ($customFields as $field) {
            $aliases['custom:'.$field->key] = [$field->key, Str::snake($field->label)];
        }

        $used = [];

        return array_map(function (string $header) use ($aliases, &$used): ?string {
            $normalized = Str::snake(Str::lower(trim($header)));

            foreach ($aliases as $target => $names) {
                if (! in_array($target, $used, true) && in_array($normalized, $names, true)) {
                    $used[] = $target;

                    return $target;
                }
            }

            return null;
        }, $headers);
    }

    /**
     * Turn one CSV row into contact data for ContactRules validation.
     *
     * Imports only ever unsubscribe: a status column cannot re-subscribe someone who opted out.
     *
     * @param  array<int, string|null>  $mapping
     * @param  list<string|null>  $row
     * @param  Collection<string, CustomFieldDefinition>  $customFieldsByKey
     * @return array<string, mixed>
     */
    public static function toContactData(array $mapping, array $row, Collection $customFieldsByKey): array
    {
        $data = [];
        $customFields = [];

        foreach ($mapping as $index => $target) {
            $value = isset($row[$index]) ? trim((string) $row[$index]) : '';

            if ($target === null) {
                continue;
            }

            if ($target === 'tags') {
                $data['tags'] = array_values(array_filter(array_map('trim', preg_split('/[,;|]/', $value) ?: [])));
            } elseif ($target === 'status') {
                if (in_array(Str::lower($value), self::UNSUBSCRIBED_VALUES, true)) {
                    $data['status'] = ContactStatus::Unsubscribed->value;
                }
            } elseif (str_starts_with($target, 'custom:')) {
                $key = Str::after($target, 'custom:');
                $customFields[$key] = self::normalizeCustomValue($customFieldsByKey->get($key), $value);
            } elseif ($value !== '' || $target === 'email') {
                $data[$target] = $value;
            }
        }

        if ($customFields !== []) {
            $data['custom_fields'] = $customFields;
        }

        return $data;
    }

    private static function normalizeCustomValue(?CustomFieldDefinition $field, string $value): mixed
    {
        if ($value === '') {
            return null;
        }

        if ($field?->type->value === 'boolean') {
            return match (Str::lower($value)) {
                'yes', 'y', 'true', '1' => true,
                'no', 'n', 'false', '0' => false,
                default => $value,
            };
        }

        return $value;
    }
}
