<?php

namespace Gerenuk\Marketing\Support;

use Gerenuk\Marketing\Enums\EventType;
use Gerenuk\Marketing\Models\ActivityEvent;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\Contact;

/**
 * A contact's recent activity in plain language, newest first.
 */
class ContactTimeline
{
    public const LIMIT = 100;

    /**
     * @return list<array{id: int, type: string, title: string, detail: string|null, campaign_id: int|null, occurred_at: string}>
     */
    public function for(Contact $contact, int $limit = self::LIMIT): array
    {
        $events = $contact->events()->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get();
        $campaigns = Campaign::query()->whereKey($events->pluck('campaign_id')->filter()->unique())->pluck('name', 'id');

        return array_values($events->map(function (ActivityEvent $event) use ($campaigns): array {
            $campaign = $event->campaign_id === null ? null : ($campaigns[$event->campaign_id] ?? __('a deleted campaign'));
            $data = $event->data ?? [];

            [$title, $detail] = match ($event->type) {
                EventType::Sent => [__('Sent :campaign', ['campaign' => $campaign]), isset($data['variant']) ? __('Variant :v', ['v' => strtoupper((string) $data['variant'])]) : null],
                EventType::Delivered => [__('Delivered :campaign', ['campaign' => $campaign]), null],
                EventType::Deferred => [__('Delivery delayed for :campaign', ['campaign' => $campaign]), $data['reason'] ?? null],
                EventType::Bounced => [__('Bounced :campaign', ['campaign' => $campaign]), $data['reason'] ?? null],
                EventType::Dropped => [__('Provider dropped :campaign', ['campaign' => $campaign]), $data['reason'] ?? null],
                EventType::Complained => [__('Marked :campaign as spam', ['campaign' => $campaign]), null],
                EventType::Opened => [__('Opened :campaign', ['campaign' => $campaign]), null],
                EventType::Clicked => [__('Clicked a link in :campaign', ['campaign' => $campaign]), ($data['url'] ?? null).(isset($data['suspected_bot']) ? ' '.__('(likely a security scanner)') : '')],
                EventType::Unsubscribed => [__('Unsubscribed'), $campaign === null ? null : __('From :campaign', ['campaign' => $campaign])],
                EventType::Custom => [__('Event: :name', ['name' => $data['name'] ?? '']), empty($data['properties']) ? null : json_encode($data['properties'], JSON_UNESCAPED_SLASHES)],
                EventType::AutomationEntered => [__('Started automation :name', ['name' => $data['automation'] ?? '']), null],
                EventType::AutomationCompleted => [__('Finished automation :name', ['name' => $data['automation'] ?? '']), null],
                EventType::AutomationExited => [__('Left automation :name', ['name' => $data['automation'] ?? '']), $data['reason'] ?? null],
            };

            return [
                'id' => $event->id,
                'type' => $event->type->value,
                'title' => (string) $title,
                'detail' => $detail === null || $detail === '' ? null : (string) $detail,
                'campaign_id' => $event->campaign_id,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ];
        })->all());
    }
}
