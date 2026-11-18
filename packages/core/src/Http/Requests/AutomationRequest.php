<?php

namespace Gerenuk\Nudge\Http\Requests;

use Gerenuk\Nudge\Enums\AutomationTrigger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The automation's settings. Its steps are checked by AutomationDefinitionValidator.
 */
class AutomationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'trigger_type' => ['required', Rule::enum(AutomationTrigger::class)],
            'trigger_config' => ['present', 'array'],
            'trigger_config.list_id' => ['nullable', 'integer'],
            'trigger_config.tag_id' => ['nullable', 'integer'],
            'trigger_config.segment_id' => ['nullable', 'integer'],
            'trigger_config.include_existing' => ['nullable', 'boolean'],
            'trigger_config.field_key' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'trigger_config.offset_days' => ['nullable', 'integer', 'min:-365', 'max:365'],
            'trigger_config.repeat' => ['nullable', 'in:yearly,once'],
            'trigger_config.days' => ['nullable', 'integer', 'min:7', 'max:3650'],
            'trigger_config.event' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'allow_reentry' => ['required', 'boolean'],
            'definition' => ['required', 'array'],
        ];
    }
}
