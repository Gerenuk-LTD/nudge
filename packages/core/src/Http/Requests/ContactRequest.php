<?php

namespace Gerenuk\Marketing\Http\Requests;

use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Support\ContactRules;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(ContactRules $contactRules, CurrentWorkspace $currentWorkspace): array
    {
        $contact = $this->route('contact');
        $rules = $contactRules->rules();

        $rules['email'][] = Rule::unique('contacts', 'email')
            ->where('workspace_id', $currentWorkspace->id())
            ->ignore($contact instanceof Contact ? $contact->id : null);
        $rules['status'] = ['required', ...array_slice($rules['status'], 1)];

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Contact::normalizeEmail($this->input('email'))]);
        }
    }
}
