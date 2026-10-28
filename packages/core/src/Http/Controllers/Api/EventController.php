<?php

namespace Gerenuk\Marketing\Http\Controllers\Api;

use Gerenuk\Marketing\Actions\Contacts\UpsertContact;
use Gerenuk\Marketing\Automations\AutomationTriggers;
use Gerenuk\Marketing\Enums\ContactSource;
use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Support\ContactData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Named events from a customer's app, such as "signed_up" or "order_placed". They appear on the
 * contact's timeline and can start automations.
 */
class EventController extends Controller
{
    public function store(Request $request, UpsertContact $upsert, AutomationTriggers $triggers): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:filter', 'max:254'],
            'event' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'properties' => ['sometimes', 'array'],
            'create_contact' => ['sometimes', 'boolean'],
        ]);

        $contact = Contact::query()->forEmail($validated['email'])->first();

        if ($contact === null && ($validated['create_contact'] ?? false)) {
            $contact = $upsert->handle(ContactData::fromValidated(['email' => Contact::normalizeEmail($validated['email'])]), ContactSource::Api)['contact'];
        }

        if ($contact === null) {
            return response()->json(['message' => 'No contact with that email. Send create_contact: true to create one.'], 404);
        }

        $properties = $validated['properties'] ?? [];

        $event = ActivityEvent::create([
            'contact_id' => $contact->id,
            'type' => EventType::Custom,
            'data' => ['name' => $validated['event'], 'properties' => $properties],
            'occurred_at' => now(),
        ]);

        $triggers->event($contact, $validated['event'], $properties);

        return response()->json(['data' => ['id' => $event->id, 'event' => $validated['event'], 'email' => $contact->email]], 202);
    }

    /**
     * A contact's activity, newest first.
     */
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
