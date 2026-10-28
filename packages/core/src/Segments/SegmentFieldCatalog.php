<?php

namespace Gerenuk\Marketing\Segments;

use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\CustomFieldType;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\Tag;
use Illuminate\Support\Str;

/**
 * Every field segments can filter on in the current workspace.
 */
class SegmentFieldCatalog
{
    /** @var array<string, SegmentField>|null */
    private ?array $fields = null;

    /**
     * @return array<string, SegmentField>
     */
    public function all(): array
    {
        return $this->fields ??= $this->build();
    }

    public function find(string $key): ?SegmentField
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, SegmentField>
     */
    private function build(): array
    {
        $fields = [
            new SegmentField('email', 'Email', FieldType::Text, 'email'),
            new SegmentField('first_name', 'First name', FieldType::Text, 'first_name'),
            new SegmentField('last_name', 'Last name', FieldType::Text, 'last_name'),
            new SegmentField('status', 'Subscription status', FieldType::Choice, 'status', self::enumOptions(ContactStatus::cases())),
            new SegmentField('source', 'Signup source', FieldType::Choice, 'source', self::enumOptions(ContactSource::cases())),
            new SegmentField('created_at', 'Date added', FieldType::Date, 'created_at'),
            new SegmentField('tag', 'Tag', FieldType::Tag, null, array_values(Tag::query()->orderBy('name')->get()
                ->map(fn (Tag $tag) => ['value' => $tag->id, 'label' => $tag->name])->all())),
            new SegmentField('list', 'List', FieldType::ContactList, null, array_values(ContactList::query()->orderBy('name')->get()
                ->map(fn (ContactList $list) => ['value' => $list->id, 'label' => $list->name])->all())),
        ];

        foreach (CustomFieldDefinition::query()->orderBy('label')->get() as $definition) {
            $fields[] = new SegmentField(
                'custom:'.$definition->key,
                $definition->label,
                match ($definition->type) {
                    CustomFieldType::Text => FieldType::Text,
                    CustomFieldType::Number => FieldType::Number,
                    CustomFieldType::Date => FieldType::Date,
                    CustomFieldType::Boolean => FieldType::Boolean,
                },
                'custom_fields->'.$definition->key,
            );
        }

        return collect($fields)->keyBy('key')->all();
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return list<array{value: string, label: string}>
     */
    private static function enumOptions(array $cases): array
    {
        return array_map(fn (\BackedEnum $case) => ['value' => (string) $case->value, 'label' => Str::headline((string) $case->value)], $cases);
    }
}
