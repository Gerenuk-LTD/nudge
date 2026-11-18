<?php

namespace Gerenuk\Nudge\Http\Requests;

use Closure;
use Gerenuk\Nudge\Enums\AudienceType;
use Gerenuk\Nudge\Sending\MergeTags;
use Gerenuk\Nudge\Templates\EmailEditor;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A draft can be saved incomplete; completeness is checked by the pre-send checklist.
 */
class CampaignRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(CurrentWorkspace $currentWorkspace): array
    {
        $workspaceId = $currentWorkspace->id();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preheader' => ['sometimes', 'nullable', 'string', 'max:255'],
            'provider_id' => ['sometimes', 'nullable', 'integer', Rule::exists('providers', 'id')->where('workspace_id', $workspaceId)],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from_email' => ['sometimes', 'nullable', 'email:filter', 'max:254'],
            'reply_to' => ['sometimes', 'nullable', 'email:filter', 'max:254'],
            'editor' => ['sometimes', Rule::in(EmailEditor::KEYS)],
            'editor_state' => ['sometimes', 'nullable', 'array'],
            'html' => ['sometimes', 'nullable', 'string', 'max:1000000'],
            'text' => ['sometimes', 'nullable', 'string', 'max:500000'],
            'variables' => [
                'sometimes', 'nullable', 'array', 'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    foreach (array_keys((array) $value) as $key) {
                        if (strlen((string) $key) > 50) {
                            $fail(__('Variable names are at most 50 characters.'));

                            return;
                        }

                        if (! preg_match('/^[a-z][a-z0-9_]*$/', (string) $key) || in_array($key, MergeTags::BUILT_IN, true)) {
                            $fail(__('Variable names use lowercase letters, numbers and underscores.'));

                            return;
                        }
                    }
                },
            ],
            'variables.*' => ['nullable', 'string', 'max:1000'],
            'audience_type' => ['sometimes', Rule::enum(AudienceType::class)],
            'list_ids' => ['sometimes', 'array'],
            'list_ids.*' => ['integer', Rule::exists('contact_lists', 'id')->where('workspace_id', $workspaceId)],
            'segment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('segments', 'id')->where('workspace_id', $workspaceId)],
            'track_opens' => ['sometimes', 'boolean'],
            'track_clicks' => ['sometimes', 'boolean'],
            'ab_enabled' => ['sometimes', 'boolean'],
            'ab_subject_b' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ab_html_b' => ['sometimes', 'nullable', 'string', 'max:1000000'],
            'ab_editor_state_b' => ['sometimes', 'nullable', 'array'],
            'ab_sample_percent' => ['sometimes', 'integer', 'min:10', 'max:50'],
            'ab_wait_minutes' => ['sometimes', 'integer', 'min:30', 'max:10080'],
            'ab_metric' => ['sometimes', 'in:clicks,opens'],
        ];
    }
}
