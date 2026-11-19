<?php

namespace Gerenuk\Nudge\Support;

use Gerenuk\Nudge\Enums\CustomFieldType;
use Gerenuk\Nudge\Models\CustomFieldDefinition;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Validation\Rule;

class CustomFieldRules
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    /**
     * @return array<string, mixed>
     */
    public function forCreate(): array
    {
        return [
            'key' => [
                'required', 'string', 'regex:'.CustomFieldDefinition::KEY_PATTERN,
                Rule::unique('custom_field_definitions', 'key')->where('workspace_id', $this->currentWorkspace->id()),
            ],
            'label' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(CustomFieldType::class)],
        ];
    }
}
