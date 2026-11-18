<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Closure;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Gerenuk\Marketing\Actions\Contacts\UpsertContact;
use Gerenuk\Marketing\Automations\AutomationTriggers;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Http\ApiError;
use Gerenuk\Marketing\Http\ApiErrorCode;
use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Http\Resources\EventResource;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Support\ContactData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Named events from a customer's app, such as "signed_up" or "order_placed". They appear on the
 * contact's timeline and can start automations.
 */
#[Group('Events')]
class EventController extends Controller
{
    /**
     * Record an event
     *
     * Records a named event on a contact's timeline and starts any automations triggered by it. The event is processed asynchronously, so this answers `202`.
     */
    #[BodyParameter('properties', 'Free-form data about the event, such as an order total. Available to automations that this event triggers. At most 50 top-level keys, nested at most 4 levels deep, and 16 KB as JSON.', type: 'object', required: false)]
    #[BodyParameter('create_contact', 'Set to true to create the contact when no contact has this email. Otherwise an unknown email answers 404.', type: 'boolean', required: false)]
    #[HeaderParameter('Idempotency-Key', 'Send the same key when retrying. A repeat within the workspace returns the original response and records nothing new. Must be 1 to 255 visible ASCII characters.', required: false, type: 'string')]
    #[Response(202, 'Accepted. `data` is the recorded event; a repeated request with the same `Idempotency-Key` answers with the original event.', type: 'array{data: EventResource}')]
    #[Response(404, 'No contact with that email, and `create_contact` was not true.', type: 'array{code: string, message: string}')]
    #[Response(422, 'The body failed validation (`properties` is limited to 50 top-level keys, 4 levels of nesting and 16 KB as JSON), or the `Idempotency-Key` header is not 1 to 255 visible ASCII characters (then `message` says so and `errors` is absent).', type: 'array{code: string, message: string, errors?: array<string, list<string>>}')]
    public function store(Request $request, UpsertContact $upsert, AutomationTriggers $triggers): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:filter', 'max:254'],
            'event' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'properties' => ['sometimes', 'array', 'max:50', function (string $attribute, mixed $value, Closure $fail): void {
                $json = json_encode($value, 0, 5);

                if ($json === false) {
                    $fail(__('Properties can be nested at most 4 levels deep.'));
                } elseif (strlen($json) > 16384) {
                    $fail(__('Properties can be at most 16 KB as JSON.'));
                }
            }],
            'create_contact' => ['sometimes', 'boolean'],
        ]);

        $key = $request->headers->has('Idempotency-Key') ? (string) $request->header('Idempotency-Key') : null;

        if ($key !== null && preg_match('/^[\x21-\x7E]{1,255}$/', $key) !== 1) {
            return ApiError::response(ApiErrorCode::InvalidIdempotencyKey, 'The Idempotency-Key header must be 1 to 255 visible ASCII characters.', 422);
        }

        // A repeat returns the stored event even if its body differs: the key wins.
        if ($key !== null && ($existing = ActivityEvent::query()->where('idempotency_key', $key)->first()) !== null) {
            return $this->replay($existing, $triggers);
        }

        $contact = Contact::query()->forEmail($validated['email'])->first();

        if ($contact === null && ($validated['create_contact'] ?? false)) {
            $contact = $upsert->handle(ContactData::fromValidated(['email' => Contact::normalizeEmail($validated['email'])]), ContactSource::Api)['contact'];
        }

        if ($contact === null) {
            return ApiError::response(ApiErrorCode::ContactNotFound, 'No contact with that email. Send create_contact: true to create one.', 404);
        }

        $properties = $validated['properties'] ?? [];

        try {
            $event = ActivityEvent::create([
                'contact_id' => $contact->id,
                'type' => EventType::Custom,
                'data' => ['name' => $validated['event'], 'properties' => $properties],
                'idempotency_key' => $key,
                'occurred_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key got there first; answer with its event.
            $existing = $key === null ? null : ActivityEvent::query()->where('idempotency_key', $key)->first();

            if ($existing === null) {
                throw $e;
            }

            return $this->replay($existing, $triggers);
        }

        $triggers->event($contact, $validated['event'], $properties, $event->id);

        return (new EventResource($event))->response()->setStatusCode(202);
    }

    /**
     * Answer a repeated request with the stored event. If its automations never started (the dispatch
     * failed after the row was written), dispatch again; a duplicate dispatch is a no-op once started.
     */
    private function replay(ActivityEvent $event, AutomationTriggers $triggers): JsonResponse
    {
        $contact = Contact::query()->find($event->contact_id);
        $name = (string) ($event->data['name'] ?? '');

        if ($event->triggered_at === null && $contact !== null) {
            $triggers->event($contact, $name, $event->data['properties'] ?? [], $event->id);
        }

        return (new EventResource($event))->response()->setStatusCode(202);
    }

    /**
     * A contact's activity, newest first.
     *
     * Cursor-paginated, 100 events per page by default. Pass `meta.next_cursor` as `cursor` to read the next page; it is null on the last page.
     */
    #[PathParameter('email', 'The contact\'s email address, URL-encoded. Matched case-insensitively: it is trimmed and lowercased before lookup.', type: 'string', format: 'email')]
    #[QueryParameter('per_page', 'Events per page, from 1 to 500. Defaults to 100.', type: 'integer', default: 100)]
    #[QueryParameter('cursor', 'The `meta.next_cursor` value from the previous page.', type: 'string')]
    #[Response(404, 'No contact with that email.', type: 'array{code: string, message: string}')]
    public function index(Request $request, string $email): AnonymousResourceCollection
    {
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:500']])['per_page'] ?? 100;

        $contact = Contact::query()->forEmail($email)->firstOrFail();

        return EventResource::collection(
            ActivityEvent::query()->where('contact_id', $contact->id)->orderByDesc('occurred_at')->orderByDesc('id')->cursorPaginate($perPage)->withQueryString(),
        );
    }
}
