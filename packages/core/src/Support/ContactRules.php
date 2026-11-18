<?php

namespace Gerenuk\Marketing\Support;

use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\CustomFieldType;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Validation rules for contact data, shared by the dashboard, the API and CSV imports.
 */
class ContactRules
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    /** @var Collection<int, CustomFieldDefinition>|null */
    private ?Collection $definitions = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(bool $emailRequired = true): array
    {
        $definitions = $this->definitions();

        $rules = [
            'email' => [$emailRequired ? 'required' : 'sometimes', 'string', 'email:filter', 'max:254'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(ContactStatus::class)],
            'custom_fields' => ['sometimes', 'nullable', 'array:'.$definitions->pluck('key')->implode(',')],
            'tags' => ['sometimes', 'array', 'max:50'],
            'tags.*' => ['string', 'max:100'],
            'lists' => ['sometimes', 'array'],
            'lists.*' => ['integer', Rule::exists('contact_lists', 'id')->where('workspace_id', $this->workspaceId())],
        ];

        foreach ($definitions as $definition) {
            $rules['custom_fields.'.$definition->key] = match ($definition->type) {
                CustomFieldType::Text => ['nullable', 'string', 'max:1000'],
                CustomFieldType::Number => ['nullable', 'numeric'],
                CustomFieldType::Date => ['nullable', 'date_format:Y-m-d'],
                CustomFieldType::Boolean => ['nullable', 'boolean'],
            };
        }

        return $rules;
    }

    /**
     * Convert validated custom field values to their declared types.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function castCustomFields(array $values): array
    {
        $types = $this->definitions()->pluck('type', 'key');

        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                $values[$key] = null;

                continue;
            }

            $values[$key] = match ($types[$key] ?? null) {
                CustomFieldType::Number => $value + 0,
                CustomFieldType::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => (string) $value,
            };
        }

        return $values;
    }

    /**
     * @return Collection<int, CustomFieldDefinition>
     */
    public function definitions(): Collection
    {
        return $this->definitions ??= CustomFieldDefinition::query()->orderBy('key')->get();
    }

    private function workspaceId(): int
    {
        return $this->currentWorkspace->getOrFail()->id;
    }
}
