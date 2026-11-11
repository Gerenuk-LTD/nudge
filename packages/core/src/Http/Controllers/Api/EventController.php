<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

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
use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Support\ContactData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    #[BodyParameter('properties', 'Free-form data about the event, such as an order total. Available to automations that this event triggers.', type: 'object', required: false)]
    #[BodyParameter('create_contact', 'Set to true to create the contact when no contact has this email. Otherwise an unknown email answers 404.', type: 'boolean', required: false)]
    #[HeaderParameter('Idempotency-Key', 'Send the same key when retrying. A repeat within the workspace returns the original response and records nothing new. Must be 1 to 255 visible ASCII characters.', required: false, type: 'string')]
    #[Response(202, 'Accepted. `email` is only null when a repeated request finds that its contact has since been deleted.', type: 'array{data: array{id: int, event: string, email: string|null}}')]
    #[Response(404, 'No contact with that email, and `create_contact` was not true.', type: 'array{message: string}')]
    #[Response(422, 'The body failed validation, or the `Idempotency-Key` header is not 1 to 255 visible ASCII characters (then `message` says so and `errors` is absent).', type: 'array{message: string, errors?: array<string, list<string>>}')]
    public function store(Request $request, UpsertContact $upsert, AutomationTriggers $triggers): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:filter', 'max:254'],
            'event' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'properties' => ['sometimes', 'array'],
            'create_contact' => ['sometimes', 'boolean'],
        ]);

        $key = $request->headers->has('Idempotency-Key') ? (string) $request->header('Idempotency-Key') : null;

        if ($key !== null && preg_match('/^[\x21-\x7E]{1,255}$/', $key) !== 1) {
            return response()->json(['message' => 'The Idempotency-Key header must be 1 to 255 visible ASCII characters.'], 422);
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
            return response()->json(['message' => 'No contact with that email. Send create_contact: true to create one.'], 404);
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

        return response()->json(['data' => ['id' => $event->id, 'event' => $validated['event'], 'email' => $contact->email]], 202);
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

        return response()->json(['data' => ['id' => $event->id, 'event' => $name, 'email' => $contact?->email]], 202);
    }

    /**
     * A contact's activity, newest first.
     *
     * Cursor-paginated, 100 events per page. Pass `next_cursor` as `cursor` to read the next page; it is null on the last page.
     */
    #[PathParameter('email', 'The contact\'s email address, URL-encoded. Matched case-insensitively: it is trimmed and lowercased before lookup.', type: 'string', format: 'email')]
    #[QueryParameter('cursor', 'The `next_cursor` value from the previous page.', type: 'string')]
    #[Response(200, 'The contact\'s events.', type: "array{data: list<array{type: 'sent'|'delivered'|'deferred'|'bounced'|'dropped'|'complained'|'opened'|'clicked'|'unsubscribed'|'resubscribed'|'paused'|'custom'|'automation_entered'|'automation_completed'|'automation_exited', data: array<string, mixed>, campaign_id: int|null, occurred_at: string}>, next_cursor: string|null}")]
    #[Response(404, 'No contact with that email.', type: 'array{message: string}')]
    public function index(Request $request, string $email): JsonResponse
    {
        $contact = Contact::query()->forEmail($email)->firstOrFail();

        $events = $contact->events()->orderByDesc('occurred_at')->orderByDesc('id')->cursorPaginate(100);

        return response()->json([
            'data' => collect($events->items())->map(fn (ActivityEvent $event) => [
                'type' => $event->type->value,
                'data' => (object) ($event->data ?? []),
                'campaign_id' => $event->campaign_id,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ]),
            'next_cursor' => $events->nextCursor()?->encode(),
        ]);
    }
}
