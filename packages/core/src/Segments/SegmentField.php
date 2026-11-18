<?php

namespace Gerenuk\Marketing\Segments;

/**
 * A field a segment condition can filter on.
 */
final class SegmentField
{
    /**
     * @param  string  $key  e.g. `email`, `custom:plan`, `tag`, `list`
     * @param  string|null  $column  Database column or JSON path, when the field maps to one
     * @param  list<array{value: string|int, label: string}>  $options  Allowed values for choice, tag and list fields
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly FieldType $type,
        public readonly ?string $column = null,
        public readonly array $options = [],
    ) {}

    /**
     * @return array{key: string, label: string, type: string, operators: list<string>, options: list<array{value: string|int, label: string}>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'operators' => $this->type->operators(),
            'options' => $this->options,
        ];
    }
}
