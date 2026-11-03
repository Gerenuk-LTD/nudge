<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Gerenuk\Marketing\Actions\Contacts\UpsertContact;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Http\Resources\ContactResource;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Support\ContactData;
use Gerenuk\Marketing\Support\ContactFilter;
use Gerenuk\Marketing\Support\ContactRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;

/**
 * Contacts are addressed by email address, so callers never need internal IDs.
 */
class ContactController extends Controller
{
    public function index(Request $request, ContactFilter $filter): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string'],
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

    public function show(string $email): ContactResource
    {
        return new ContactResource($this->find($email)->load(['tags:id,name', 'lists:id']));
    }

    /**
     * Create or update a contact. Custom fields are merged; tags and lists are added, never removed.
     * Someone who unsubscribed is only subscribed again when `resubscribe` is true.
     */
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
