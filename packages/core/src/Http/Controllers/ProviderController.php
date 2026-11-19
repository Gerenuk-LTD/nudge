<?php

namespace Gerenuk\Nudge\Http\Controllers;

use Gerenuk\Nudge\Actions\Providers\SaveProvider;
use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Http\Requests\ProviderRequest;
use Gerenuk\Nudge\Models\ActivityEvent;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Sending\CredentialField;
use Gerenuk\Nudge\Sending\ProviderTransports;
use Gerenuk\Nudge\Sending\ProviderType;
use Gerenuk\Nudge\Sending\WebhookVerification;
use Gerenuk\Nudge\Support\ProviderData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mime\Email;
use Throwable;

class ProviderController extends Controller
{
    public function index(): Response
    {
        // Sends and bounces over the last week, per provider, for the cards.
        $recent = ActivityEvent::query()
            ->join('campaign_recipients', 'campaign_recipients.id', '=', 'events.campaign_recipient_id')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
            ->where('events.occurred_at', '>=', now()->subDays(7))
            ->whereIn('events.type', [EventType::Sent, EventType::Bounced])
            ->toBase()
            ->selectRaw('campaigns.provider_id, events.type, count(*) as total')
            ->groupBy('campaigns.provider_id', 'events.type')
            ->get()
            ->mapWithKeys(fn (object $row) => ["{$row->provider_id}:{$row->type}" => (int) $row->total]);

        $count = fn (int $providerId, EventType $type): int => $recent["{$providerId}:{$type->value}"] ?? 0;

        return Inertia::render('nudge/providers/index', [
            'providers' => Provider::query()->orderBy('name')->get()->map(fn (Provider $provider) => [
                'sent_7d' => $count($provider->id, EventType::Sent),
                'bounced_7d' => $count($provider->id, EventType::Bounced),
                'id' => $provider->id,
                'name' => $provider->name,
                'type' => $provider->type->label(),
                'from_email' => $provider->from_email,
                'last_sent_at' => $provider->last_sent_at?->toIso8601String(),
                'last_webhook_at' => $provider->last_webhook_at?->toIso8601String(),
                'receives_webhooks' => $provider->type->webhookVerification() !== WebhookVerification::None,
                'max_per_second' => $provider->max_per_second,
                'daily_limit' => $provider->daily_limit,
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('nudge/providers/form', [
            'provider' => null,
            'types' => array_map(fn (ProviderType $type) => $type->toArray(), ProviderType::cases()),
        ]);
    }

    public function store(ProviderRequest $request, SaveProvider $saveProvider): RedirectResponse
    {
        $provider = $saveProvider->handle(ProviderData::fromValidated($request->validated()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Provider connected. Set up its webhook next.')]);

        return to_route('nudge.providers.show', $provider);
    }

    public function show(Provider $provider): Response
    {
        $since = now()->subDays(7);
        $counts = ActivityEvent::query()
            ->whereIn('campaign_recipient_id', fn ($query) => $query->select('campaign_recipients.id')->from('campaign_recipients')
                ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
                ->where('campaigns.provider_id', $provider->id))
            ->where('occurred_at', '>=', $since)
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return Inertia::render('nudge/providers/show', [
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->name,
                'type' => $provider->type->toArray(),
                'from_name' => $provider->from_name,
                'from_email' => $provider->from_email,
                'max_per_second' => $provider->max_per_second,
                'daily_limit' => $provider->daily_limit,
                'last_sent_at' => $provider->last_sent_at?->toIso8601String(),
                'last_webhook_at' => $provider->last_webhook_at?->toIso8601String(),
                'webhook_url' => $this->webhookUrl($provider),
                'has_webhook_secret' => filled($provider->webhook_secret),
            ],
            'stats' => [
                'sent' => (int) ($counts[EventType::Sent->value] ?? 0),
                'delivered' => (int) ($counts[EventType::Delivered->value] ?? 0),
                'bounced' => (int) ($counts[EventType::Bounced->value] ?? 0),
                'complained' => (int) ($counts[EventType::Complained->value] ?? 0),
            ],
        ]);
    }

    public function edit(Provider $provider): Response
    {
        return Inertia::render('nudge/providers/form', [
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->name,
                'type' => $provider->type->value,
                // Secrets never go back to the browser.
                'credentials' => collect($provider->type->fields())
                    ->mapWithKeys(fn (CredentialField $field) => [$field->key => $field->secret ? '' : ($provider->credentials[$field->key] ?? '')]),
                'from_name' => $provider->from_name,
                'from_email' => $provider->from_email,
                'reply_to' => $provider->reply_to,
                'max_per_second' => $provider->max_per_second,
                'daily_limit' => $provider->daily_limit,
                'has_webhook_secret' => filled($provider->webhook_secret),
            ],
            'types' => [$provider->type->toArray()],
        ]);
    }

    public function update(ProviderRequest $request, Provider $provider, SaveProvider $saveProvider): RedirectResponse
    {
        $saveProvider->handle(ProviderData::fromValidated($request->validated()), $provider);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Provider saved.')]);

        return to_route('nudge.providers.show', $provider);
    }

    public function destroy(Provider $provider): RedirectResponse
    {
        if (Campaign::query()->where('provider_id', $provider->id)->where('status', CampaignStatus::Sending)->exists()) {
            throw ValidationException::withMessages(['provider' => __('A campaign is sending through this provider. Wait for it to finish.')]);
        }

        $provider->delete();

        return to_route('nudge.providers.index');
    }

    /**
     * Send a plain test message through the saved credentials.
     */
    public function test(Request $request, Provider $provider, ProviderTransports $transports): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'email:filter'],
            'from' => ['required', 'email:filter'],
        ]);

        try {
            $transports->for($provider)->send((new Email)
                ->from($validated['from'])
                ->to($validated['to'])
                ->subject(__('Test from :name', ['name' => $provider->name]))
                ->text(__('This test message was sent through :provider. If you can read it, sending works.', ['provider' => $provider->type->label()])));
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['to' => __('Sending failed: :message', ['message' => $exception->getMessage()])]);
        }

        $provider->forceFill(['last_sent_at' => now()])->saveQuietly();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test sent. Check the inbox for :email.', ['email' => $validated['to']])]);

        return to_route('nudge.providers.show', $provider);
    }

    /**
     * Basic-auth providers get the credentials we generated embedded in the URL.
     */
    private function webhookUrl(Provider $provider): ?string
    {
        if ($provider->type->webhookVerification() === WebhookVerification::None) {
            return null;
        }

        $base = rtrim((string) (config('nudge.tracking_url') ?: config('app.url')), '/');
        $url = $base.route('nudge.webhooks.provider', $provider->webhook_token, absolute: false);

        if ($provider->type->webhookVerification() === WebhookVerification::BasicAuth && filled($provider->webhook_secret)) {
            [$scheme, $rest] = explode('://', $url, 2);
            [$user, $password] = explode(':', (string) $provider->webhook_secret, 2);

            return $scheme.'://'.rawurlencode($user).':'.rawurlencode($password).'@'.$rest;
        }

        return $url;
    }
}
