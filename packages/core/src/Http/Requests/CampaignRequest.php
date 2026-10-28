<?php

namespace Gerenuk\Marketing\Http\Requests;

use Gerenuk\Marketing\Enums\AudienceType;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
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
            'html' => ['sometimes', 'nullable', 'string', 'max:1000000'],
            'text' => ['sometimes', 'nullable', 'string', 'max:500000'],
            'audience_type' => ['sometimes', Rule::enum(AudienceType::class)],
            'list_ids' => ['sometimes', 'array'],
            'list_ids.*' => ['integer', Rule::exists('contact_lists', 'id')->where('workspace_id', $workspaceId)],
            'segment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('segments', 'id')->where('workspace_id', $workspaceId)],
            'track_opens' => ['sometimes', 'boolean'],
            'track_clicks' => ['sometimes', 'boolean'],
        ];
    }
}
