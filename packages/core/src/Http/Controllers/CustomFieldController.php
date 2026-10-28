<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Enums\CustomFieldType;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Support\CustomFieldRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomFieldController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('marketing/custom-fields/index', [
            'fields' => CustomFieldDefinition::query()->orderBy('label')->get(['id', 'key', 'label', 'type']),
            'types' => array_column(CustomFieldType::cases(), 'value'),
        ]);
    }

    public function store(Request $request, CustomFieldRules $rules): RedirectResponse
    {
        CustomFieldDefinition::create($request->validate($rules->forCreate()));

        return to_route('marketing.custom-fields.index');
    }

    /**
     * Only the label can change; the key and type are fixed because stored values and segments depend on them.
     */
    public function update(Request $request, CustomFieldDefinition $customField): RedirectResponse
    {
        $customField->update($request->validate(['label' => ['required', 'string', 'max:100']]));

        return to_route('marketing.custom-fields.index');
    }

    /**
     * Deleting a definition hides its values; they stay in each contact's data until overwritten.
     */
    public function destroy(CustomFieldDefinition $customField): RedirectResponse
    {
        $customField->delete();

        return to_route('marketing.custom-fields.index');
    }
}
