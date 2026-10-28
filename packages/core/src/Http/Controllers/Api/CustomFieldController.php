<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Support\CustomFieldRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomFieldController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CustomFieldDefinition::query()->orderBy('key')->get(['key', 'label', 'type']),
        ]);
    }

    public function store(Request $request, CustomFieldRules $rules): JsonResponse
    {
        $field = CustomFieldDefinition::create($request->validate($rules->forCreate()));

        return response()->json(['data' => $field->only(['key', 'label', 'type'])], 201);
    }
}
