<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Actions\Contacts\ChangeContactStatus;
use Gerenuk\Marketing\Actions\Contacts\UpdateContact;
use Gerenuk\Marketing\Actions\Contacts\UpsertContact;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Http\Requests\ContactRequest;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Models\Tag;
use Gerenuk\Marketing\Support\ContactData;
use Gerenuk\Marketing\Support\ContactFilter;
use Gerenuk\Marketing\Support\ContactTimeline;
use Gerenuk\Marketing\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    /** How many recent events the side panel shows before linking to the full profile. */
    private const PREVIEW_ACTIVITY = 8;

    public function index(Request $request, ContactFilter $filter, ContactTimeline $timeline): Response
    {
        $filters = $request->only(['search', 'status', 'list', 'tag', 'segment', 'archived']);

        $contacts = TableQuery::sort($filter->query($filters), $request, [
            'email' => 'email',
            'name' => 'first_name',
            'status' => 'status',
            'added' => 'created_at',
        ])
            ->with('tags:id,name')
            ->paginate(TableQuery::perPage($request, 50))
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
            'lists' => ContactList::query()->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'segments' => Segment::query()->orderBy('name')->get(['id', 'name']),
            'customFields' => CustomFieldDefinition::query()->orderBy('label')->get(['key', 'label', 'type']),
            'preview' => Inertia::optional(fn () => $this->preview($request->integer('preview'), $timeline)),
        ]);
    }

    /**
     * Contacts are added from a dialog on the list, which this opens.
     */
    public function create(): RedirectResponse
    {
        return to_route('marketing.contacts.index', ['add' => 1]);
    }

    public function store(ContactRequest $request, UpsertContact $upsertContact): RedirectResponse
    {
        ['contact' => $contact] = $upsertContact->handle(ContactData::fromValidated($request->validated()), ContactSource::Manual);

        if ($request->boolean('add_another')) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Added :email.', ['email' => $contact->email])]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact added.')]);

        return to_route('marketing.contacts.show', $contact);
    }

    public function show(Contact $contact, ContactTimeline $timeline): Response
    {
        $contact->load(['tags:id,name', 'lists:id']);

        return Inertia::render('marketing/contacts/show', [
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
            'engagement' => Inertia::defer(fn () => $this->engagement($contact)),
        ]);
    }

    public function update(ContactRequest $request, Contact $contact, UpdateContact $updateContact): RedirectResponse
    {
        $updateContact->handle($contact, ContactData::fromValidated($request->validated()), $request->user()->name);

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
     * Stops all marketing email to the contact straight away, and notes on their timeline who did it.
     */
    public function unsubscribe(Request $request, Contact $contact): RedirectResponse
    {
        if ($contact->status !== ContactStatus::Unsubscribed) {
            DB::transaction(function () use ($request, $contact): void {
                (new ChangeContactStatus)->apply($contact, ContactStatus::Unsubscribed);
                $contact->save();

                ChangeContactStatus::record($contact, ContactStatus::Unsubscribed, ['by' => $request->user()->name, 'via' => 'dashboard']);
            });
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unsubscribed :email.', ['email' => $contact->email])]);

        return back();
    }

    public function resubscribe(Request $request, Contact $contact): RedirectResponse
    {
        if ($contact->status !== ContactStatus::Subscribed) {
            DB::transaction(function () use ($request, $contact): void {
                (new ChangeContactStatus)->apply($contact, ContactStatus::Subscribed);
                $contact->save();

                ChangeContactStatus::record($contact, ContactStatus::Subscribed, ['by' => $request->user()->name, 'via' => 'dashboard']);
            });
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscribed :email again.', ['email' => $contact->email])]);

        return back();
    }

    /**
     * How many emails a contact has been sent, and how many of those they opened and clicked. Repeat opens of one email
     * count once; events with no campaign recipient count individually.
     *
     * @return array{received: int, opened: int, clicked: int}
     */
    private function engagement(Contact $contact): array
    {
        $counts = $contact->events()
            ->whereIn('type', [EventType::Sent, EventType::Opened, EventType::Clicked])
            ->selectRaw('type, count(distinct coalesce(campaign_recipient_id, -id)) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'received' => (int) ($counts[EventType::Sent->value] ?? 0),
            'opened' => (int) ($counts[EventType::Opened->value] ?? 0),
            'clicked' => (int) ($counts[EventType::Clicked->value] ?? 0),
        ];
    }

    /**
     * One contact's details and latest activity for the side panel on the contacts list, or null when it is not in this workspace.
     *
     * @return array<string, mixed>|null
     */
    private function preview(int $contactId, ContactTimeline $timeline): ?array
    {
        $contact = Contact::query()->with(['tags:id,name', 'lists:id,name'])->find($contactId);

        if ($contact === null) {
            return null;
        }

        $definitions = CustomFieldDefinition::query()->orderBy('label')->get(['key', 'label', 'type']);

        return [
            'id' => $contact->id,
            'email' => $contact->email,
            'name' => trim("{$contact->first_name} {$contact->last_name}") ?: null,
            'status' => $contact->status->value,
            'source' => $contact->source->value,
            'tags' => $contact->tags->pluck('name'),
            'lists' => $contact->lists->pluck('name'),
            'fields' => $definitions
                ->map(fn (CustomFieldDefinition $field) => ['label' => $field->label, 'type' => $field->type, 'value' => ($contact->custom_fields ?? [])[$field->key] ?? null])
                ->filter(fn (array $field) => $field['value'] !== null && $field['value'] !== '')
                ->values(),
            'unsubscribed_at' => $contact->unsubscribed_at?->toIso8601String(),
            'archived_at' => $contact->archived_at?->toIso8601String(),
            'created_at' => $contact->created_at->toIso8601String(),
            'activity' => $timeline->for($contact, self::PREVIEW_ACTIVITY),
        ];
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
