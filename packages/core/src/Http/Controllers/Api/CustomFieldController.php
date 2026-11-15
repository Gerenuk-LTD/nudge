<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Http\Resources\CustomFieldResource;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Support\CustomFieldRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Custom fields')]
class CustomFieldController extends Controller
{
    /**
     * List custom fields
     *
     * The custom fields defined in the workspace, ordered by key, 100 per page by default. These keys are the ones accepted in a contact's `custom_fields`. Follow `meta.next_cursor` (pass it as `cursor`) to read the next page.
     */
    #[QueryParameter('per_page', 'Custom fields per page, from 1 to 500. Defaults to 100.', type: 'integer', default: 100)]
    #[QueryParameter('cursor', 'The `meta.next_cursor` value from the previous page.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:500']])['per_page'] ?? 100;

        return CustomFieldResource::collection(
            CustomFieldDefinition::query()->orderBy('key')->cursorPaginate($perPage)->withQueryString(),
        );
    }

    /**
     * Create a custom field
     *
     * Defines a new custom field. Its type cannot be changed afterwards through this API.
     */
    #[BodyParameter('key', 'Lowercase letters, digits and underscores, starting with a letter, at most 64 characters (`^[a-z][a-z0-9_]{0,63}$`). Must be unique within the workspace.', type: 'string', required: true)]
    #[BodyParameter('label', 'A display name, at most 100 characters.', type: 'string', required: true)]
    #[BodyParameter('type', 'The type values are validated and stored as.', type: "'text'|'number'|'date'|'boolean'", required: true)]
    #[Response(201, 'The custom field was created.', type: 'array{data: CustomFieldResource}')]
    #[Response(422, 'The body failed validation.', type: 'array{code: string, message: string, errors: array<string, list<string>>}')]
    public function store(Request $request, CustomFieldRules $rules): JsonResponse
    {
        $field = CustomFieldDefinition::create($request->validate($rules->forCreate()));

        return (new CustomFieldResource($field))->response()->setStatusCode(201);
    }
}
