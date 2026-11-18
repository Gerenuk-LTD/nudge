<?php

namespace Gerenuk\Nudge\Support;

use Gerenuk\Nudge\Models\CustomFieldDefinition;

/**
 * The merge tags an email editor offers to insert: the built-in ones, then one per custom field.
 */
class MergeTagOptions
{
    /**
     * @return list<array{tag: string, label: string}>
     */
    public static function all(): array
    {
        $tags = [
            ['tag' => '{{ first_name }}', 'label' => __('First name')],
            ['tag' => '{{ last_name }}', 'label' => __('Last name')],
            ['tag' => '{{ email }}', 'label' => __('Email')],
            ['tag' => '{{ unsubscribe_url }}', 'label' => __('Unsubscribe link')],
            ['tag' => '{{ postal_address }}', 'label' => __('Postal address')],
        ];

        foreach (CustomFieldDefinition::query()->orderBy('label')->get() as $field) {
            $tags[] = ['tag' => "{{ custom.{$field->key} }}", 'label' => $field->label];
        }

        return $tags;
    }
}
