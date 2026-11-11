<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Support\CustomFieldRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Custom fields')]
class CustomFieldController extends Controller
{
    /**
     * List custom fields
     *
     * The custom fields defined in the workspace. These keys are the ones accepted in a contact's `custom_fields`.
     */
    #[Response(200, 'Custom field definitions, ordered by key.', type: "array{data: list<array{key: string, label: string, type: 'text'|'number'|'date'|'boolean'}>}")]
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CustomFieldDefinition::query()->orderBy('key')->get(['key', 'label', 'type']),
        ]);
    }

    /**
     * Create a custom field
     *
     * Defines a new custom field. Its type cannot be changed afterwards through this API.
     */
    #[BodyParameter('key', 'Lowercase letters, digits and underscores, starting with a letter, at most 64 characters (`^[a-z][a-z0-9_]{0,63}$`). Must be unique within the workspace.', type: 'string', required: true)]
    #[BodyParameter('label', 'A display name, at most 100 characters.', type: 'string', required: true)]
    #[BodyParameter('type', 'The type values are validated and stored as.', type: "'text'|'number'|'date'|'boolean'", required: true)]
    #[Response(201, 'The custom field was created.', type: "array{data: array{key: string, label: string, type: 'text'|'number'|'date'|'boolean'}}")]
    public function store(Request $request, CustomFieldRules $rules): JsonResponse
    {
        $field = CustomFieldDefinition::create($request->validate($rules->forCreate()));

        return response()->json(['data' => $field->only(['key', 'label', 'type'])], 201);
    }
}
