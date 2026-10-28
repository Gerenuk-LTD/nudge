<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Gerenuk\Marketing\Actions\Contacts\UpsertContact;
use Gerenuk\Marketing\Automations\AutomationTriggers;
use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Http\Resources\ContactListResource;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ContactListController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(): AnonymousResourceCollection
    {
        return ContactListResource::collection(ContactList::query()->withCount('contacts')->orderBy('name')->get());
    }

    public function show(ContactList $list): ContactListResource
    {
        return new ContactListResource($list->loadCount('contacts'));
    }

    public function store(Request $request): JsonResponse
    {
        $list = ContactList::create($this->validated($request));

        return (new ContactListResource($list))->response()->setStatusCode(201);
    }

    public function update(Request $request, ContactList $list): ContactListResource
    {
        $list->update($this->validated($request, $list));

        return new ContactListResource($list);
    }

    public function destroy(ContactList $list): JsonResponse
    {
        $list->delete();

        return response()->json(null, 204);
    }

    /**
     * Add existing contacts to the list by email. Unknown emails are reported back, not created.
     */
    public function addContacts(Request $request, ContactList $list, AutomationTriggers $triggers): JsonResponse
    {
        ['found' => $found, 'missing' => $missing] = $this->contactsFromRequest($request);

        $attached = $list->contacts()->syncWithoutDetaching(UpsertContact::listPivot($found))['attached'];

        Contact::query()->whereKey($attached)->each(fn (Contact $contact) => $triggers->listsJoined($contact, [$list->id]));

        return response()->json(['data' => ['added' => count($found), 'not_found' => $missing]]);
    }

    public function removeContacts(Request $request, ContactList $list): JsonResponse
    {
        ['found' => $found, 'missing' => $missing] = $this->contactsFromRequest($request);

        $removed = $list->contacts()->detach($found);

        return response()->json(['data' => ['removed' => $removed, 'not_found' => $missing]]);
    }

    /**
     * Split the requested emails into contact IDs that exist and emails that do not.
     *
     * @return array{found: list<int>, missing: list<string>}
     */
    private function contactsFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'emails' => ['required', 'array', 'max:1000'],
            'emails.*' => ['string', 'email:filter'],
        ]);

        $emails = [];

        foreach ($validated['emails'] as $email) {
            $emails[Contact::normalizeEmail((string) $email)] = true;
        }

        $found = [];

        foreach (Contact::query()->whereIn('email', array_keys($emails))->get(['id', 'email']) as $contact) {
            $found[] = $contact->id;
            unset($emails[$contact->email]);
        }

        return ['found' => $found, 'missing' => array_map(strval(...), array_keys($emails))];
    }

    /**
     * @return array{name: string, description?: string|null}
     */
    private function validated(Request $request, ?ContactList $list = null): array
    {
        return $request->validate([
            'name' => [
                $list ? 'sometimes' : 'required', 'string', 'max:100',
                Rule::unique('contact_lists', 'name')->where('workspace_id', $this->currentWorkspace->id())->ignore($list?->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
