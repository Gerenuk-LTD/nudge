<?php

namespace Gerenuk\Nudge\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Gerenuk\Nudge\Actions\Contacts\UpsertContact;
use Gerenuk\Nudge\Automations\AutomationTriggers;
use Gerenuk\Nudge\Http\Controllers\Controller;
use Gerenuk\Nudge\Http\Resources\ContactListResource;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\ContactList;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Lists')]
class ContactListController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    /**
     * List the lists
     *
     * Every list in the workspace, by name, with the number of contacts on each, 100 per page by default. Follow `meta.next_cursor` (pass it as `cursor`) to read the next page.
     */
    #[QueryParameter('per_page', 'Lists per page, from 1 to 500. Defaults to 100.', type: 'integer', default: 100)]
    #[QueryParameter('cursor', 'The `meta.next_cursor` value from the previous page.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:500']])['per_page'] ?? 100;

        return ContactListResource::collection(
            ContactList::query()->withCount('contacts')->orderBy('name')->orderBy('id')->cursorPaginate($perPage)->withQueryString(),
        );
    }

    /**
     * Get a list
     */
    #[PathParameter('list', 'The ID of the list.', type: 'integer')]
    #[Response(404, 'No list with that ID.', type: 'array{code: string, message: string}')]
    public function show(ContactList $list): ContactListResource
    {
        return new ContactListResource($list->loadCount('contacts'));
    }

    /**
     * Create a list
     */
    #[BodyParameter('name', 'The list\'s name, at most 100 characters. Must be unique within the workspace.', type: 'string', required: true)]
    #[BodyParameter('description', 'At most 500 characters.', type: 'string', required: false)]
    #[Response(201, 'The list was created.', type: 'array{data: ContactListResource}')]
    #[Response(422, 'The body failed validation.', type: 'array{code: string, message: string, errors: array<string, list<string>>}')]
    public function store(Request $request): JsonResponse
    {
        $list = ContactList::create($this->validated($request));

        return (new ContactListResource($list->loadCount('contacts')))->response()->setStatusCode(201);
    }

    /**
     * Update a list
     *
     * Only the fields you send are changed.
     */
    #[PathParameter('list', 'The ID of the list.', type: 'integer')]
    #[BodyParameter('name', 'The list\'s name, at most 100 characters. Must be unique within the workspace.', type: 'string', required: false)]
    #[BodyParameter('description', 'At most 500 characters. Send null to clear it.', type: 'string', required: false)]
    #[Response(404, 'No list with that ID.', type: 'array{code: string, message: string}')]
    #[Response(422, 'The body failed validation.', type: 'array{code: string, message: string, errors: array<string, list<string>>}')]
    public function update(Request $request, ContactList $list): ContactListResource
    {
        $list->update($this->validated($request, $list));

        return new ContactListResource($list->loadCount('contacts'));
    }

    /**
     * Delete a list
     *
     * Deletes the list. Its contacts are not deleted.
     */
    #[PathParameter('list', 'The ID of the list.', type: 'integer')]
    #[Response(204, 'Deleted. No body.')]
    #[Response(404, 'No list with that ID.', type: 'array{code: string, message: string}')]
    public function destroy(ContactList $list): JsonResponse
    {
        $list->delete();

        return response()->json(null, 204);
    }

    /**
     * Add contacts to a list
     *
     * Add existing contacts to the list by email. Unknown emails are reported back, not created.
     */
    #[PathParameter('list', 'The ID of the list.', type: 'integer')]
    #[BodyParameter('emails', 'Email addresses of existing contacts, at most 1000.', type: 'string[]', required: true)]
    #[Response(200, '`added` counts the contacts found; `not_found` lists the emails that matched no contact.', type: 'array{data: array{added: int, not_found: list<string>}}')]
    #[Response(404, 'No list with that ID.', type: 'array{code: string, message: string}')]
    #[Response(422, 'The body failed validation.', type: 'array{code: string, message: string, errors: array<string, list<string>>}')]
    public function addContacts(Request $request, ContactList $list, AutomationTriggers $triggers): JsonResponse
    {
        ['found' => $found, 'missing' => $missing] = $this->contactsFromRequest($request);

        $attached = $list->contacts()->syncWithoutDetaching(UpsertContact::listPivot($found))['attached'];

        Contact::query()->whereKey($attached)->each(fn (Contact $contact) => $triggers->listsJoined($contact, [$list->id]));

        return response()->json(['data' => ['added' => count($found), 'not_found' => $missing]]);
    }

    /**
     * Remove contacts from a list
     *
     * Removes contacts from the list by email. The contacts themselves are not deleted, and unknown emails are reported back.
     */
    #[PathParameter('list', 'The ID of the list.', type: 'integer')]
    #[BodyParameter('emails', 'Email addresses of contacts to remove, at most 1000.', type: 'string[]', required: true)]
    #[Response(200, '`removed` counts the contacts taken off the list; `not_found` lists the emails that matched no contact.', type: 'array{data: array{removed: int, not_found: list<string>}}')]
    #[Response(404, 'No list with that ID.', type: 'array{code: string, message: string}')]
    #[Response(422, 'The body failed validation.', type: 'array{code: string, message: string, errors: array<string, list<string>>}')]
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
