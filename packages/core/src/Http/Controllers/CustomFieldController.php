<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Enums\CustomFieldType;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Support\CustomFieldReferences;
use Gerenuk\Marketing\Support\CustomFieldRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomFieldController extends Controller
{
    public function index(CustomFieldReferences $references): Response
    {
        $fields = CustomFieldDefinition::query()->orderBy('label')->get(['id', 'key', 'label', 'type']);
        $usage = $references->usage($fields->pluck('key')->all());

        return Inertia::render('marketing/custom-fields/index', [
            'fields' => $fields->map(fn (CustomFieldDefinition $field) => [
                ...$field->only(['id', 'key', 'label', 'type']),
                'filled_count' => Contact::query()->active()->whereNotNull('custom_fields->'.$field->key)->count(),
                'used_by' => $usage[$field->key],
            ]),
            'types' => array_column(CustomFieldType::cases(), 'value'),
            'activeContacts' => Contact::query()->active()->count(),
        ]);
    }

    public function store(Request $request, CustomFieldRules $rules): RedirectResponse
    {
        CustomFieldDefinition::create($request->validate($rules->forCreate(), [
            'key.unique' => __('Another field already uses this key.'),
            'key.regex' => __('Use lowercase letters, numbers and underscores, starting with a letter.'),
        ]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Field added.')]);

        return to_route('marketing.custom-fields.index');
    }

    /**
     * Only the label can change; the key and type are fixed because stored values and segments depend on them.
     */
    public function update(Request $request, CustomFieldDefinition $customField): RedirectResponse
    {
        $customField->update($request->validate(['label' => ['required', 'string', 'max:100']]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Field renamed.')]);

        return to_route('marketing.custom-fields.index');
    }

    /**
     * Deleting a definition hides its values; they stay in each contact's data until overwritten.
     */
    public function destroy(CustomFieldDefinition $customField): RedirectResponse
    {
        $customField->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Field deleted.')]);

        return to_route('marketing.custom-fields.index');
    }
}
