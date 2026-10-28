<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Actions\Contacts\UpdateContact;
use Gerenuk\Marketing\Actions\Contacts\UpsertContact;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Http\Requests\ContactRequest;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Models\Tag;
use Gerenuk\Marketing\Support\ContactData;
use Gerenuk\Marketing\Support\ContactFilter;
use Gerenuk\Marketing\Support\ContactTimeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(Request $request, ContactFilter $filter): Response
    {
        $filters = $request->only(['search', 'status', 'list', 'tag', 'segment', 'archived']);

        $contacts = $filter->query($filters)
            ->with('tags:id,name')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Contact $contact) => [
                'id' => $contact->id,
                'email' => $contact->email,
                'name' => trim("{$contact->first_name} {$contact->last_name}") ?: null,
                'status' => $contact->status->value,
                'tags' => $contact->tags->pluck('name'),
                'created_at' => $contact->created_at->toIso8601String(),
            ]);

        return Inertia::render('marketing/contacts/index', [
            'contacts' => $contacts,
            'filters' => $filters,
            'lists' => ContactList::query()->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'segments' => Segment::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('marketing/contacts/form', [
            'contact' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(ContactRequest $request, UpsertContact $upsertContact): RedirectResponse
    {
        ['contact' => $contact] = $upsertContact->handle(ContactData::fromValidated($request->validated()), ContactSource::Manual);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact added.')]);

        return to_route('marketing.contacts.show', $contact);
    }

    public function show(Contact $contact, ContactTimeline $timeline): Response
    {
        $contact->load(['tags:id,name', 'lists:id']);

        return Inertia::render('marketing/contacts/form', [
            'contact' => [
                'id' => $contact->id,
                'email' => $contact->email,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'status' => $contact->status->value,
                'source' => $contact->source->value,
                'custom_fields' => (object) ($contact->custom_fields ?? []),
                'tags' => $contact->tags->pluck('name'),
                'lists' => $contact->lists->pluck('id'),
                'unsubscribed_at' => $contact->unsubscribed_at?->toIso8601String(),
                'archived_at' => $contact->archived_at?->toIso8601String(),
                'created_at' => $contact->created_at->toIso8601String(),
            ],
            ...$this->formOptions(),
            'timeline' => Inertia::defer(fn () => $timeline->for($contact)),
        ]);
    }

    public function update(ContactRequest $request, Contact $contact, UpdateContact $updateContact): RedirectResponse
    {
        $updateContact->handle($contact, ContactData::fromValidated($request->validated()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact saved.')]);

        return to_route('marketing.contacts.show', $contact);
    }

    public function archive(Contact $contact): RedirectResponse
    {
        $contact->update(['archived_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact archived.')]);

        return to_route('marketing.contacts.index');
    }

    public function restore(Contact $contact): RedirectResponse
    {
        $contact->update(['archived_at' => null]);

        return to_route('marketing.contacts.show', $contact);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'statuses' => array_column(ContactStatus::cases(), 'value'),
            'lists' => ContactList::query()->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::query()->orderBy('name')->pluck('name'),
            'customFields' => CustomFieldDefinition::query()->orderBy('label')->get(['key', 'label', 'type']),
        ];
    }
}
