<?php

namespace Gerenuk\Nudge\Http\Requests;

use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Support\ContactRules;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('Someone with this email is already a contact.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Contact::normalizeEmail($this->input('email'))]);
        }
    }
}
