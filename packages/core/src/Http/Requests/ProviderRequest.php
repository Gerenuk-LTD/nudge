<?php

namespace Gerenuk\Marketing\Http\Requests;

use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Sending\ProviderType;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(CurrentWorkspace $currentWorkspace): array
    {
        $provider = $this->route('provider');
        $existing = $provider instanceof Provider ? $provider : null;
        $type = $existing === null ? ProviderType::tryFrom((string) $this->input('type')) : $existing->type;

        $rules = [
            'name' => ['required', 'string', 'max:100', Rule::unique('providers', 'name')->where('workspace_id', $currentWorkspace->id())->ignore($existing?->id)],
            'type' => [$existing ? 'prohibited' : 'required', Rule::enum(ProviderType::class)],
            'credentials' => ['required', 'array'],
            'from_name' => ['nullable', 'string', 'max:100'],
            'from_email' => ['nullable', 'email:filter', 'max:254'],
            'reply_to' => ['nullable', 'email:filter', 'max:254'],
            'max_per_second' => ['required', 'integer', 'min:1', 'max:1000'],
            'daily_limit' => ['nullable', 'integer', 'min:1'],
            'webhook_secret' => ['nullable', 'string', 'max:2000'],
        ];

        foreach ($type?->fields() ?? [] as $field) {
            // Saved secrets are not sent back to the browser, so blank means "keep".
            $required = $field->required && ! ($existing && $field->secret);

            $rules['credentials.'.$field->key] = [
                $required ? 'required' : 'nullable',
                'string',
                'max:1000',
                ...($field->options === [] ? [] : [Rule::in(array_column($field->options, 'value'))]),
            ];
        }

        return $rules;
    }
}
