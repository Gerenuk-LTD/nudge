<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Closure;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Gerenuk\Marketing\Actions\Contacts\UpsertContact;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Http\Resources\ContactResource;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Support\ContactData;
use Gerenuk\Marketing\Support\ContactFilter;
use Gerenuk\Marketing\Support\ContactRules;
use Gerenuk\Marketing\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;

/**
 * Contacts are addressed by email address, so callers never need internal IDs.
 */
#[Group('Contacts')]
class ContactController extends Controller
{
    /**
     * List contacts
     *
     * Contacts in ID order, 100 per page by default. Follow `meta.next_cursor` (pass it as `cursor`) to read the next page.
     */
    #[QueryParameter('status', 'Only contacts with this status: `subscribed` or `unsubscribed`.', type: 'string')]
    #[QueryParameter('list', 'Only contacts on the list with this ID.', type: 'integer')]
    #[QueryParameter('tag', 'Only contacts with the tag with this ID.', type: 'integer')]
    #[QueryParameter('segment', 'Only contacts in the segment with this ID. An unknown ID answers 404.', type: 'integer')]
    #[QueryParameter('archived', 'When true, list only archived contacts. By default only active contacts are listed.', type: 'boolean')]
    #[QueryParameter('search', 'Matches against email address, first name and last name.', type: 'string')]
    #[QueryParameter('per_page', 'Contacts per page, from 1 to 500. Defaults to 100.', type: 'integer', default: 100)]
    #[QueryParameter('cursor', 'The `meta.next_cursor` value from the previous page.', type: 'string')]
    #[Response(404, 'The `segment` filter names a segment that does not exist.', type: 'array{code: string, message: string}')]
    #[Response(422, 'A filter failed validation, such as an unknown `status`.', type: 'array{code: string, message: string, errors: array<string, list<string>>}')]
    public function index(Request $request, ContactFilter $filter): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                foreach (TableQuery::values($value) as $status) {
                    if (ContactStatus::tryFrom($status) === null) {
                        $fail('The status must be subscribed or unsubscribed, or a comma-separated list of them.');

                        return;
                    }
                }
            }],
            'list' => ['nullable', 'integer'],
            'tag' => ['nullable', 'integer'],
            'segment' => ['nullable', 'integer'],
            'archived' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $contacts = $filter->query($filters)
            ->with(['tags:id,name', 'lists:id'])
            ->orderBy('id')
            ->cursorPaginate($filters['per_page'] ?? 100)
            ->withQueryString();

        return ContactResource::collection($contacts);
    }

    /**
     * Get a contact
     */
    #[PathParameter('email', 'The contact\'s email address, URL-encoded. Matched case-insensitively: it is trimmed and lowercased before lookup.', type: 'string', format: 'email')]
    #[Response(404, 'No contact with that email.', type: 'array{code: string, message: string}')]
    public function show(string $email): ContactResource
    {
        return new ContactResource($this->find($email)->load(['tags:id,name', 'lists:id']));
    }

    /**
     * Create or update a contact
     *
     * Create or update a contact. Custom fields are merged; tags and lists are added, never removed.
     * Someone who unsubscribed is only subscribed again when `resubscribe` is true.
     *
     * Answers `201` with the new contact when this call created it, and `200` with the updated contact when it already existed.
     */
    #[PathParameter('email', 'The contact\'s email address, URL-encoded. Matched case-insensitively: it is trimmed and lowercased before lookup.', type: 'string', format: 'email')]
    #[Response(200, 'The contact already existed and was updated.', type: 'array{data: ContactResource}')]
    #[Response(201, 'The contact was created.', type: 'array{data: ContactResource}')]
    #[Response(422, 'The body failed validation.', type: 'array{code: string, message: string, errors: array<string, list<string>>}')]
    #[BodyParameter('first_name', type: 'string', required: false)]
    #[BodyParameter('last_name', type: 'string', required: false)]
    #[BodyParameter('status', 'Whether the contact is subscribed. See `resubscribe` for turning an unsubscribed contact back on.', type: 'string', required: false)]
    #[BodyParameter('custom_fields', 'Values keyed by custom field key. The accepted keys are defined per workspace; list them with `GET /custom-fields`. Unknown keys are rejected. Merged into the contact\'s existing values.', type: 'object', required: false)]
    #[BodyParameter('tags', 'Tag names to add, at most 50, each at most 100 characters. Tags are never removed by this call.', type: 'string[]', required: false)]
    #[BodyParameter('lists', 'IDs of lists to add the contact to. Lists are never removed by this call, and each ID must belong to this workspace.', type: 'integer[]', required: false)]
    #[BodyParameter('resubscribe', 'Set to true to subscribe someone who previously unsubscribed. Without it their status is left as it is.', type: 'boolean', required: false)]
    public function upsert(Request $request, string $email, ContactRules $contactRules, UpsertContact $upsertContact): JsonResponse
    {
        $data = ['email' => Contact::normalizeEmail($email), ...$request->except('email')];
        $validated = Validator::make($data, [...$contactRules->rules(), 'resubscribe' => ['sometimes', 'boolean']])->validate();

        ['contact' => $contact, 'created' => $created] = $upsertContact->handle(
            ContactData::fromValidated($validated),
            ContactSource::Api,
            (bool) ($validated['resubscribe'] ?? false),
            ['token_id' => $request->attributes->get('marketing_api_token')?->id],
        );

        return (new ContactResource($contact->load(['tags:id,name', 'lists:id'])))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    /**
     * Archive a contact
     *
     * Archives the contact (it is not deleted) and answers `204` with no body.
     */
    #[PathParameter('email', 'The contact\'s email address, URL-encoded. Matched case-insensitively: it is trimmed and lowercased before lookup.', type: 'string', format: 'email')]
    #[Response(204, 'Archived. No body.')]
    #[Response(404, 'No contact with that email.', type: 'array{code: string, message: string}')]
    public function archive(string $email): JsonResponse
    {
        $this->find($email)->update(['archived_at' => now()]);

        return response()->json(null, 204);
    }

    private function find(string $email): Contact
    {
        return Contact::query()->forEmail($email)->firstOrFail();
    }
}
